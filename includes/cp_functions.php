<?php
require_once '../includes/database.php';

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

    if (!$monthly_cp_id) {
        throw new Exception('monthly_cp_id が存在しません。');
    }
    try {
        $dbh->beginTransaction();

        // 勘定科目詳細の更新・挿入・削除
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

        // 時間・賃率の更新・追加
        $stmt = $dbh->prepare("SELECT id FROM monthly_cp_time WHERE monthly_cp_id = ?");
        $stmt->execute([$monthly_cp_id]);
        $existing_time = $stmt->fetchColumn();

        if ($existing_time) {
            $stmt = $dbh->prepare("UPDATE monthly_cp_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ? WHERE id = ?");
            $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $existing_time]);
        } else {
            $stmt = $dbh->prepare("INSERT INTO monthly_cp_time (monthly_cp_id, standard_hours, overtime_hours, transferred_hours, hourly_rate) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$monthly_cp_id, $standard_hours, $overtime_hours, $transferred_hours, $hourly_rate]);
        }

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("CPの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

function reflectToForecast($monthly_cp_id, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    // CP本体から年度・月を取得
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
        // 対象年月の forecast（ヘッダー）を削除
        $stmt = $dbh->prepare("DELETE FROM monthly_forecast WHERE year = ? AND month = ?");
        $stmt->execute([$year, $month]);

        // 時間・賃率情報を取得
        $stmt = $dbh->prepare("SELECT standard_hours, overtime_hours, transferred_hours, hourly_rate 
                               FROM monthly_cp_time WHERE monthly_cp_id = ?");
        $stmt->execute([$monthly_cp_id]);
        $timeInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        // monthly_forecast（ヘッダー）に挿入
        $stmt = $dbh->prepare("INSERT INTO monthly_forecast (year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate)
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $year,
            $month,
            $timeInfo['standard_hours'] ?? 0,
            $timeInfo['overtime_hours'] ?? 0,
            $timeInfo['transferred_hours'] ?? 0,
            $timeInfo['hourly_rate'] ?? 0
        ]);

        // 挿入した forecast_id を取得
        $forecast_id = $dbh->lastInsertId();

        // 明細データを取得
        $stmt = $dbh->prepare("SELECT detail_id, amount FROM monthly_cp_details WHERE monthly_cp_id = ?");
        $stmt->execute([$monthly_cp_id]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 明細を monthly_forecast_details に挿入
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
    updateMonthlyCp($data, $dbh);
    reflectToForecast($data['monthly_cp_id'], $dbh);
}
