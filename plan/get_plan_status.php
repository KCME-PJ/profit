<?php
require_once '../includes/database.php';
require_once '../includes/plan_ui_functions.php';

header('Content-Type: application/json');

$year = (int)($_GET['year'] ?? 0);

if ($year > 0) {
    $dbh = getDb();
    // 呼び出す関数を getPlanStatusByYear に変更
    $status = getPlanStatusByYear($year, $dbh);
    echo json_encode($status);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
}
