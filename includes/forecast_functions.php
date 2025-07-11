<?php
require_once '../includes/database.php';

function updateMonthlyForecast($data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $forecast_id = $data['forecast_id'] ?? null;
    $amounts = $data['amounts'] ?? [];
    $standard_hours = $data['standard_hours'] ?? 0;
    $overtime_hours = $data['overtime_hours'] ?? 0;
    $transferred_hours = $data['transferred_hours'] ?? 0;
    $hourly_rate = $data['hourly_rate'] ?? 0;

    if (!$forecast_id) {
        throw new Exception('forecast_id が存在しません。');
    }

    // ステータス確認（fixedならエラー）
    $stmt = $dbh->prepare("SELECT status FROM monthly_forecast WHERE id = ?");
    $stmt->execute([$forecast_id]);
    $status = $stmt->fetchColumn();
    if ($status === 'fixed') {
        throw new Exception("この見通しはすでに確定済みで、編集できません。");
    }

    try {
        $dbh->beginTransaction();

        // 勘定科目詳細の更新・挿入・削除
        foreach ($amounts as $detail_id => $amount) {
            if ($amount === "" || $amount === null) {
                $stmt = $dbh->prepare("DELETE FROM monthly_forecast_details WHERE forecast_id = ? AND detail_id = ?");
                $stmt->execute([$forecast_id, $detail_id]);
            } else {
                $stmt = $dbh->prepare("SELECT id FROM monthly_forecast_details WHERE forecast_id = ? AND detail_id = ?");
                $stmt->execute([$forecast_id, $detail_id]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $stmt = $dbh->prepare("UPDATE monthly_forecast_details SET amount = ? WHERE id = ?");
                    $stmt->execute([$amount, $existing]);
                } else {
                    $stmt = $dbh->prepare("INSERT INTO monthly_forecast_details (forecast_id, detail_id, amount) VALUES (?, ?, ?)");
                    $stmt->execute([$forecast_id, $detail_id, $amount]);
                }
            }
        }

        // 時間・賃率の更新・追加
        $stmt = $dbh->prepare("UPDATE monthly_forecast SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ? WHERE id = ?");
        $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $forecast_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("見通しの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

function reflectToPlan($forecast_id, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $stmt = $dbh->prepare("SELECT year, month FROM monthly_forecast WHERE id = ?");
    $stmt->execute([$forecast_id]);
    $forecast = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$forecast) {
        throw new Exception("forecast_id が不正です。");
    }

    $year = $forecast['year'];
    $month = $forecast['month'];

    $dbh->beginTransaction();

    try {
        // 予定テーブルを削除してから再挿入
        $stmt = $dbh->prepare("DELETE FROM monthly_plan WHERE year = ? AND month = ?");
        $stmt->execute([$year, $month]);

        // 時間・賃率情報を取得
        $stmt = $dbh->prepare("INSERT INTO monthly_plan (year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate)
                               SELECT year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate
                               FROM monthly_forecast WHERE id = ?");
        $stmt->execute([$forecast_id]);

        $plan_id = $dbh->lastInsertId();

        // 見通し明細を予定明細にコピー
        $stmt = $dbh->prepare("INSERT INTO monthly_plan_details (plan_id, detail_id, amount)
                               SELECT ?, detail_id, amount FROM monthly_forecast_details WHERE forecast_id = ?");
        $stmt->execute([$plan_id, $forecast_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("予定への反映中にエラー: " . $e->getMessage());
    }
}

function confirmMonthlyForecast($data, $dbh = null)
{
    updateMonthlyForecast($data, $dbh);
    reflectToPlan($data['forecast_id'], $dbh);

    $forecast_id = $data['forecast_id'] ?? null;
    if (!$forecast_id) {
        throw new Exception("forecast_id が存在しません。");
    }

    try {
        $stmt = $dbh->prepare("UPDATE monthly_forecast SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$forecast_id]);
    } catch (Exception $e) {
        throw new Exception("見通しの確定中にエラーが発生しました: " . $e->getMessage());
    }
}
