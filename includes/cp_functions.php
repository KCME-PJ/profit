<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';

/**
 * CPの新規登録処理 (Create)
 */
function registerMonthlyCp(array $data, $dbh = null, array $userContext = [])
{
    if (!$dbh) $dbh = getDb();

    // Adminガード
    if (($userContext['role'] ?? '') === 'admin') {
        throw new Exception("管理者は新規登録を行えません。");
    }

    $year = $data['year'] ?? null;
    $month = $data['month'] ?? null;
    $hourly_rate_common = (float)($data['hourly_rate'] ?? 0);

    if (empty($year) || empty($month)) {
        throw new Exception('年度と月は必須項目です。');
    }

    // 1. 親テーブル(ヘッダー)
    $stmtCheck = $dbh->prepare("SELECT id FROM monthly_cp WHERE year = ? AND month = ?");
    $stmtCheck->execute([$year, $month]);
    $monthly_cp_id = $stmtCheck->fetchColumn();

    if (!$monthly_cp_id) {
        // 親テーブルに hourly_rate を保存
        $stmtCp = $dbh->prepare("INSERT INTO monthly_cp (year, month, hourly_rate, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', NOW(), NOW())");
        $stmtCp->execute([$year, $month, $hourly_rate_common]);
        $monthly_cp_id = $dbh->lastInsertId();
    } else {
        // 既にある場合は親の賃率を更新
        $dbh->prepare("UPDATE monthly_cp SET hourly_rate = ?, updated_at = NOW() WHERE id = ?")->execute([$hourly_rate_common, $monthly_cp_id]);
    }

    // 2. 自拠点データの登録
    $targetOfficeId = $userContext['office_id'] ?? null;

    if (!$targetOfficeId) throw new Exception("登録対象の営業所が特定できません。");

    // 重複チェック
    $stmtExist = $dbh->prepare("SELECT id FROM monthly_cp_time WHERE monthly_cp_id = ? AND office_id = ? AND type = 'cp'");
    $stmtExist->execute([$monthly_cp_id, $targetOfficeId]);
    if ($stmtExist->fetch()) {
        throw new Exception("この月のデータは既に登録されています。修正する場合は編集画面を使用してください。");
    }

    // データ準備 (安全に取り出す)
    $officeTimeData = $data['officeTimeData'] ?? [];
    $myTimeData = [];
    if (isset($officeTimeData[$targetOfficeId])) {
        $myTimeData = $officeTimeData[$targetOfficeId];
    } elseif (isset($officeTimeData[(string)$targetOfficeId])) {
        $myTimeData = $officeTimeData[(string)$targetOfficeId];
    }

    insertCpTimeOnly([
        'monthly_cp_id' => $monthly_cp_id,
        'office_id' => $targetOfficeId,
        'standard_hours' => $myTimeData['standard_hours'] ?? 0,
        'overtime_hours' => $myTimeData['overtime_hours'] ?? 0,
        'transferred_hours' => $myTimeData['transferred_hours'] ?? 0,
        'fulltime_count' => $myTimeData['fulltime_count'] ?? 0,
        'contract_count' => $myTimeData['contract_count'] ?? 0,
        'dispatch_count' => $myTimeData['dispatch_count'] ?? 0
    ], $dbh);

    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];
    insertCpDetails($monthly_cp_id, $targetOfficeId, $amounts, $revenues, $dbh);

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'create', [
        'office_id' => $targetOfficeId,
        'year'      => $year,
        'month'     => $month,
        'msg'       => 'New CP entry registered'
    ]);
}

/**
 * CPの更新処理 (Update)
 */
function updateMonthlyCp(array $data, PDO $dbh, array $userContext = [])
{
    // Adminガード
    if (($userContext['role'] ?? '') === 'admin') {
        throw new Exception("管理者はデータを修正できません。差し戻しのみ可能です。");
    }

    if (empty($data['monthly_cp_id'])) {
        throw new Exception("monthly_cp_id がありません。");
    }
    $monthly_cp_id = (int)$data['monthly_cp_id'];

    $targetOfficeId = $userContext['office_id'] ?? null;
    if (isset($data['target_office_id']) && (int)$data['target_office_id'] !== (int)$targetOfficeId) {
        throw new Exception("権限のない営業所です。");
    }

    if (!$targetOfficeId) throw new Exception("更新対象の営業所が特定できません。");

    // 1. 存在確認
    $stmtCheck = $dbh->prepare("SELECT id FROM monthly_cp_time WHERE monthly_cp_id = ? AND office_id = ? AND type = 'cp'");
    $stmtCheck->execute([$monthly_cp_id, $targetOfficeId]);
    $existingId = $stmtCheck->fetchColumn();

    if (!$existingId) {
        throw new Exception("更新対象のデータが存在しません。先に新規登録を行ってください。");
    }

    // 2. ステータスチェック
    $stmtStatus = $dbh->prepare("SELECT status FROM monthly_cp_time WHERE id = ?");
    $stmtStatus->execute([$existingId]);
    $currentStatus = $stmtStatus->fetchColumn();

    if ($currentStatus === 'fixed' && ($data['action_type'] ?? '') !== 'unlock') {
        throw new Exception("確定済みのため修正できません。");
    }

    // 3. 時間データ更新 (安全に取り出す)
    $officeTimeData = $data['officeTimeData'] ?? [];
    $myTimeData = [];
    if (isset($officeTimeData[$targetOfficeId])) {
        $myTimeData = $officeTimeData[$targetOfficeId];
    } elseif (isset($officeTimeData[(string)$targetOfficeId])) {
        $myTimeData = $officeTimeData[(string)$targetOfficeId];
    }

    // 親テーブル(monthly_cp)の賃率を更新
    $hourly_rate_common = (float)($data['hourly_rate'] ?? 0);
    $dbh->prepare("UPDATE monthly_cp SET hourly_rate = ?, updated_at = NOW() WHERE id = ?")->execute([$hourly_rate_common, $monthly_cp_id]);

    updateCpTimeOnly($existingId, [
        'standard_hours' => $myTimeData['standard_hours'] ?? 0,
        'overtime_hours' => $myTimeData['overtime_hours'] ?? 0,
        'transferred_hours' => $myTimeData['transferred_hours'] ?? 0,
        'fulltime_count' => $myTimeData['fulltime_count'] ?? 0,
        'contract_count' => $myTimeData['contract_count'] ?? 0,
        'dispatch_count' => $myTimeData['dispatch_count'] ?? 0
    ], $dbh);

    // 4. 明細データ洗い替え
    deleteCpDetails($monthly_cp_id, $targetOfficeId, $dbh);
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];
    insertCpDetails($monthly_cp_id, $targetOfficeId, $amounts, $revenues, $dbh);

    $logData = $data;
    $logData['office_id'] = $targetOfficeId;

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'update', $logData);
}

/**
 * CPの確定処理
 */
function confirmMonthlyCp(array $data, PDO $dbh, array $userContext = [])
{
    updateMonthlyCp($data, $dbh, $userContext);

    $monthly_cp_id = (int)$data['monthly_cp_id'];
    $targetOfficeId = $userContext['office_id'];

    $dbh->prepare("UPDATE monthly_cp_time SET status = 'fixed', updated_at = NOW() WHERE monthly_cp_id = ? AND office_id = ? AND type = 'cp'")
        ->execute([$monthly_cp_id, $targetOfficeId]);

    reflectToForecastSingleOffice($monthly_cp_id, $targetOfficeId, $dbh);

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'fix', ['office_id' => $targetOfficeId]);
}

/**
 * CPの差し戻し処理
 */
function rejectMonthlyCp(array $data, PDO $dbh, array $userContext = [])
{
    // Admin権限チェック
    if (($userContext['role'] ?? '') !== 'admin') {
        throw new Exception("差し戻し操作は管理者のみ可能です。");
    }

    $monthly_cp_id = (int)($data['monthly_cp_id'] ?? 0);
    $targetOfficeId = $data['target_office_id'] ?? null;

    if (!$monthly_cp_id || !$targetOfficeId) {
        throw new Exception("対象データが特定できません。");
    }

    // 1. 子テーブル(monthly_cp_time)のステータス変更
    $stmtUpd = $dbh->prepare("UPDATE monthly_cp_time SET status = 'draft', updated_at = NOW() WHERE monthly_cp_id = ? AND office_id = ? AND type = 'cp'");
    $stmtUpd->execute([$monthly_cp_id, $targetOfficeId]);

    if ($stmtUpd->rowCount() === 0) {
        throw new Exception("差し戻し対象のデータが存在しません。");
    }

    // 2. 親テーブル(monthly_cp)のステータス変更 (ロック解除)
    $dbh->prepare("UPDATE monthly_cp SET status = 'draft', updated_at = NOW() WHERE id = ?")->execute([$monthly_cp_id]);

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'reject', ['office_id' => $targetOfficeId, 'msg' => 'Admin rejected data to draft']);
}

/**
 * 親ステータスの更新処理 (全社確定/解除)
 */
function updateParentStatus(array $data, $newStatus, PDO $dbh, array $userContext = [])
{
    if (($userContext['role'] ?? '') !== 'admin') {
        throw new Exception("ステータス変更は管理者のみ可能です。");
    }

    $monthly_cp_id = (int)($data['monthly_cp_id'] ?? 0);
    if (!$monthly_cp_id) {
        throw new Exception("対象月が特定できません。");
    }

    // Fixedにする場合、全データがfixedか確認
    if ($newStatus === 'fixed') {
        $stmt = $dbh->prepare("SELECT status FROM monthly_cp_time WHERE monthly_cp_id = ? AND type = 'cp'");
        $stmt->execute([$monthly_cp_id]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($statuses as $st) {
            if ($st !== 'fixed') {
                throw new Exception("未確定の営業所が存在するため、月次締めを行えません。");
            }
        }
    }

    // ログ用に年度と月を取得する
    $stmtInfo = $dbh->prepare("SELECT year, month FROM monthly_cp WHERE id = ?");
    $stmtInfo->execute([$monthly_cp_id]);
    $cpInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);
    $year = $cpInfo['year'] ?? null;
    $month = $cpInfo['month'] ?? null;

    // ステータス更新実行
    $stmt = $dbh->prepare("UPDATE monthly_cp SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $monthly_cp_id]);

    // ログ記録
    logAudit($dbh, 'cp', $monthly_cp_id, 'parent_' . $newStatus, [
        'year'  => $year,
        'month' => $month,
        'msg'   => "Admin changed parent status to {$newStatus}"
    ]);
}

/**
 * CPデータをForecastへ反映する関数
 */
function reflectToForecastSingleOffice($cpId, $officeId, $dbh)
{
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_cp WHERE id = ?");
    $stmt->execute([$cpId]);
    $cpInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cpInfo) return;

    $year = $cpInfo['year'];
    $month = $cpInfo['month'];
    $rate = $cpInfo['hourly_rate'];

    $stmtF = $dbh->prepare("SELECT id FROM monthly_forecast WHERE year = ? AND month = ?");
    $stmtF->execute([$year, $month]);
    $forecastId = $stmtF->fetchColumn();

    if (!$forecastId) {
        $stmtIns = $dbh->prepare("INSERT INTO monthly_forecast (year, month, hourly_rate, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', NOW(), NOW())");
        $stmtIns->execute([$year, $month, $rate]);
        $forecastId = $dbh->lastInsertId();
    } else {
        $dbh->prepare("UPDATE monthly_forecast SET hourly_rate = ? WHERE id = ?")->execute([$rate, $forecastId]);
    }

    // A. クリーンアップ
    $dbh->prepare("DELETE FROM monthly_forecast_time WHERE monthly_forecast_id = ? AND office_id = ?")->execute([$forecastId, $officeId]);
    $dbh->prepare("DELETE fd FROM monthly_forecast_details fd INNER JOIN details d ON fd.detail_id = d.id WHERE fd.forecast_id = ? AND d.office_id = ?")->execute([$forecastId, $officeId]);
    $dbh->prepare("DELETE fr FROM monthly_forecast_revenues fr INNER JOIN revenue_items r ON fr.revenue_item_id = r.id WHERE fr.forecast_id = ? AND r.office_id = ?")->execute([$forecastId, $officeId]);

    // B. データコピー
    $sqlCopyTime = "INSERT INTO monthly_forecast_time 
                    (monthly_forecast_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, status, created_at, updated_at) 
                    SELECT ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, 'draft', NOW(), NOW() 
                    FROM monthly_cp_time 
                    WHERE monthly_cp_id = ? AND office_id = ? AND type = 'cp' 
                    LIMIT 1";
    $dbh->prepare($sqlCopyTime)->execute([$forecastId, $cpId, $officeId]);

    $sqlCopyDet = "INSERT IGNORE INTO monthly_forecast_details 
                    (forecast_id, detail_id, amount) 
                    SELECT ?, d.detail_id, d.amount 
                    FROM monthly_cp_details d 
                    INNER JOIN details m ON d.detail_id = m.id 
                    WHERE d.monthly_cp_id = ? AND m.office_id = ? AND d.type = 'cp'";
    $dbh->prepare($sqlCopyDet)->execute([$forecastId, $cpId, $officeId]);

    $sqlCopyRev = "INSERT IGNORE INTO monthly_forecast_revenues 
                    (forecast_id, revenue_item_id, amount) 
                    SELECT ?, r.revenue_item_id, r.amount 
                    FROM monthly_cp_revenues r 
                    INNER JOIN revenue_items m ON r.revenue_item_id = m.id 
                    WHERE r.monthly_cp_id = ? AND m.office_id = ?";
    $dbh->prepare($sqlCopyRev)->execute([$forecastId, $cpId, $officeId]);
}

// ---------------------------------------------------------
// 内部用 実働部隊関数
// ---------------------------------------------------------
function insertCpTimeOnly($data, $dbh)
{
    $sql = "INSERT INTO monthly_cp_time (monthly_cp_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count, type, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'cp', 'draft', NOW(), NOW())";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([$data['monthly_cp_id'], $data['office_id'], $data['standard_hours'], $data['overtime_hours'], $data['transferred_hours'], $data['fulltime_count'], $data['contract_count'], $data['dispatch_count']]);
}
function updateCpTimeOnly($id, $data, $dbh)
{
    $sql = "UPDATE monthly_cp_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([$data['standard_hours'], $data['overtime_hours'], $data['transferred_hours'], $data['fulltime_count'], $data['contract_count'], $data['dispatch_count'], $id]);
}
function deleteCpDetails($cpId, $officeId, $dbh)
{
    $sqlDelDet = "DELETE d FROM monthly_cp_details d 
                    INNER JOIN details m ON d.detail_id = m.id 
                    WHERE d.monthly_cp_id = ? 
                    AND d.type = 'cp' 
                    AND (m.office_id = ? OR m.office_id IS NULL)";
    $dbh->prepare($sqlDelDet)->execute([$cpId, $officeId]);

    $sqlDelRev = "DELETE r FROM monthly_cp_revenues r 
                    INNER JOIN revenue_items m ON r.revenue_item_id = m.id 
                    WHERE r.monthly_cp_id = ? 
                    AND (m.office_id = ? OR m.office_id IS NULL)";
    $dbh->prepare($sqlDelRev)->execute([$cpId, $officeId]);
}
function insertCpDetails($cpId, $targetOfficeId, $amounts, $revenues, $dbh)
{
    $stmtDetail = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount, type) VALUES (?, ?, ?, 'cp')");
    $stmtCheckDetail = $dbh->prepare("SELECT office_id FROM details WHERE id = ?");
    foreach ($amounts as $id => $val) {
        if ((float)$val == 0) continue;
        $stmtCheckDetail->execute([$id]);
        $owner = $stmtCheckDetail->fetchColumn();
        if (empty($owner) || (int)$owner === (int)$targetOfficeId) {
            $stmtDetail->execute([$cpId, $id, $val]);
        }
    }
    $stmtRev = $dbh->prepare("INSERT INTO monthly_cp_revenues (monthly_cp_id, revenue_item_id, amount) VALUES (?, ?, ?)");
    $stmtCheckRev = $dbh->prepare("SELECT office_id FROM revenue_items WHERE id = ?");
    foreach ($revenues as $id => $val) {
        if ((float)$val == 0) continue;
        $stmtCheckRev->execute([$id]);
        $owner = $stmtCheckRev->fetchColumn();
        if (empty($owner) || (int)$owner === (int)$targetOfficeId) {
            $stmtRev->execute([$cpId, $id, $val]);
        }
    }
}
