<?php
require_once '../includes/database.php';

function updateMonthlyOutlook($data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $outlook_id = $data['outlook_id'] ?? null;
    $amounts = $data['amounts'] ?? [];
    $standard_hours = $data['standard_hours'] ?? 0;
    $overtime_hours = $data['overtime_hours'] ?? 0;
    $transferred_hours = $data['transferred_hours'] ?? 0;
    $hourly_rate = $data['hourly_rate'] ?? 0;

    if (!$outlook_id) {
        throw new Exception('outlook_id が存在しません。');
    }

    try {
        $dbh->beginTransaction();

        // 明細の更新・削除・追加
        foreach ($amounts as $detail_id => $amount) {
            if ($amount === "" || $amount === null) {
                $stmt = $dbh->prepare("DELETE FROM monthly_outlook_details WHERE outlook_id = ? AND detail_id = ?");
                $stmt->execute([$outlook_id, $detail_id]);
            } else {
                $stmt = $dbh->prepare("SELECT id FROM monthly_outlook_details WHERE outlook_id = ? AND detail_id = ?");
                $stmt->execute([$outlook_id, $detail_id]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $stmt = $dbh->prepare("UPDATE monthly_outlook_details SET amount = ? WHERE id = ?");
                    $stmt->execute([$amount, $existing]);
                } else {
                    $stmt = $dbh->prepare("INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount) VALUES (?, ?, ?)");
                    $stmt->execute([$outlook_id, $detail_id, $amount]);
                }
            }
        }

        // 時間・賃率の更新
        $stmt = $dbh->prepare("UPDATE monthly_outlook SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ? WHERE id = ?");
        $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $outlook_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("月末見込みの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

function reflectToResult($outlook_id, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $stmt = $dbh->prepare("SELECT year, month FROM monthly_outlook WHERE id = ?");
    $stmt->execute([$outlook_id]);
    $outlook = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$outlook) {
        throw new Exception("outlook_id が不正です。");
    }

    $year = $outlook['year'];
    $month = $outlook['month'];

    $dbh->beginTransaction();

    try {
        // 既存 result を削除
        $stmt = $dbh->prepare("DELETE FROM monthly_result WHERE year = ? AND month = ?");
        $stmt->execute([$year, $month]);

        // 時間・賃率データを挿入
        $stmt = $dbh->prepare("INSERT INTO monthly_result (year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate)
                               SELECT year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate
                               FROM monthly_outlook WHERE id = ?");
        $stmt->execute([$outlook_id]);

        $result_id = $dbh->lastInsertId();

        // 明細をコピー
        $stmt = $dbh->prepare("INSERT INTO monthly_result_details (result_id, detail_id, amount)
                               SELECT ?, detail_id, amount FROM monthly_outlook_details WHERE outlook_id = ?");
        $stmt->execute([$result_id, $outlook_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("概算実績への反映中にエラー: " . $e->getMessage());
    }
}

function confirmMonthlyOutlook($data, $dbh = null)
{
    updateMonthlyOutlook($data, $dbh);
    reflectToResult($data['outlook_id'], $dbh);
}
