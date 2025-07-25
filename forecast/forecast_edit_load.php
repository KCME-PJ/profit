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

    // 見通し本体（月次時間＋単価）の取得（1件のみ）
    $stmt = $dbh->prepare("
        SELECT * 
        FROM monthly_forecast 
        WHERE year = :year AND month = :month
        LIMIT 1
    ");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $forecast = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$forecast) {
        // データが存在しない場合は初期値で返す
        echo json_encode([
            'forecast_id' => 0,
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

    // 詳細データ（detail_id ⇒ amount）の取得
    $stmt = $dbh->prepare("
        SELECT detail_id, amount
        FROM monthly_forecast_details
        WHERE forecast_id = :forecast_id
    ");
    $stmt->execute(['forecast_id' => $forecast['id']]);
    $detailsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 配列を detail_id => amount の形式に整形
    $details = array_column($detailsData, 'amount', 'detail_id');

    // JSONとして返す
    echo json_encode([
        'forecast_id' => (int)$forecast['id'],
        'standard_hours' => (float)$forecast['standard_hours'],
        'overtime_hours' => (float)$forecast['overtime_hours'],
        'transferred_hours' => (float)$forecast['transferred_hours'],
        'hourly_rate' => (float)$forecast['hourly_rate'],
        'fulltime_count' => (int)$forecast['fulltime_count'],
        'contract_count' => (int)$forecast['contract_count'],
        'dispatch_count' => (int)$forecast['dispatch_count'],
        'details' => $details
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
