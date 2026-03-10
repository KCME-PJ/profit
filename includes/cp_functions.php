<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';

/**
 * 月次CPの更新処理（データ保存用）
 */
function updateMonthlyCp(array $data, PDO $dbh)
{
    if (empty($data['monthly_cp_id'])) {
        if (empty($data['year']) || empty($data['month'])) {
            throw new Exception("更新対象のID、または年月の指定がありません。");
        }
    }

    $monthly_cp_id = $data['monthly_cp_id'];

    // IDがない場合（新規保存時など）、年月からIDを取得または作成
    if (!$monthly_cp_id) {
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_cp WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $monthly_cp_id = $stmtCheck->fetchColumn();

        if (!$monthly_cp_id) {
            $stmtCreate = $dbh->prepare("INSERT INTO monthly_cp (year, month, status, created_at, updated_at) VALUES (?, ?, 'draft', NOW(), NOW())");
            $stmtCreate->execute([$data['year'], $data['month']]);
            $monthly_cp_id = $dbh->lastInsertId();
        }
    }

    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];

    // ----------------------------
    // 0. 排他制御 & ステータスチェック
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status, hourly_rate, updated_at FROM monthly_cp WHERE id = ? FOR UPDATE");
    $stmtStatusCheck->execute([$monthly_cp_id]);
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
        $stmtChildStatus = $dbh->prepare("SELECT status FROM monthly_cp_time WHERE monthly_cp_id = ? AND office_id = ?");
        $stmtChildStatus->execute([$monthly_cp_id, $targetOfficeId]);
        $childStatus = $stmtChildStatus->fetchColumn();

        // 復元や更新を行おうとした対象営業所が既にFixedならエラーにする
        if ($childStatus === 'fixed') {
            throw new Exception("この営業所のCPデータは確定済みのため、復元・修正できません。");
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
    // 1. 親テーブル (monthly_cp) の賃率更新
    // ----------------------------
    // 全社共通賃率
    $hourly_rate = $currentData['hourly_rate'];
    if (isset($data['hourly_rate']) && $data['hourly_rate'] !== '') {
        $hourly_rate = (float)$data['hourly_rate'];
    }

    $stmtParent = $dbh->prepare("UPDATE monthly_cp SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
    $stmtParent->execute([$hourly_rate, $monthly_cp_id]);

    // ----------------------------
    // 2. 営業所別時間データ (monthly_cp_time)
    // ----------------------------
    $stmtCheckTime  = $dbh->prepare("SELECT id FROM monthly_cp_time WHERE monthly_cp_id = ? AND office_id = ?");
    $stmtUpdateTime = $dbh->prepare("UPDATE monthly_cp_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW() WHERE id = ?");
    $stmtInsertTime = $dbh->prepare("INSERT INTO monthly_cp_time (monthly_cp_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())");

    foreach ($officeTimeData as $office_id => $time) {
        $office_id = (int)$office_id;
        if ($office_id <= 0) continue;

        $standard = (float)($time['standard_hours'] ?? 0);
        $overtime = (float)($time['overtime_hours'] ?? 0);
        $transfer = (float)($time['transferred_hours'] ?? 0);
        $full = (int)($time['fulltime_count'] ?? 0);
        $contract = (int)($time['contract_count'] ?? 0);
        $dispatch = (int)($time['dispatch_count'] ?? 0);

        $stmtCheckTime->execute([$monthly_cp_id, $office_id]);
        $existingId = $stmtCheckTime->fetchColumn();

        if ($existingId) {
            $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
        } else {
            // 新規作成時は draft
            $stmtInsertTime->execute([$monthly_cp_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
        }
    }

    // ----------------------------
    // 3. 経費明細 (monthly_cp_details)
    // ----------------------------
    $stmtCheckDetail  = $dbh->prepare("SELECT id FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ?");
    $stmtUpdateDetail = $dbh->prepare("UPDATE monthly_cp_details SET amount = ? WHERE id = ?");
    $stmtInsertDetail = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteDetail = $dbh->prepare("DELETE FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ?");

    if (!empty($amounts)) {
        foreach ($amounts as $detail_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $detail_id = (int)$detail_id;

            $stmtCheckDetail->execute([$monthly_cp_id, $detail_id]);
            $existingId = $stmtCheckDetail->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateDetail->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertDetail->execute([$monthly_cp_id, $detail_id, $amountValue]);
                }
            } elseif ($existingId) {
                // 0円なら削除してゴミを残さない
                $stmtDeleteDetail->execute([$monthly_cp_id, $detail_id]);
            }
        }
    }

    // ----------------------------
    // 4. 収入明細 (monthly_cp_revenues)
    // ----------------------------
    $stmtCheckRev  = $dbh->prepare("SELECT id FROM monthly_cp_revenues WHERE monthly_cp_id = ? AND revenue_item_id = ?");
    $stmtUpdateRev = $dbh->prepare("UPDATE monthly_cp_revenues SET amount = ? WHERE id = ?");
    $stmtInsertRev = $dbh->prepare("INSERT INTO monthly_cp_revenues (monthly_cp_id, revenue_item_id, amount) VALUES (?, ?, ?)");
    $stmtDeleteRev = $dbh->prepare("DELETE FROM monthly_cp_revenues WHERE monthly_cp_id = ? AND revenue_item_id = ?");

    if (!empty($revenues)) {
        foreach ($revenues as $revenue_item_id => $amount) {
            $amountValue = ($amount === "" || $amount === null) ? 0 : (float)$amount;
            $revenue_item_id = (int)$revenue_item_id;

            $stmtCheckRev->execute([$monthly_cp_id, $revenue_item_id]);
            $existingId = $stmtCheckRev->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateRev->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertRev->execute([$monthly_cp_id, $revenue_item_id, $amountValue]);
                }
            } elseif ($existingId) {
                $stmtDeleteRev->execute([$monthly_cp_id, $revenue_item_id]);
            }
        }
    }

    $logData = $data;
    if (!empty($data['target_office_id'])) {
        $logData['office_id'] = $data['target_office_id'];
    }

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'update', $logData);
}

function fixParentCp(int $monthly_cp_id, PDO $dbh)
{
    // 1. ステータスを Fixed に
    $stmt = $dbh->prepare("UPDATE monthly_cp SET status = 'fixed', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$monthly_cp_id]);

    // 子データも全て Fixed に強制統一（整合性のため）
    $stmtChild = $dbh->prepare("UPDATE monthly_cp_time SET status = 'fixed' WHERE monthly_cp_id = ?");
    $stmtChild->execute([$monthly_cp_id]);

    // 2. Forecast(見通し)へ反映
    reflectToForecast($monthly_cp_id, $dbh);

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'parent_fixed', ['msg' => 'Admin changed parent status to fixed']);
}

function unlockParentCp(int $monthly_cp_id, PDO $dbh)
{
    // ステータスを draft に戻す
    $stmt = $dbh->prepare("UPDATE monthly_cp SET status = 'draft', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$monthly_cp_id]);

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'parent_unlock', ['msg' => 'Admin unlocked cp']);
}

function rejectMonthlyCp(int $monthly_cp_id, int $target_office_id, PDO $dbh)
{
    // 1. 親が fixed なら draft に戻す (ロック解除)
    $stmtHead = $dbh->prepare("UPDATE monthly_cp SET status = 'draft', updated_at = NOW() WHERE id = ? AND status = 'fixed'");
    $stmtHead->execute([$monthly_cp_id]);

    // 2. 指定された営業所の子データを draft に戻す
    $stmtChild = $dbh->prepare("UPDATE monthly_cp_time SET status = 'draft', updated_at = NOW() WHERE monthly_cp_id = ? AND office_id = ?");
    $stmtChild->execute([$monthly_cp_id, $target_office_id]);

    // 3. ログ記録 (office_id を記録)
    $logContent = [
        'office_id' => $target_office_id,
        'msg'       => 'Admin rejected data to draft'
    ];
    logAudit($dbh, 'cp', $monthly_cp_id, 'reject', $logContent);
}

function confirmMonthlyCp(array $data, PDO $dbh)
{
    // 1. まずデータを保存/更新
    updateMonthlyCp($data, $dbh);

    // 2. IDの解決
    $monthly_cp_id = $data['monthly_cp_id'] ?? null;
    if (empty($monthly_cp_id)) {
        // updateMonthlyCpで作成された可能性があるため再取得
        $stmtCheck = $dbh->prepare("SELECT id FROM monthly_cp WHERE year = ? AND month = ?");
        $stmtCheck->execute([$data['year'], $data['month']]);
        $monthly_cp_id = $stmtCheck->fetchColumn();
    }

    if (!$monthly_cp_id) {
        throw new Exception("対象のCPデータが存在しません。");
    }

    $targetOfficeId = $data['target_office_id'] ?? null;
    if (!$targetOfficeId) {
        throw new Exception("確定対象の営業所が指定されていません。");
    }

    // 3. ステータスを Fixed に更新
    $stmtFix = $dbh->prepare("UPDATE monthly_cp_time SET status = 'fixed', updated_at = NOW() WHERE monthly_cp_id = ? AND office_id = ?");
    $stmtFix->execute([$monthly_cp_id, $targetOfficeId]);

    // 4. ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'fix', ['office_id' => $targetOfficeId]);
}

function reflectToForecast(int $monthly_cp_id, PDO $dbh)
{
    // CP情報を取得
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_cp WHERE id = ?");
    $stmt->execute([$monthly_cp_id]);
    $cpInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cpInfo) {
        throw new Exception("参照元のCPデータが見つかりません。");
    }
    $year = $cpInfo['year'];
    $month = $cpInfo['month'];
    $rate = $cpInfo['hourly_rate'];

    // ----------------------------------------------------
    // Forecastの親レコードを探す (なければ作る、あればID取得)
    // ※Forecastは上書き仕様とするため、詳細データは一度消して入れ直す
    // ----------------------------------------------------
    $stmtForecast = $dbh->prepare("SELECT id FROM monthly_forecast WHERE year = ? AND month = ?");
    $stmtForecast->execute([$year, $month]);
    $forecastId = $stmtForecast->fetchColumn();

    if ($forecastId) {
        // 既存データの詳細をクリア
        $dbh->prepare("DELETE FROM monthly_forecast_time WHERE monthly_forecast_id = ?")->execute([$forecastId]);
        $dbh->prepare("DELETE FROM monthly_forecast_details WHERE forecast_id = ?")->execute([$forecastId]);
        $dbh->prepare("DELETE FROM monthly_forecast_revenues WHERE forecast_id = ?")->execute([$forecastId]);

        // 親データの更新 (賃率など)
        $dbh->prepare("UPDATE monthly_forecast SET hourly_rate = ?, updated_at = NOW() WHERE id = ?")->execute([$rate, $forecastId]);
    } else {
        // 新規作成
        $stmtIns = $dbh->prepare("INSERT INTO monthly_forecast (year, month, hourly_rate, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', NOW(), NOW())");
        $stmtIns->execute([$year, $month, $rate]);
        $forecastId = $dbh->lastInsertId();
    }

    // ----------------------------------------------------
    // データのコピー (CP -> Forecast)
    // ----------------------------------------------------
    // Forecastの各営業所ステータスを 'draft' で作成する
    $sqlTime = "
        INSERT INTO monthly_forecast_time 
        (monthly_forecast_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at)
        SELECT 
            ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, 'draft', NOW(), NOW()
        FROM monthly_cp_time 
        WHERE monthly_cp_id = ?
    ";
    $stmtTime = $dbh->prepare($sqlTime);
    $stmtTime->execute([$forecastId, $monthly_cp_id]);

    // 2. Details (CP_details -> Forecast_details)
    $sqlDet = "
        INSERT INTO monthly_forecast_details (forecast_id, detail_id, amount, created_at, updated_at)
        SELECT ?, detail_id, amount, NOW(), NOW()
        FROM monthly_cp_details
        WHERE monthly_cp_id = ?
    ";
    $stmtDet = $dbh->prepare($sqlDet);
    $stmtDet->execute([$forecastId, $monthly_cp_id]);

    // 3. Revenues (CP_revenues -> Forecast_revenues)
    $sqlRev = "
        INSERT INTO monthly_forecast_revenues (forecast_id, revenue_item_id, amount, created_at, updated_at)
        SELECT ?, revenue_item_id, amount, NOW(), NOW()
        FROM monthly_cp_revenues
        WHERE monthly_cp_id = ?
    ";
    $stmtRev = $dbh->prepare($sqlRev);
    $stmtRev->execute([$forecastId, $monthly_cp_id]);
}
