<?php
require_once '../includes/database.php';

function updateMonthlyPlan($data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $plan_id = $data['plan_id'] ?? null;
    $amounts = $data['amounts'] ?? [];
    $standard_hours = $data['standard_hours'] ?? 0;
    $overtime_hours = $data['overtime_hours'] ?? 0;
    $transferred_hours = $data['transferred_hours'] ?? 0;
    $hourly_rate = $data['hourly_rate'] ?? 0;

    if (!$plan_id) {
        throw new Exception('plan_id が存在しません。');
    }

    // ステータス確認（fixedならエラー）
    $stmt = $dbh->prepare("SELECT status FROM monthly_plan WHERE id = ?");
    $stmt->execute([$plan_id]);
    $status = $stmt->fetchColumn();
    if ($status === 'fixed') {
        throw new Exception("この予定はすでに確定済みで、編集できません。");
    }

    try {
        $dbh->beginTransaction();

        // 勘定科目詳細の更新・挿入・削除
        foreach ($amounts as $detail_id => $amount) {
            if ($amount === "" || $amount === null) {
                $stmt = $dbh->prepare("DELETE FROM monthly_plan_details WHERE plan_id = ? AND detail_id = ?");
                $stmt->execute([$plan_id, $detail_id]);
            } else {
                $stmt = $dbh->prepare("SELECT id FROM monthly_plan_details WHERE plan_id = ? AND detail_id = ?");
                $stmt->execute([$plan_id, $detail_id]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $stmt = $dbh->prepare("UPDATE monthly_plan_details SET amount = ? WHERE id = ?");
                    $stmt->execute([$amount, $existing]);
                } else {
                    $stmt = $dbh->prepare("INSERT INTO monthly_plan_details (plan_id, detail_id, amount) VALUES (?, ?, ?)");
                    $stmt->execute([$plan_id, $detail_id, $amount]);
                }
            }
        }

        // 時間・賃率の更新・追加
        $stmt = $dbh->prepare("UPDATE monthly_plan SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ? WHERE id = ?");
        $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $plan_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("予定の更新中にエラーが発生しました: " . $e->getMessage());
    }
}

function reflectToOutlook($plan_id, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $stmt = $dbh->prepare("SELECT year, month FROM monthly_plan WHERE id = ?");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        throw new Exception("plan_id が不正です。");
    }

    $year = $plan['year'];
    $month = $plan['month'];

    try {
        $dbh->beginTransaction();

        // 月末見込みテーブルを削除してから再挿入
        $stmt = $dbh->prepare("DELETE FROM monthly_outlook WHERE year = ? AND month = ?");
        $stmt->execute([$year, $month]);

        // mainテーブル挿入
        $stmt = $dbh->prepare("INSERT INTO monthly_outlook (year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate)
                               SELECT year, month, standard_hours, overtime_hours, transferred_hours, hourly_rate
                               FROM monthly_plan WHERE id = ?");
        $stmt->execute([$plan_id]);

        $outlook_id = $dbh->lastInsertId();

        // 予定明細を月末見込み明細にコピー
        $stmt = $dbh->prepare("INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount)
                               SELECT ?, detail_id, amount FROM monthly_plan_details WHERE plan_id = ?");
        $stmt->execute([$outlook_id, $plan_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("月末見込みへの反映中にエラー: " . $e->getMessage());
    }
}

function confirmMonthlyPlan($data, $dbh = null)
{
    updateMonthlyPlan($data, $dbh);
    reflectToOutlook($data['plan_id'], $dbh);

    $plan_id = $data['plan_id'] ?? null;
    if (!$plan_id) {
        throw new Exception("plan_id が存在しません。");
    }

    try {
        $stmt = $dbh->prepare("UPDATE monthly_plan SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$plan_id]);
    } catch (Exception $e) {
        throw new Exception("予定の確定中にエラーが発生しました: " . $e->getMessage());
    }
}
