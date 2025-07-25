<?php
require_once '../includes/database.php';

header('Content-Type: application/json');

try {
    $dbh = getDb();

    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
    $month = isset($_GET['month']) ? (int)$_GET['month'] : null;

    if (!$year || !$month) {
        http_response_code(400);
        throw new Exception("パラメータが不足しています。");
    }

    // 月次予定データの取得
    $stmt = $dbh->prepare("
        SELECT * 
        FROM monthly_plan 
        WHERE year = :year AND month = :month
        LIMIT 1
    ");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        echo json_encode([
            'plan_id' => 0,
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

    // 明細データの取得
    $stmt = $dbh->prepare("
        SELECT detail_id, amount
        FROM monthly_plan_details
        WHERE plan_id = :plan_id
    ");
    $stmt->execute(['plan_id' => $plan['id']]);
    $detailsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $details = array_column($detailsData, 'amount', 'detail_id');

    echo json_encode([
        'plan_id' => (int)$plan['id'],
        'standard_hours' => (float)$plan['standard_hours'],
        'overtime_hours' => (float)$plan['overtime_hours'],
        'transferred_hours' => (float)$plan['transferred_hours'],
        'hourly_rate' => (float)$plan['hourly_rate'],
        'fulltime_count' => (int)$plan['fulltime_count'],
        'contract_count' => (int)$plan['contract_count'],
        'dispatch_count' => (int)$plan['dispatch_count'],
        'details' => $details
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
