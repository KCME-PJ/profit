<?php
require_once '../includes/database.php';

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
    $fulltime_count = $data['fulltime_count'] ?? 0;
    $contract_count = $data['contract_count'] ?? 0;
    $dispatch_count = $data['dispatch_count'] ?? 0;

    if (!$result_id) {
        throw new Exception('result_id が存在しません。');
    }

    // ステータス確認（fixedならエラー）
    $stmt = $dbh->prepare("SELECT status FROM monthly_result WHERE id = ?");
    $stmt->execute([$result_id]);
    $status = $stmt->fetchColumn();

    if ($status === 'fixed') {
        throw new Exception("この概算実績はすでに確定済みで、編集できません。");
    }

    try {
        $dbh->beginTransaction();

        // 勘定科目詳細の更新・挿入・削除
        foreach ($amounts as $detail_id => $amount) {
            if ($amount === "" || $amount === null) {
                $stmt = $dbh->prepare("DELETE FROM monthly_result_details WHERE result_id = ? AND detail_id = ?");
                $stmt->execute([$result_id, $detail_id]);
            } else {
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

        // 時間・賃率・人数などの更新
        $stmt = $dbh->prepare("UPDATE monthly_result SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ? WHERE id = ?");
        $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $fulltime_count, $contract_count, $dispatch_count, $result_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("概算実績の更新中にエラーが発生しました: " . $e->getMessage());
    }
}

function confirmMonthlyResult($data, $dbh = null)
{
    updateMonthlyResult($data, $dbh);

    if (!$dbh) {
        $dbh = getDb();
    }

    try {
        $stmt = $dbh->prepare("UPDATE monthly_result SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$data['result_id']]);
    } catch (Exception $e) {
        throw new Exception("概算実績の確定中にエラーが発生しました: " . $e->getMessage());
    }
}
