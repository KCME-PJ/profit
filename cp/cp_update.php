<?php
require_once '../includes/database.php';

// データベース接続
$dbh = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monthly_cp_id = $_POST['monthly_cp_id'] ?? null;
    $year = $_POST['year'] ?? null;
    $month = $_POST['month'] ?? null;
    $amounts = $_POST['amounts'] ?? [];
    $standard_hours = $_POST['standard_hours'] ?? null;
    $overtime_hours = $_POST['overtime_hours'] ?? null;
    $transferred_hours = $_POST['transferred_hours'] ?? null;
    $hourly_rate = $_POST['hourly_rate'] ?? null;

    if (!$monthly_cp_id) {
        exit('エラー: 対象の monthly_cp_id が見つかりません。');
    }

    try {
        $dbh->beginTransaction(); // トランザクション開始

        /**
         * monthly_cp_details の更新・追加・削除処理
         */
        foreach ($amounts as $detail_id => $amount) {
            if ($amount === "" || $amount === null) {
                // 金額がNULLまたは空欄の場合、削除処理
                $stmt = $dbh->prepare("DELETE FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ?");
                $stmt->execute([$monthly_cp_id, $detail_id]);
            } else {
                // 既存レコードがあるか確認
                $stmt = $dbh->prepare("SELECT id FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ?");
                $stmt->execute([$monthly_cp_id, $detail_id]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    // 更新
                    $stmt = $dbh->prepare("UPDATE monthly_cp_details SET amount = ? WHERE id = ?");
                    $stmt->execute([$amount, $existing]);
                } else {
                    // 新規追加
                    $stmt = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount) VALUES (?, ?, ?)");
                    $stmt->execute([$monthly_cp_id, $detail_id, $amount]);
                }
            }
        }

        /**
         * monthly_cp_time の更新・追加（削除しない）
         */
        $stmt = $dbh->prepare("SELECT id FROM monthly_cp_time WHERE monthly_cp_id = ?");
        $stmt->execute([$monthly_cp_id]);
        $existing_time = $stmt->fetchColumn();

        if ($existing_time) {
            // 更新
            $stmt = $dbh->prepare("UPDATE monthly_cp_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ? WHERE id = ?");
            $stmt->execute([$standard_hours, $overtime_hours, $transferred_hours, $hourly_rate, $existing_time]);
        } else {
            // 新規追加
            $stmt = $dbh->prepare("INSERT INTO monthly_cp_time (monthly_cp_id, standard_hours, overtime_hours, transferred_hours, hourly_rate) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$monthly_cp_id, $standard_hours, $overtime_hours, $transferred_hours, $hourly_rate]);
        }

        $dbh->commit(); // 正常ならコミット
        echo "更新が完了しました。";
    } catch (Exception $e) {
        $dbh->rollBack(); // エラー時はロールバック
        exit("エラー: " . $e->getMessage());
    }
} else {
    exit("不正なアクセスです。");
}
