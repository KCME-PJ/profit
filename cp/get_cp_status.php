<?php
require_once '../includes/database.php';
require_once '../includes/cp_ui_functions.php';

$year = (int)($_GET['year'] ?? 0);

if ($year > 0) {
    $dbh = getDb();
    $status = getCpFixedStatusByYear($year, $dbh);
    $completeStatus = [];
    for ($i = 1; $i <= 12; $i++) {
        $completeStatus[$i] = $status[$i] ?? 'none';
    }
    header('Content-Type: application/json');
    echo json_encode($completeStatus);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
}
