<?php
require_once '../includes/database.php';

/**
 * 概算実績（result）の更新処理
 * 明細（amounts）＋時間・賃率を含む
 */
function updateMonthlyResult($data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $result_id = $data['result_id'] ?? null;
    $amounts = $data['amounts'] ?? [];
    $standard_hours = $data['standard_hours'] ?? 0;
    $overtime_hours = $data['overtime_hours'] ?? 0;
    $transferred_hours = $data['transferred_hours'] ?? 0;
    $hourly_rate = $data['hourly_rate'] ?? 0;

    if (!$result_id) {
        throw new Exception('result_id が存在しません。');
    }

    try {
        $dbh->beginTransaction();

        // 明細（各 detail_id に対する金額）の更新・削除・追加
        foreach ($amounts as $detail_id => $amount) {
            if ($amount === "" || $amount === null) {
                $stmt = $dbh->prepare("DELETE FROM monthly_result_details WHERE result_id = ? AND detail_id = ?");
                $stmt->execute([$result_id, $detail_id]);
            } else {
                // 既存確認
                $stmt = $dbh->prepare("SELECT id FROM monthly_result_details WHERE result_id = ? AND detail_id = ?");
                $stmt->execute([$result_id, $detail_id]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $stmt = $dbh->prepare("UPDATE monthly_result_details SET amount = ? WHERE id = ?");
                    $stmt->execute([$amount, $existing]);
                } else {
                    $stmt = $dbh->prepare("INSERT INTO monthly_result_details (result_id, detail_id, amount) VALUES (?, ?, ?)");
                    $stmt->execute([$result_id, $detail_id, $amount]);
                }
            }
        }

        // 時間・賃率の更新
        $stmt = $dbh->prepare("UPDATE monthly_result SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ? WHERE id = ?");
        $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $result_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("概算実績の更新中にエラーが発生しました: " . $e->getMessage());
    }
}
