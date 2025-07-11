<?php
require_once '../includes/database.php';

header('Content-Type: application/json');

try {
    $dbh = getDb();

    // パラメータの取得とバリデーション
    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
    $month = isset($_GET['month']) ? (int)$_GET['month'] : null;

    if (!$year || !$month) {
        throw new Exception("パラメータが不足しています。");
    }

    // 概算実績（本体）データの取得
    $stmt = $dbh->prepare("
        SELECT * 
        FROM monthly_outlook 
        WHERE year = :year AND month = :month
        LIMIT 1
    ");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $outlook = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$outlook) {
        // データが存在しない場合は初期値で返す
        echo json_encode([
            'outlook_id' => 0,
            'standard_hours' => 0,
            'overtime_hours' => 0,
            'transferred_hours' => 0,
            'hourly_rate' => 0,
            'details' => []
        ]);
        exit;
    }

    // 詳細データ（detail_id ⇒ amount）の取得
    $stmt = $dbh->prepare("
        SELECT detail_id, amount
        FROM monthly_outlook_details
        WHERE outlook_id = :outlook_id
    ");
    $stmt->execute(['outlook_id' => $outlook['id']]);
    $detailsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 配列を detail_id => amount の形式に整形
    $details = array_column($detailsData, 'amount', 'detail_id');

    // JSONとして返す
    echo json_encode([
        'outlook_id' => (int)$outlook['id'],
        'standard_hours' => (float)$outlook['standard_hours'],
        'overtime_hours' => (float)$outlook['overtime_hours'],
        'transferred_hours' => (float)$outlook['transferred_hours'],
        'hourly_rate' => (float)$outlook['hourly_rate'],
        'details' => $details
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
