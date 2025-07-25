<?php
require_once '../includes/database.php';
require_once '../includes/plan_ui_functions.php';

$year = (int)($_GET['year'] ?? 0);

header('Content-Type: application/json');

if ($year > 0) {
    $dbh = getDb();
    $status = getPlanStatusByYear($year, $dbh); // ['1' => 'fixed', '2' => 'draft', ...]
    echo json_encode($status);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
}
