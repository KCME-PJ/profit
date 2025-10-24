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

    // 時間管理データ（全営業所）を取得
    $timeQuery = "
        SELECT
            mcp.id AS monthly_cp_id,
            mcp.status,
            mcpt.office_id,
            mcpt.standard_hours,
            mcpt.overtime_hours,
            mcpt.transferred_hours,
            mcpt.hourly_rate,
            mcpt.fulltime_count,
            mcpt.contract_count,
            mcpt.dispatch_count
        FROM monthly_cp mcp
        LEFT JOIN monthly_cp_time mcpt 
            ON mcp.id = mcpt.monthly_cp_id
        WHERE mcp.year = :year AND mcp.month = :month
    ";
    $timeStmt = $dbh->prepare($timeQuery);
    $timeStmt->execute(['year' => $year, 'month' => $month]);
    $timeRows = $timeStmt->fetchAll(PDO::FETCH_ASSOC);

    // office_id をキーにして配列化
    $officeData = [];
    $monthly_cp_id = 0;
    $status = 'draft';
    foreach ($timeRows as $row) {
        $officeId = $row['office_id'] ?? 0;
        if ($officeId) {
            $officeData[$officeId] = [
                'standard_hours' => $row['standard_hours'] ?? 0,
                'overtime_hours' => $row['overtime_hours'] ?? 0,
                'transferred_hours' => $row['transferred_hours'] ?? 0,
                'hourly_rate' => $row['hourly_rate'] ?? 0,
                'fulltime_count' => $row['fulltime_count'] ?? 0,
                'contract_count' => $row['contract_count'] ?? 0,
                'dispatch_count' => $row['dispatch_count'] ?? 0,
            ];
        }
        $monthly_cp_id = $row['monthly_cp_id'] ?? $monthly_cp_id;
        $status = $row['status'] ?? $status;
    }

    // 勘定科目詳細
    $detailsQuery = "
        SELECT d.id AS detail_id, COALESCE(mcpd.amount, 0) AS amount
        FROM details d
        LEFT JOIN monthly_cp mcp 
            ON mcp.year = :year AND mcp.month = :month
        LEFT JOIN monthly_cp_details mcpd 
            ON d.id = mcpd.detail_id AND mcp.id = mcpd.monthly_cp_id
    ";
    $detailsStmt = $dbh->prepare($detailsQuery);
    $detailsStmt->execute(['year' => $year, 'month' => $month]);
    $detailsData = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'monthly_cp_id' => $monthly_cp_id,
        'status' => $status,
        'offices' => $officeData,
        'details' => array_column($detailsData, 'amount', 'detail_id'),
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
