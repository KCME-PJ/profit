<?php
require_once '../includes/database.php';
require_once '../includes/plan_ui_functions.php';

// セッションが開始されていなければ開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$year = (int)($_GET['year'] ?? 0);

// 現在のユーザー情報を取得
$userRole = $_SESSION['role'] ?? 'viewer';
$userOfficeId = $_SESSION['office_id'] ?? null;
$requestOfficeId = isset($_GET['office_id']) ? $_GET['office_id'] : null;

// 判定に使うターゲット営業所IDを決定
$targetOfficeId = null;

if ($requestOfficeId && $requestOfficeId !== 'all' && $requestOfficeId != 0) {
    // 明示的に指定された場合
    $targetOfficeId = (int)$requestOfficeId;
} elseif ($userRole === 'manager' && $userOfficeId) {
    // Managerなら自分の営業所
    $targetOfficeId = (int)$userOfficeId;
}

if ($year > 0) {
    $dbh = getDb();
    // 関数を呼び出す（第3引数にターゲット営業所IDを渡す）
    $status = getPlanStatusByYear($year, $dbh, $targetOfficeId);
    echo json_encode($status);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
}
