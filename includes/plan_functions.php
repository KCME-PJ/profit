<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';

/**
 * 月次予定の更新処理（データ保存用）
 */
function updateMonthlyPlan(array $data, PDO $dbh)
{
    // IDも年月もない場合はエラー
    if (empty($data['plan_id'])) {
        if (empty($data['year']) || empty($data['month'])) {
            throw new Exception("更新対象のID、または年月の指定がありません。");
        }
    }

    $plan_id = $data['plan_id'];

    // IDがない場合（新規保存時など）、年月からIDを取得または作成
    if (!$plan_id) {
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_plan WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $plan_id = $stmtCheck->fetchColumn();

        if (!$plan_id) {
            $stmtCreate = $dbh->prepare("INSERT INTO monthly_plan (year, month, status, created_at, updated_at) VALUES (?, ?, 'draft', NOW(), NOW())");
            $stmtCreate->execute([$data['year'], $data['month']]);
            $plan_id = $dbh->lastInsertId();
        }
    }

    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];

    // ----------------------------
    // 0. 排他制御 & ステータスチェック
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status, hourly_rate, updated_at FROM monthly_plan WHERE id = ? FOR UPDATE");
    $stmtStatusCheck->execute([$plan_id]);
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
        $stmtChildStatus = $dbh->prepare("SELECT status FROM monthly_plan_time WHERE monthly_plan_id = ? AND office_id = ?");
        $stmtChildStatus->execute([$plan_id, $targetOfficeId]);
        $childStatus = $stmtChildStatus->fetchColumn();

        // 復元や更新を行おうとした対象営業所が既にFixedならエラーにする
        if ($childStatus === 'fixed') {
            throw new Exception("この営業所の予定データは確定済みのため、復元・修正できません。");
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
    // 1. 親テーブル (monthly_plan) の賃率更新
    // ----------------------------
    // 全社共通賃率
    $hourly_rate = $currentData['hourly_rate'];
    if (isset($data['hourly_rate']) && $data['hourly_rate'] !== '') {
        $hourly_rate = (float)$data['hourly_rate'];
    }

    $stmtParent = $dbh->prepare("UPDATE monthly_plan SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
    $stmtParent->execute([$hourly_rate, $plan_id]);

    // ----------------------------
    // 2. 営業所別時間データ (monthly_plan_time)
    // ----------------------------
    $stmtCheckTime  = $dbh->prepare("SELECT id FROM monthly_plan_time WHERE monthly_plan_id = ? AND office_id = ?");
    $stmtUpdateTime = $dbh->prepare("UPDATE monthly_plan_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW() WHERE id = ?");
    $stmtInsertTime = $dbh->prepare("INSERT INTO monthly_plan_time (monthly_plan_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())");

    foreach ($officeTimeData as $office_id => $time) {
        $office_id = (int)$office_id;
        if ($office_id <= 0) continue;

        $standard = (float)($time['standard_hours'] ?? 0);
        $overtime = (float)($time['overtime_hours'] ?? 0);
        $transfer = (float)($time['transferred_hours'] ?? 0);
        $full = (int)($time['fulltime_count'] ?? 0);
        $contract = (int)($time['contract_count'] ?? 0);
        $dispatch = (int)($time['dispatch_count'] ?? 0);

        $stmtCheckTime->execute([$plan_id, $office_id]);
        $existingId = $stmtCheckTime->fetchColumn();

        if ($existingId) {
            $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
        } else {
            // 新規作成時は draft
            $stmtInsertTime->execute([$plan_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
        }
    }

    // ----------------------------
    // 3. 経費明細 (monthly_plan_details)
    // ----------------------------
    $stmtCheckDetail  = $dbh->prepare("SELECT id FROM monthly_plan_details WHERE plan_id = ? AND detail_id = ?");
    $stmtUpdateDetail = $dbh->prepare("UPDATE monthly_plan_details SET amount = ? WHERE id = ?");
    $stmtInsertDetail = $dbh->prepare("INSERT INTO monthly_plan_details (plan_id, detail_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteDetail = $dbh->prepare("DELETE FROM monthly_plan_details WHERE plan_id = ? AND detail_id = ?");

    if (!empty($amounts)) {
        foreach ($amounts as $detail_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $detail_id = (int)$detail_id;

            $stmtCheckDetail->execute([$plan_id, $detail_id]);
            $existingId = $stmtCheckDetail->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateDetail->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertDetail->execute([$plan_id, $detail_id, $amountValue]);
                }
            } elseif ($existingId) {
                // 0円なら削除してゴミを残さない
                $stmtDeleteDetail->execute([$plan_id, $detail_id]);
            }
        }
    }

    // ----------------------------
    // 4. 収入明細 (monthly_plan_revenues)
    // ----------------------------
    $stmtCheckRev  = $dbh->prepare("SELECT id FROM monthly_plan_revenues WHERE plan_id = ? AND revenue_item_id = ?");
    $stmtUpdateRev = $dbh->prepare("UPDATE monthly_plan_revenues SET amount = ? WHERE id = ?");
    $stmtInsertRev = $dbh->prepare("INSERT INTO monthly_plan_revenues (plan_id, revenue_item_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteRev = $dbh->prepare("DELETE FROM monthly_plan_revenues WHERE plan_id = ? AND revenue_item_id = ?");

    if (!empty($revenues)) {
        foreach ($revenues as $revenue_item_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $revenue_item_id = (int)$revenue_item_id;

            $stmtCheckRev->execute([$plan_id, $revenue_item_id]);
            $existingId = $stmtCheckRev->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateRev->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertRev->execute([$plan_id, $revenue_item_id, $amountValue]);
                }
            } elseif ($existingId) {
                $stmtDeleteRev->execute([$plan_id, $revenue_item_id]);
            }
        }
    }

    $logData = $data;
    if (!empty($data['target_office_id'])) {
        $logData['office_id'] = $data['target_office_id'];
    }

    // ログ記録
    logAudit($dbh, 'plan', $plan_id, 'update', $logData);
}

function fixParentPlan(int $plan_id, PDO $dbh)
{
    // 1. ステータスを Fixed に
    $stmt = $dbh->prepare("UPDATE monthly_plan SET status = 'fixed', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$plan_id]);

    // 子データも全て Fixed に強制統一（整合性のため）
    $stmtChild = $dbh->prepare("UPDATE monthly_plan_time SET status = 'fixed' WHERE monthly_plan_id = ?");
    $stmtChild->execute([$plan_id]);

    // 2. Outlook(月末見込み)へ反映
    reflectToOutlook($plan_id, $dbh);

    // ログ記録
    logAudit($dbh, 'plan', $plan_id, 'parent_fixed', ['msg' => 'Admin changed parent status to fixed']);
}

function unlockParentPlan(int $plan_id, PDO $dbh)
{
    // ステータスを draft に戻す
    $stmt = $dbh->prepare("UPDATE monthly_plan SET status = 'draft', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$plan_id]);

    // ログ記録
    logAudit($dbh, 'plan', $plan_id, 'parent_unlock', ['msg' => 'Admin unlocked plan']);
}

function rejectMonthlyPlan(int $plan_id, int $target_office_id, PDO $dbh)
{
    // 1. 親が fixed なら draft に戻す (ロック解除)
    $stmtHead = $dbh->prepare("UPDATE monthly_plan SET status = 'draft', updated_at = NOW() WHERE id = ? AND status = 'fixed'");
    $stmtHead->execute([$plan_id]);

    // 2. 指定された営業所の子データを draft に戻す
    $stmtChild = $dbh->prepare("UPDATE monthly_plan_time SET status = 'draft', updated_at = NOW() WHERE monthly_plan_id = ? AND office_id = ?");
    $stmtChild->execute([$plan_id, $target_office_id]);

    // 3. ログ記録 (office_id を記録)
    $logContent = [
        'office_id' => $target_office_id,
        'msg'       => 'Admin rejected data to draft'
    ];
    logAudit($dbh, 'plan', $plan_id, 'reject', $logContent);
}

function confirmMonthlyPlan(array $data, PDO $dbh)
{
    // 1. まずデータを保存/更新
    updateMonthlyPlan($data, $dbh);

    // 2. IDの解決
    $plan_id = $data['plan_id'] ?? null;
    if (empty($plan_id)) {
        // updateMonthlyPlanで作成された可能性があるため再取得
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_plan WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $plan_id = $stmtCheck->fetchColumn();
    }

    if (!$plan_id) {
        throw new Exception("対象の予定データが存在しません。");
    }

    $targetOfficeId = $data['target_office_id'] ?? null;
    if (!$targetOfficeId) {
        throw new Exception("確定対象の営業所が指定されていません。");
    }

    // 3. ステータスを Fixed に更新
    $stmtFix = $dbh->prepare("UPDATE monthly_plan_time SET status = 'fixed', updated_at = NOW() WHERE monthly_plan_id = ? AND office_id = ?");
    $stmtFix->execute([$plan_id, $targetOfficeId]);

    // 4. ログ記録
    logAudit($dbh, 'plan', $plan_id, 'fix', ['office_id' => $targetOfficeId]);
}

function reflectToOutlook(int $plan_id, PDO $dbh)
{
    // 予定情報を取得
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_plan WHERE id = ?");
    $stmt->execute([$plan_id]);
    $pl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pl) {
        throw new Exception("参照元の予定データが見つかりません。");
    }
    $year = $pl['year'];
    $month = $pl['month'];
    $rate = $pl['hourly_rate'];

    // ----------------------------------------------------
    // Outlookの親レコードを探す (洗い替え)
    // ----------------------------------------------------
    $stmtOut = $dbh->prepare("SELECT id FROM monthly_outlook WHERE year = ? AND month = ?");
    $stmtOut->execute([$year, $month]);
    $outlookId = $stmtOut->fetchColumn();

    if ($outlookId) {
        // 既存データの詳細をクリア
        $dbh->prepare("DELETE FROM monthly_outlook_time WHERE monthly_outlook_id = ?")->execute([$outlookId]);
        $dbh->prepare("DELETE FROM monthly_outlook_details WHERE outlook_id = ?")->execute([$outlookId]);
        $dbh->prepare("DELETE FROM monthly_outlook_revenues WHERE outlook_id = ?")->execute([$outlookId]);

        // 親データの更新
        $dbh->prepare("UPDATE monthly_outlook SET hourly_rate = ?, updated_at = NOW() WHERE id = ?")->execute([$rate, $outlookId]);
    } else {
        // 新規作成
        $stmtIns = $dbh->prepare("INSERT INTO monthly_outlook (year, month, hourly_rate, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', NOW(), NOW())");
        $stmtIns->execute([$year, $month, $rate]);
        $outlookId = $dbh->lastInsertId();
    }

    // ----------------------------------------------------
    // データのコピー (Plan -> Outlook)
    // ----------------------------------------------------
    // 1. Time (Outlookの各営業所ステータスを 'draft' で作成)
    $sqlTime = "
        INSERT INTO monthly_outlook_time 
        (monthly_outlook_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at)
        SELECT 
            ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, 'draft', NOW(), NOW()
        FROM monthly_plan_time 
        WHERE monthly_plan_id = ?
    ";
    $stmtTime = $dbh->prepare($sqlTime);
    $stmtTime->execute([$outlookId, $plan_id]);

    // 2. Details (Plan_details -> Outlook_details)
    $sqlDet = "
        INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount, created_at, updated_at)
        SELECT ?, detail_id, amount, NOW(), NOW()
        FROM monthly_plan_details
        WHERE plan_id = ?
    ";
    $stmtDet = $dbh->prepare($sqlDet);
    $stmtDet->execute([$outlookId, $plan_id]);

    // 3. Revenues (Plan_revenues -> Outlook_revenues)
    $sqlRev = "
        INSERT INTO monthly_outlook_revenues (outlook_id, revenue_item_id, amount, created_at, updated_at)
        SELECT ?, revenue_item_id, amount, NOW(), NOW()
        FROM monthly_plan_revenues
        WHERE plan_id = ?
    ";
    $stmtRev = $dbh->prepare($sqlRev);
    $stmtRev->execute([$outlookId, $plan_id]);
}
