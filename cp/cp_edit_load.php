<?php
require_once '../includes/database.php';

header('Content-Type: application/json');

if (!isset($_GET['year'], $_GET['month'])) {
    echo json_encode(['error' => '年度と月が指定されていません。']);
    exit;
}

$year = (int)$_GET['year'];
$month = (int)$_GET['month'];

try {
    $dbh = getDb();

    // 時間管理データとステータスを取得
    $timeQuery = "
        SELECT
            mcp.id AS monthly_cp_id,
            mcp.status,
            mcpt.standard_hours,
            mcpt.overtime_hours,
            mcpt.transferred_hours,
            mcpt.hourly_rate,
            mcpt.fulltime_count ,
            mcpt.contract_count,
            mcpt.dispatch_count

        FROM monthly_cp mcp
        LEFT JOIN monthly_cp_time mcpt ON mcp.id = mcpt.monthly_cp_id
        WHERE mcp.year = :year AND mcp.month = :month
        LIMIT 1
    ";
    $timeStmt = $dbh->prepare($timeQuery);
    $timeStmt->execute(['year' => $year, 'month' => $month]);
    $timeData = $timeStmt->fetch(PDO::FETCH_ASSOC);

    // 勘定科目詳細データを取得
    $detailsQuery = "
        SELECT d.id AS detail_id, COALESCE(mcpd.amount, 0) AS amount
        FROM details d
        LEFT JOIN monthly_cp mcp ON mcp.year = :year AND mcp.month = :month
        LEFT JOIN monthly_cp_details mcpd ON d.id = mcpd.detail_id AND mcp.id = mcpd.monthly_cp_id
    ";
    $detailsStmt = $dbh->prepare($detailsQuery);
    $detailsStmt->execute(['year' => $year, 'month' => $month]);
    $detailsData = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'monthly_cp_id' => $timeData['monthly_cp_id'] ?? 0,
        'status' => $timeData['status'] ?? 'draft',
        'standard_hours' => $timeData['standard_hours'] ?? 0,
        'overtime_hours' => $timeData['overtime_hours'] ?? 0,
        'transferred_hours' => $timeData['transferred_hours'] ?? 0,
        'hourly_rate' => $timeData['hourly_rate'] ?? 0,
        'fulltime_count' => $timeData['fulltime_count'] ?? 0,
        'contract_count' => $timeData['contract_count'] ?? 0,
        'dispatch_count' => $timeData['dispatch_count'] ?? 0,
        'details' => array_column($detailsData, 'amount', 'detail_id'),
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
