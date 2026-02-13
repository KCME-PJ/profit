<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';

/**
 * 月次見通しの更新処理（データ保存用）
 */
function updateMonthlyOutlook(array $data, PDO $dbh)
{
    if (empty($data['monthly_outlook_id'])) {
        if (empty($data['year']) || empty($data['month'])) {
            throw new Exception("更新対象のID、または年月の指定がありません。");
        }
    }

    $monthly_outlook_id = $data['monthly_outlook_id'];

    // IDがない場合（新規保存時など）、年月からIDを取得または作成
    if (!$monthly_outlook_id) {
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_outlook WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $monthly_outlook_id = $stmtCheck->fetchColumn();

        if (!$monthly_outlook_id) {
            $stmtCreate = $dbh->prepare("INSERT INTO monthly_outlook (year, month, status, created_at, updated_at) VALUES (?, ?, 'draft', NOW(), NOW())");
            $stmtCreate->execute([$data['year'], $data['month']]);
            $monthly_outlook_id = $dbh->lastInsertId();
        }
    }

    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];

    // ----------------------------
    // 0. 排他制御 & ステータスチェック
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status, hourly_rate, updated_at FROM monthly_outlook WHERE id = ? FOR UPDATE");
    $stmtStatusCheck->execute([$monthly_outlook_id]);
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
        $stmtChildStatus = $dbh->prepare("SELECT status FROM monthly_outlook_time WHERE monthly_outlook_id = ? AND office_id = ?");
        $stmtChildStatus->execute([$monthly_outlook_id, $targetOfficeId]);
        $childStatus = $stmtChildStatus->fetchColumn();

        // 復元や更新を行おうとした対象営業所が既にFixedならエラーにする
        if ($childStatus === 'fixed') {
            throw new Exception("この営業所の月末見込みデータは確定済みのため、復元・修正できません。");
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
    // 1. 親テーブル (monthly_outlook) の賃率更新
    // ----------------------------
    $hourly_rate = $currentData['hourly_rate'];
    if (isset($data['hourly_rate']) && $data['hourly_rate'] !== '') {
        $hourly_rate = (float)$data['hourly_rate'];
    }

    $stmtParent = $dbh->prepare("UPDATE monthly_outlook SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
    $stmtParent->execute([$hourly_rate, $monthly_outlook_id]);

    // ----------------------------
    // 2. 営業所別時間データ (monthly_outlook_time)
    // ※ 外部キー: monthly_outlook_id
    // ----------------------------
    $stmtCheckTime  = $dbh->prepare("SELECT id FROM monthly_outlook_time WHERE monthly_outlook_id = ? AND office_id = ?");
    $stmtUpdateTime = $dbh->prepare("UPDATE monthly_outlook_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW() WHERE id = ?");
    $stmtInsertTime = $dbh->prepare("INSERT INTO monthly_outlook_time (monthly_outlook_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())");

    foreach ($officeTimeData as $office_id => $time) {
        $office_id = (int)$office_id;
        if ($office_id <= 0) continue;

        $standard = (float)($time['standard_hours'] ?? 0);
        $overtime = (float)($time['overtime_hours'] ?? 0);
        $transfer = (float)($time['transferred_hours'] ?? 0);
        $full = (int)($time['fulltime_count'] ?? 0);
        $contract = (int)($time['contract_count'] ?? 0);
        $dispatch = (int)($time['dispatch_count'] ?? 0);

        $stmtCheckTime->execute([$monthly_outlook_id, $office_id]);
        $existingId = $stmtCheckTime->fetchColumn();

        if ($existingId) {
            $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
        } else {
            $stmtInsertTime->execute([$monthly_outlook_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
        }
    }

    // ----------------------------
    // 3. 経費明細 (monthly_outlook_details)
    // ※ 外部キー: outlook_id (Forecastとは異なる)
    // ----------------------------
    $stmtCheckDetail  = $dbh->prepare("SELECT id FROM monthly_outlook_details WHERE outlook_id = ? AND detail_id = ?");
    $stmtUpdateDetail = $dbh->prepare("UPDATE monthly_outlook_details SET amount = ? WHERE id = ?");
    $stmtInsertDetail = $dbh->prepare("INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteDetail = $dbh->prepare("DELETE FROM monthly_outlook_details WHERE outlook_id = ? AND detail_id = ?");

    if (!empty($amounts)) {
        foreach ($amounts as $detail_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $detail_id = (int)$detail_id;

            $stmtCheckDetail->execute([$monthly_outlook_id, $detail_id]);
            $existingId = $stmtCheckDetail->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateDetail->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertDetail->execute([$monthly_outlook_id, $detail_id, $amountValue]);
                }
            } elseif ($existingId) {
                $stmtDeleteDetail->execute([$monthly_outlook_id, $detail_id]);
            }
        }
    }

    // ----------------------------
    // 4. 収入明細 (monthly_outlook_revenues)
    // ※ 外部キー: outlook_id
    // ----------------------------
    $stmtCheckRev  = $dbh->prepare("SELECT id FROM monthly_outlook_revenues WHERE outlook_id = ? AND revenue_item_id = ?");
    $stmtUpdateRev = $dbh->prepare("UPDATE monthly_outlook_revenues SET amount = ? WHERE id = ?");
    $stmtInsertRev = $dbh->prepare("INSERT INTO monthly_outlook_revenues (outlook_id, revenue_item_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteRev = $dbh->prepare("DELETE FROM monthly_outlook_revenues WHERE outlook_id = ? AND revenue_item_id = ?");

    if (!empty($revenues)) {
        foreach ($revenues as $revenue_item_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $revenue_item_id = (int)$revenue_item_id;

            $stmtCheckRev->execute([$monthly_outlook_id, $revenue_item_id]);
            $existingId = $stmtCheckRev->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateRev->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertRev->execute([$monthly_outlook_id, $revenue_item_id, $amountValue]);
                }
            } elseif ($existingId) {
                $stmtDeleteRev->execute([$monthly_outlook_id, $revenue_item_id]);
            }
        }
    }

    $logData = $data;
    if (!empty($data['target_office_id'])) {
        $logData['office_id'] = $data['target_office_id'];
    }

    logAudit($dbh, 'outlook', $monthly_outlook_id, 'update', $logData);
}

function fixParentOutlook(int $monthly_outlook_id, PDO $dbh)
{
    $stmt = $dbh->prepare("UPDATE monthly_outlook SET status = 'fixed', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$monthly_outlook_id]);

    $stmtChild = $dbh->prepare("UPDATE monthly_outlook_time SET status = 'fixed' WHERE monthly_outlook_id = ?");
    $stmtChild->execute([$monthly_outlook_id]);

    // 次工程(Result: 概算実績)へ反映
    reflectToResult($monthly_outlook_id, $dbh);

    logAudit($dbh, 'outlook', $monthly_outlook_id, 'parent_fixed', ['msg' => 'Admin changed parent status to fixed']);
}

function unlockParentOutlook(int $monthly_outlook_id, PDO $dbh)
{
    $stmt = $dbh->prepare("UPDATE monthly_outlook SET status = 'draft', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$monthly_outlook_id]);

    logAudit($dbh, 'outlook', $monthly_outlook_id, 'parent_unlock', ['msg' => 'Admin unlocked outlook']);
}

function rejectMonthlyOutlook(int $monthly_outlook_id, int $target_office_id, PDO $dbh)
{
    $stmtHead = $dbh->prepare("UPDATE monthly_outlook SET status = 'draft', updated_at = NOW() WHERE id = ? AND status = 'fixed'");
    $stmtHead->execute([$monthly_outlook_id]);

    $stmtChild = $dbh->prepare("UPDATE monthly_outlook_time SET status = 'draft', updated_at = NOW() WHERE monthly_outlook_id = ? AND office_id = ?");
    $stmtChild->execute([$monthly_outlook_id, $target_office_id]);

    $logContent = [
        'office_id' => $target_office_id,
        'msg'       => 'Admin rejected data to draft'
    ];
    logAudit($dbh, 'outlook', $monthly_outlook_id, 'reject', $logContent);
}

function confirmMonthlyOutlook(array $data, PDO $dbh)
{
    updateMonthlyOutlook($data, $dbh);
    $monthly_outlook_id = $data['monthly_outlook_id'] ?? null;
    if (empty($monthly_outlook_id)) {
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_outlook WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $monthly_outlook_id = $stmtCheck->fetchColumn();
    }

    if (!$monthly_outlook_id) {
        throw new Exception("対象の月末見込みデータが存在しません。");
    }

    $targetOfficeId = $data['target_office_id'] ?? null;
    if (!$targetOfficeId) {
        throw new Exception("確定対象の営業所が指定されていません。");
    }

    $stmtFix = $dbh->prepare("UPDATE monthly_outlook_time SET status = 'fixed', updated_at = NOW() WHERE monthly_outlook_id = ? AND office_id = ?");
    $stmtFix->execute([$monthly_outlook_id, $targetOfficeId]);

    logAudit($dbh, 'outlook', $monthly_outlook_id, 'fix', ['office_id' => $targetOfficeId]);
}

// ----------------------------------------------------
// 洗い替え反映: Outlook -> Result
// ----------------------------------------------------
function reflectToResult(int $monthly_outlook_id, PDO $dbh)
{
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_outlook WHERE id = ?");
    $stmt->execute([$monthly_outlook_id]);
    $ol = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ol) {
        throw new Exception("参照元の月末見込みデータが見つかりません。");
    }
    $year = $ol['year'];
    $month = $ol['month'];
    $rate = $ol['hourly_rate'];

    // Resultの親レコードを探す (なければ作る、あればID取得)
    $stmtRes = $dbh->prepare("SELECT id FROM monthly_result WHERE year = ? AND month = ?");
    $stmtRes->execute([$year, $month]);
    $resultId = $stmtRes->fetchColumn();

    if ($resultId) {
        // 洗い替え: 既存データをクリア
        $dbh->prepare("DELETE FROM monthly_result_time WHERE monthly_result_id = ?")->execute([$resultId]);
        $dbh->prepare("DELETE FROM monthly_result_details WHERE result_id = ?")->execute([$resultId]);
        $dbh->prepare("DELETE FROM monthly_result_revenues WHERE result_id = ?")->execute([$resultId]);

        // 親データの更新
        $dbh->prepare("UPDATE monthly_result SET hourly_rate = ?, updated_at = NOW() WHERE id = ?")->execute([$rate, $resultId]);
    } else {
        // 新規作成
        $stmtIns = $dbh->prepare("INSERT INTO monthly_result (year, month, hourly_rate, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', NOW(), NOW())");
        $stmtIns->execute([$year, $month, $rate]);
        $resultId = $dbh->lastInsertId();
    }

    // 1. Time
    $sqlTime = "
        INSERT INTO monthly_result_time 
        (monthly_result_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at)
        SELECT 
            ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, 'draft', NOW(), NOW()
        FROM monthly_outlook_time 
        WHERE monthly_outlook_id = ?
    ";
    $stmtTime = $dbh->prepare($sqlTime);
    $stmtTime->execute([$resultId, $monthly_outlook_id]);

    // 2. Details
    $sqlDet = "
        INSERT INTO monthly_result_details (result_id, detail_id, amount, created_at, updated_at)
        SELECT ?, detail_id, amount, NOW(), NOW()
        FROM monthly_outlook_details
        WHERE outlook_id = ?
    ";
    $stmtDet = $dbh->prepare($sqlDet);
    $stmtDet->execute([$resultId, $monthly_outlook_id]);

    // 3. Revenues
    $sqlRev = "
        INSERT INTO monthly_result_revenues (result_id, revenue_item_id, amount, created_at, updated_at)
        SELECT ?, revenue_item_id, amount, NOW(), NOW()
        FROM monthly_outlook_revenues
        WHERE outlook_id = ?
    ";
    $stmtRev = $dbh->prepare($sqlRev);
    $stmtRev->execute([$resultId, $monthly_outlook_id]);
}
