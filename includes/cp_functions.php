<?php
require_once '../includes/database.php';
require_once '../includes/common_functions.php';

// CPの登録処理
function registerMonthlyCp($data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $year = $data['year'] ?? null;
    $month = $data['month'] ?? null;
    $standard_hours = $data['standard_hours'] ?? null;
    $overtime_hours = $data['overtime_hours'] ?? null;
    $transferred_hours = $data['transferred_hours'] ?? null;
    $hourly_rate = $data['hourly_rate'] ?? null;
    $detail_ids = $data['detail_ids'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $fulltime = $data['fulltime_count'] ?? 0;
    $contract = $data['contract_count'] ?? 0;
    $dispatch = $data['dispatch_count'] ?? 0;

    if (empty($year) || empty($month)) {
        throw new Exception('年度と月は必須項目です。');
    }

    if (count($detail_ids) !== count($amounts)) {
        throw new Exception('詳細IDと金額の数が一致していません。');
    }

    // 既に登録済みチェック
    $stmtCheck = $dbh->prepare("SELECT COUNT(*) FROM monthly_cp WHERE year = ? AND month = ?");
    $stmtCheck->execute([$year, $month]);
    if ($stmtCheck->fetchColumn() > 0) {
        throw new Exception("この年度と月のデータはすでに登録されています。");
    }

    try {
        $dbh->beginTransaction();

        // monthly_cp 登録
        $stmt = $dbh->prepare("INSERT INTO monthly_cp (year, month) VALUES (?, ?)");
        $stmt->execute([$year, $month]);
        $monthly_cp_id = $dbh->lastInsertId();

        // monthly_cp_time 登録
        $stmt = $dbh->prepare("INSERT INTO monthly_cp_time 
            (monthly_cp_id, standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $monthly_cp_id,
            $standard_hours,
            $overtime_hours,
            $transferred_hours,
            $hourly_rate,
            $fulltime,
            $contract,
            $dispatch
        ]);

        // monthly_cp_details 登録
        $stmt = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount)
                               VALUES (?, ?, ?)");
        foreach ($detail_ids as $i => $detail_id) {
            $amount = $amounts[$i];
            if ($amount !== '' && $amount !== null) {
                $stmt->execute([$monthly_cp_id, $detail_id, $amount]);
            }
        }

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("CPの登録中にエラーが発生しました: " . $e->getMessage());
    }
}

// CPの更新処理
function updateMonthlyCp($data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $monthly_cp_id = $data['monthly_cp_id'] ?? null;
    $amounts = $data['amounts'] ?? [];
    $standard_hours = $data['standard_hours'] ?? null;
    $overtime_hours = $data['overtime_hours'] ?? null;
    $transferred_hours = $data['transferred_hours'] ?? null;
    $hourly_rate = $data['hourly_rate'] ?? null;
    $fulltime = $data['fulltime_count'] ?? null;
    $contract = $data['contract_count'] ?? null;
    $dispatch = $data['dispatch_count'] ?? null;

    if (!$monthly_cp_id) {
        throw new Exception('monthly_cp_id が存在しません。');
    }

    // 年月取得＋ロック確認
    $stmt = $dbh->prepare("SELECT year, month FROM monthly_cp WHERE id = ?");
    $stmt->execute([$monthly_cp_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("指定されたCPデータが見つかりません。");
    }
    if (isLocked('monthly_cp', $row['year'], $row['month'], $dbh)) {
        throw new Exception("このCPは確定済みのため修正できません。");
    }

    try {
        $dbh->beginTransaction();

        // 明細の更新・挿入・削除
        foreach ($amounts as $detail_id => $amount) {
            if ($amount === "" || $amount === null) {
                $stmt = $dbh->prepare("DELETE FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ?");
                $stmt->execute([$monthly_cp_id, $detail_id]);
            } else {
                $stmt = $dbh->prepare("SELECT id FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ?");
                $stmt->execute([$monthly_cp_id, $detail_id]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $stmt = $dbh->prepare("UPDATE monthly_cp_details SET amount = ? WHERE id = ?");
                    $stmt->execute([$amount, $existing]);
                } else {
                    $stmt = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount) VALUES (?, ?, ?)");
                    $stmt->execute([$monthly_cp_id, $detail_id, $amount]);
                }
            }
        }

        // 時間・賃率
        $stmt = $dbh->prepare("SELECT id FROM monthly_cp_time WHERE monthly_cp_id = ?");
        $stmt->execute([$monthly_cp_id]);
        $existing_time = $stmt->fetchColumn();

        if ($existing_time) {
            $stmt = $dbh->prepare("UPDATE monthly_cp_time 
                SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ?
                WHERE id = ?");
            $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $fulltime, $contract, $dispatch, $existing_time]);
        } else {
            $stmt = $dbh->prepare("INSERT INTO monthly_cp_time 
                (monthly_cp_id, standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$monthly_cp_id, $standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $fulltime, $contract, $dispatch]);
        }

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("CPの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

// PCから見通しへ反映する処理
function reflectToForecast($monthly_cp_id, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $stmt = $dbh->prepare("SELECT year, month FROM monthly_cp WHERE id = ?");
    $stmt->execute([$monthly_cp_id]);
    $cpInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cpInfo) {
        throw new Exception("monthly_cp_id が不正です");
    }

    $year = $cpInfo['year'];
    $month = $cpInfo['month'];

    $dbh->beginTransaction();

    try {
        $stmt = $dbh->prepare("DELETE FROM monthly_forecast WHERE year = ? AND month = ?");
        $stmt->execute([$year, $month]);

        $stmt = $dbh->prepare("SELECT standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count
                               FROM monthly_cp_time WHERE monthly_cp_id = ?");
        $stmt->execute([$monthly_cp_id]);
        $timeInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("INSERT INTO monthly_forecast 
            (year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $year,
            $month,
            $timeInfo['standard_hours'] ?? 0,
            $timeInfo['overtime_hours'] ?? 0,
            $timeInfo['transferred_hours'] ?? 0,
            $timeInfo['hourly_rate'] ?? 0,
            $timeInfo['fulltime_count'] ?? 0,
            $timeInfo['contract_count'] ?? 0,
            $timeInfo['dispatch_count'] ?? 0
        ]);

        $forecast_id = $dbh->lastInsertId();

        $stmt = $dbh->prepare("SELECT detail_id, amount FROM monthly_cp_details WHERE monthly_cp_id = ?");
        $stmt->execute([$monthly_cp_id]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("INSERT INTO monthly_forecast_details (forecast_id, detail_id, amount)
                               VALUES (?, ?, ?)");
        foreach ($details as $row) {
            $stmt->execute([$forecast_id, $row['detail_id'], $row['amount']]);
        }

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("見通しへの反映中にエラーが発生しました: " . $e->getMessage());
    }
}

function confirmMonthlyCp($data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    updateMonthlyCp($data, $dbh);
    reflectToForecast($data['monthly_cp_id'], $dbh);

    $monthly_cp_id = $data['monthly_cp_id'] ?? null;
    if (!$monthly_cp_id) {
        throw new Exception("monthly_cp_id が存在しません。");
    }

    try {
        $stmt = $dbh->prepare("UPDATE monthly_cp SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$monthly_cp_id]);
    } catch (Exception $e) {
        throw new Exception("CPの確定中にエラーが発生しました: " . $e->getMessage());
    }
}
