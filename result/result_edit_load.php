<?php
require_once '../includes/database.php';

header('Content-Type: application/json');

try {
    $dbh = getDb();

    // パラメータの取得とバリデーション
    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
    $month = isset($_GET['month']) ? (int)$_GET['month'] : null;

    if (!$year || !$month) {
        http_response_code(400);
        throw new Exception("パラメータが不足しています。");
    }

    // 概算実績データの取得
    $stmt = $dbh->prepare("
        SELECT * 
        FROM monthly_result 
        WHERE year = :year AND month = :month
        LIMIT 1
    ");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        // データが存在しない場合は初期値で返す
        echo json_encode([
            'result_id' => 0,
            'standard_hours' => 0,
            'overtime_hours' => 0,
            'transferred_hours' => 0,
            'hourly_rate' => 0,
            'fulltime_count' => 0,
            'contract_count' => 0,
            'dispatch_count' => 0,
            'details' => []
        ]);
        exit;
    }

    // 詳細データの取得
    $stmt = $dbh->prepare("
        SELECT detail_id, amount
        FROM monthly_result_details
        WHERE result_id = :result_id
    ");
    $stmt->execute(['result_id' => $result['id']]);
    $detailsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 配列を detail_id => amount の形式に整形
    $details = array_column($detailsData, 'amount', 'detail_id');

    // JSONとして返す
    echo json_encode([
        'result_id' => (int)$result['id'],
        'standard_hours' => (float)$result['standard_hours'],
        'overtime_hours' => (float)$result['overtime_hours'],
        'transferred_hours' => (float)$result['transferred_hours'],
        'hourly_rate' => (float)$result['hourly_rate'],
        'fulltime_count' => (int)$result['fulltime_count'],
        'contract_count' => (int)$result['contract_count'],
        'dispatch_count' => (int)$result['dispatch_count'],
        'details' => $details
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
