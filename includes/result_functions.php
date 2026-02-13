<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';

/**
 * 月次概算実績の更新処理（データ保存用）
 */
function updateMonthlyResult(array $data, PDO $dbh)
{
    if (empty($data['monthly_result_id'])) {
        if (empty($data['year']) || empty($data['month'])) {
            throw new Exception("更新対象のID、または年月の指定がありません。");
        }
    }

    $monthly_result_id = $data['monthly_result_id'];

    // IDがない場合（新規保存時など）、年月からIDを取得または作成
    if (!$monthly_result_id) {
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_result WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $monthly_result_id = $stmtCheck->fetchColumn();

        if (!$monthly_result_id) {
            $stmtCreate = $dbh->prepare("INSERT INTO monthly_result (year, month, status, created_at, updated_at) VALUES (?, ?, 'draft', NOW(), NOW())");
            $stmtCreate->execute([$data['year'], $data['month']]);
            $monthly_result_id = $dbh->lastInsertId();
        }
    }

    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];

    // ----------------------------
    // 0. 排他制御 & ステータスチェック
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status, hourly_rate, updated_at FROM monthly_result WHERE id = ? FOR UPDATE");
    $stmtStatusCheck->execute([$monthly_result_id]);
    $currentData = $stmtStatusCheck->fetch(PDO::FETCH_ASSOC);

    if (!$currentData) {
        throw new Exception("対象のデータが見つかりません。");
    }

    // 親がFixedなら更新不可（管理者がUnlockした場合を除く）
    if (($currentData['status'] ?? '') === 'fixed') {
        throw new Exception("この月は全体確定済みのため、修正できません。");
    }

    // 営業所個別のステータスチェック (Fixedなら復元/修正不可)
    $targetOfficeId = $data['target_office_id'] ?? null;
    if ($targetOfficeId) {
        $stmtChildStatus = $dbh->prepare("SELECT status FROM monthly_result_time WHERE monthly_result_id = ? AND office_id = ?");
        $stmtChildStatus->execute([$monthly_result_id, $targetOfficeId]);
        $childStatus = $stmtChildStatus->fetchColumn();

        // 復元や更新を行おうとした対象営業所が既にFixedならエラーにする
        if ($childStatus === 'fixed') {
            throw new Exception("この営業所の概算実績データは確定済みのため、復元・修正できません。");
        }
    }

    // タイムスタンプ比較（排他制御）
    $inputUpdatedAt = $data['updated_at'] ?? '';
    $dbUpdatedAt    = $currentData['updated_at'];

    // JS側でupdated_atが空で送られてくる初回保存時などはスキップ
    if ($inputUpdatedAt !== '' && $dbUpdatedAt && $inputUpdatedAt != $dbUpdatedAt) {
        throw new Exception("他のユーザーによってデータが更新されました。画面をリロードしてください。");
    }

    // ----------------------------
    // 1. 親テーブル (monthly_result) の賃率更新
    // ----------------------------
    // 全社共通賃率
    $hourly_rate = $currentData['hourly_rate'];
    if (isset($data['hourly_rate']) && $data['hourly_rate'] !== '') {
        $hourly_rate = (float)$data['hourly_rate'];
    }

    $stmtParent = $dbh->prepare("UPDATE monthly_result SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
    $stmtParent->execute([$hourly_rate, $monthly_result_id]);

    // ----------------------------
    // 2. 営業所別時間データ (monthly_result_time)
    // ※ 外部キー: monthly_result_id
    // ----------------------------
    $stmtCheckTime  = $dbh->prepare("SELECT id FROM monthly_result_time WHERE monthly_result_id = ? AND office_id = ?");
    $stmtUpdateTime = $dbh->prepare("UPDATE monthly_result_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW() WHERE id = ?");
    $stmtInsertTime = $dbh->prepare("INSERT INTO monthly_result_time (monthly_result_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())");

    foreach ($officeTimeData as $office_id => $time) {
        $office_id = (int)$office_id;
        if ($office_id <= 0) continue;

        $standard = (float)($time['standard_hours'] ?? 0);
        $overtime = (float)($time['overtime_hours'] ?? 0);
        $transfer = (float)($time['transferred_hours'] ?? 0);
        $full = (int)($time['fulltime_count'] ?? 0);
        $contract = (int)($time['contract_count'] ?? 0);
        $dispatch = (int)($time['dispatch_count'] ?? 0);

        $stmtCheckTime->execute([$monthly_result_id, $office_id]);
        $existingId = $stmtCheckTime->fetchColumn();

        if ($existingId) {
            $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
        } else {
            // 新規作成時は draft
            $stmtInsertTime->execute([$monthly_result_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
        }
    }

    // ----------------------------
    // 3. 経費明細 (monthly_result_details)
    // ※ 外部キー: result_id
    // ----------------------------
    $stmtCheckDetail  = $dbh->prepare("SELECT id FROM monthly_result_details WHERE result_id = ? AND detail_id = ?");
    $stmtUpdateDetail = $dbh->prepare("UPDATE monthly_result_details SET amount = ? WHERE id = ?");
    $stmtInsertDetail = $dbh->prepare("INSERT INTO monthly_result_details (result_id, detail_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteDetail = $dbh->prepare("DELETE FROM monthly_result_details WHERE result_id = ? AND detail_id = ?");

    if (!empty($amounts)) {
        foreach ($amounts as $detail_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $detail_id = (int)$detail_id;

            $stmtCheckDetail->execute([$monthly_result_id, $detail_id]);
            $existingId = $stmtCheckDetail->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateDetail->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertDetail->execute([$monthly_result_id, $detail_id, $amountValue]);
                }
            } elseif ($existingId) {
                // 0円なら削除してゴミを残さない
                $stmtDeleteDetail->execute([$monthly_result_id, $detail_id]);
            }
        }
    }

    // ----------------------------
    // 4. 収入明細 (monthly_result_revenues)
    // ※ 外部キー: result_id
    // ----------------------------
    $stmtCheckRev  = $dbh->prepare("SELECT id FROM monthly_result_revenues WHERE result_id = ? AND revenue_item_id = ?");
    $stmtUpdateRev = $dbh->prepare("UPDATE monthly_result_revenues SET amount = ? WHERE id = ?");
    $stmtInsertRev = $dbh->prepare("INSERT INTO monthly_result_revenues (result_id, revenue_item_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteRev = $dbh->prepare("DELETE FROM monthly_result_revenues WHERE result_id = ? AND revenue_item_id = ?");

    if (!empty($revenues)) {
        foreach ($revenues as $revenue_item_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $revenue_item_id = (int)$revenue_item_id;

            $stmtCheckRev->execute([$monthly_result_id, $revenue_item_id]);
            $existingId = $stmtCheckRev->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateRev->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertRev->execute([$monthly_result_id, $revenue_item_id, $amountValue]);
                }
            } elseif ($existingId) {
                $stmtDeleteRev->execute([$monthly_result_id, $revenue_item_id]);
            }
        }
    }

    $logData = $data;
    if (!empty($data['target_office_id'])) {
        $logData['office_id'] = $data['target_office_id'];
    }

    // ログ記録
    logAudit($dbh, 'result', $monthly_result_id, 'update', $logData);
}

function fixParentResult(int $monthly_result_id, PDO $dbh)
{
    // 1. ステータスを Fixed に
    $stmt = $dbh->prepare("UPDATE monthly_result SET status = 'fixed', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$monthly_result_id]);

    // 子データも全て Fixed に強制統一
    $stmtChild = $dbh->prepare("UPDATE monthly_result_time SET status = 'fixed' WHERE monthly_result_id = ?");
    $stmtChild->execute([$monthly_result_id]);

    // ログ記録
    logAudit($dbh, 'result', $monthly_result_id, 'parent_fixed', ['msg' => 'Admin changed parent status to fixed']);
}

function unlockParentResult(int $monthly_result_id, PDO $dbh)
{
    // ステータスを draft に戻す
    $stmt = $dbh->prepare("UPDATE monthly_result SET status = 'draft', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$monthly_result_id]);

    // ログ記録
    logAudit($dbh, 'result', $monthly_result_id, 'parent_unlock', ['msg' => 'Admin unlocked result']);
}

function rejectMonthlyResult(int $monthly_result_id, int $target_office_id, PDO $dbh)
{
    // 1. 親が fixed なら draft に戻す (ロック解除)
    $stmtHead = $dbh->prepare("UPDATE monthly_result SET status = 'draft', updated_at = NOW() WHERE id = ? AND status = 'fixed'");
    $stmtHead->execute([$monthly_result_id]);

    // 2. 指定された営業所の子データを draft に戻す
    $stmtChild = $dbh->prepare("UPDATE monthly_result_time SET status = 'draft', updated_at = NOW() WHERE monthly_result_id = ? AND office_id = ?");
    $stmtChild->execute([$monthly_result_id, $target_office_id]);

    // 3. ログ記録 (office_id を記録)
    $logContent = [
        'office_id' => $target_office_id,
        'msg'       => 'Admin rejected data to draft'
    ];
    logAudit($dbh, 'result', $monthly_result_id, 'reject', $logContent);
}

function confirmMonthlyResult(array $data, PDO $dbh)
{
    // 1. まずデータを保存/更新
    updateMonthlyResult($data, $dbh);

    // 2. IDの解決
    $monthly_result_id = $data['monthly_result_id'] ?? null;
    if (empty($monthly_result_id)) {
        // updateMonthlyResultで作成された可能性があるため再取得
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_result WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $monthly_result_id = $stmtCheck->fetchColumn();
    }

    if (!$monthly_result_id) {
        throw new Exception("対象の概算実績データが存在しません。");
    }

    $targetOfficeId = $data['target_office_id'] ?? null;
    if (!$targetOfficeId) {
        throw new Exception("確定対象の営業所が指定されていません。");
    }

    // 3. ステータスを Fixed に更新
    $stmtFix = $dbh->prepare("UPDATE monthly_result_time SET status = 'fixed', updated_at = NOW() WHERE monthly_result_id = ? AND office_id = ?");
    $stmtFix->execute([$monthly_result_id, $targetOfficeId]);

    // 4. ログ記録
    logAudit($dbh, 'result', $monthly_result_id, 'fix', ['office_id' => $targetOfficeId]);
}
