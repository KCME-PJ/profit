<?php
// セッション管理・認証
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

// エラー出力を抑制し、JSONのみを返す
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    $year = (int)($_GET['year'] ?? 0);
    $userRole = $_SESSION['role'] ?? 'viewer';
    $userOfficeId = $_SESSION['office_id'] ?? null;
    $requestOfficeId = isset($_GET['office_id']) ? $_GET['office_id'] : null;

    // 対象営業所IDの決定
    $targetOfficeId = 0;

    if ($requestOfficeId === 'all' || $requestOfficeId === '0') {
        // 明示的に全社が指定された場合は、権限に関わらず全社（0）として扱う
        $targetOfficeId = 0;
    } elseif ($requestOfficeId !== null) {
        // 明示的に特定の営業所が指定された場合
        $targetOfficeId = (int)$requestOfficeId;
    } elseif ($userRole === 'manager' && $userOfficeId) {
        // 指定がない（初期ロードなど）場合で、Managerなら自分の営業所
        $targetOfficeId = (int)$userOfficeId;
    }

    // パラメータチェック (修正: targetOfficeId <= 0 のチェックを外す)
    if ($year <= 0) {
        echo json_encode(array_fill(1, 12, 'none'));
        exit;
    }

    $dbh = getDb();

    // デフォルトは全て 'none' (未登録)
    $completeStatus = [];
    for ($i = 1; $i <= 12; $i++) {
        $completeStatus[$i] = 'none';
    }

    // -----------------------------------------------------
    // ステータスの取得クエリ
    // -----------------------------------------------------
    if ($targetOfficeId > 0) {
        // A. 特定の営業所: 親と子を結合して子ステータスを取得
        $sql = "
            SELECT 
                m.month, 
                t.status 
            FROM monthly_cp m
            INNER JOIN monthly_cp_time t ON m.id = t.monthly_cp_id
            WHERE m.year = ? 
            AND t.office_id = ?
        ";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([$year, $targetOfficeId]);
    } else {
        // B. 全社 (AdminでAll選択時): 親テーブルのステータスをそのまま取得
        $sql = "SELECT month, status FROM monthly_cp WHERE year = ?";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([$year]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // データがある月だけ status で上書き
    foreach ($rows as $row) {
        $m = (int)$row['month'];
        $completeStatus[$m] = $row['status'] ?: 'draft';
    }

    echo json_encode($completeStatus);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
