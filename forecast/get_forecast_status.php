<?php
require_once '../includes/database.php';
require_once '../includes/forecast_ui_functions.php'; // 共通関数を読み込む

header('Content-Type: application/json');

$year = (int)($_GET['year'] ?? 0);

if ($year > 0) {
    $dbh = getDb();
    // forecast_ui_functions.php で定義された関数を呼び出す
    $status = getForecastStatusByYear($year, $dbh);
    echo json_encode($status);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
}
