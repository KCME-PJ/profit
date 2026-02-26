<?php
require_once '../includes/database.php';
require_once '../includes/result_ui_functions.php';

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

if ($requestOfficeId === 'all' || $requestOfficeId === '0') {
    // 明示的に全社が指定された場合は、権限に関わらず全社（null）として扱う
    $targetOfficeId = null;
} elseif ($requestOfficeId !== null) {
    // 明示的に特定の営業所が指定された場合
    $targetOfficeId = (int)$requestOfficeId;
} elseif ($userRole === 'manager' && $userOfficeId) {
    // 指定がない（初期ロードなど）場合で、Managerなら自分の営業所
    $targetOfficeId = (int)$userOfficeId;
}

if ($year > 0) {
    $dbh = getDb();
    // 関数を呼び出す（第3引数にターゲット営業所IDを渡す）
    $status = getResultStatusByYear($year, $dbh, $targetOfficeId);
    echo json_encode($status);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
}
