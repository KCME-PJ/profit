<?php
// データベース接続
require_once '../includes/database.php';

try {
    // データベース接続
    $pdo = getDb();

    // トランザクション開始
    $pdo->beginTransaction();

    // フォームデータ取得
    $year = $_POST['year'] ?? null;
    $month = $_POST['month'] ?? null;
    $standardHours = $_POST['standard_hours'] ?? null;
    $overtimeHours = $_POST['overtime_hours'] ?? null;
    $transferredHours = $_POST['transferred_hours'] ?? null;
    $hourlyRate = $_POST['hourly_rate'] ?? null;

    // 勘定科目の詳細データ
    $detailIds = $_POST['detail_ids'] ?? [];
    $amounts = $_POST['amounts'] ?? [];

    // 入力チェック
    if (empty($year) || empty($month)) {
        throw new Exception('年度と月は必須項目です。');
    }

    if (count($detailIds) !== count($amounts)) {
        throw new Exception('詳細IDと金額の数が一致していません。');
    }

    // 1. `monthly_cp`テーブルに既に同じ年度と月が登録されているか確認
    $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM monthly_cp WHERE year = :year AND month = :month');
    $stmtCheck->execute([
        ':year' => $year,
        ':month' => $month,
    ]);
    $existingCount = $stmtCheck->fetchColumn();

    if ($existingCount > 0) {
        // 登録済みの場合は処理を終了
        echo "この年度と月のデータはすでに登録されています。修正用ページから編集してください。";
        exit;
    }

    // 2. `monthly_cp`テーブルに登録
    $stmt = $pdo->prepare('INSERT INTO monthly_cp (year, month) VALUES (:year, :month)');
    $stmt->execute([
        ':year' => $year,
        ':month' => $month,
    ]);

    // 登録した`monthly_cp`のIDを取得
    $monthlyCpId = $pdo->lastInsertId();

    // 3. `monthly_cp_time`テーブルに登録
    $stmtTime = $pdo->prepare('
        INSERT INTO monthly_cp_time (monthly_cp_id, standard_hours, overtime_hours, transferred_hours, hourly_rate) 
        VALUES (:monthly_cp_id, :standard_hours, :overtime_hours, :transferred_hours, :hourly_rate)
    ');

    $stmtTime->execute([
        ':monthly_cp_id' => $monthlyCpId,
        ':standard_hours' => $standardHours,
        ':overtime_hours' => $overtimeHours,
        ':transferred_hours' => $transferredHours,
        ':hourly_rate' => $hourlyRate,
    ]);

    // 4. `monthly_cp_details`テーブルに金額が入力されているものだけ登録
    $stmtDetails = $pdo->prepare('
        INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount) 
        VALUES (:monthly_cp_id, :detail_id, :amount)
    ');

    foreach ($detailIds as $index => $detailId) {
        // 金額が空でない場合のみ登録
        $amount = $amounts[$index] !== '' ? (float)$amounts[$index] : null;

        // 金額がNULLまたは空でない場合にのみ処理
        if ($amount !== null) {
            $stmtDetails->execute([
                ':monthly_cp_id' => $monthlyCpId,
                ':detail_id' => $detailId,
                ':amount' => $amount,
            ]);
        }
    }

    // コミット
    $pdo->commit();

    // 成功メッセージ
    echo "登録が完了しました！";
} catch (Exception $e) {
    // エラー発生時のロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // エラーメッセージを表示
    echo "エラーが発生しました: " . htmlspecialchars($e->getMessage());
}
