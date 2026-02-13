<?php
require_once '../vendor/autoload.php';
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : null;
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;

if (!$year || !$month) {
    header("Location: plan_edit.php?error=" . urlencode("年度と月を指定してください。"));
    exit;
}

function redirectWithError($msg, $year, $month)
{
    $safeMsg = urlencode($msg);
    header("Location: plan_edit.php?year={$year}&month={$month}&error={$safeMsg}");
    exit;
}

$dbh = getDb();

$queryStatus = "SELECT id, status, hourly_rate FROM monthly_plan WHERE year = :year AND month = :month";
$stmt = $dbh->prepare($queryStatus);
$stmt->execute([':year' => $year, ':month' => $month]);
$planData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$planData) {
    redirectWithError("【{$year}年度 {$month}月】の予定は未登録です。", $year, $month);
}

$monthlyPlanId = $planData['id'];
$commonHourlyRate = (float)($planData['hourly_rate'] ?? 0);

$queryOffices = "SELECT id, name FROM offices ORDER BY id";
$stmt = $dbh->prepare($queryOffices);
$stmt->execute();
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$offices) {
    redirectWithError("営業所データが見つかりません。", $year, $month);
}

// =============================================
// Excel 出力
// =============================================
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

foreach ($offices as $office) {
    $officeId = $office['id'];
    $officeName = $office['name'];

    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($officeName);

    // ヘッダー
    $sheet->setCellValue("A1", "{$year}年度");
    $sheet->setCellValue("B1", "{$month}月");
    $sheet->setCellValue("A2", "営業所名");
    $sheet->setCellValue("B2", $officeName);

    if ($planData['status'] === 'draft') {
        $sheet->setCellValue("D1", "【注意】未確定 (Draft)");
        $sheet->getStyle('D1')->getFont()->getColor()->setARGB('FFFF0000');
    }

    // 列定義
    $colExpAcc = 'A';
    $colExpDet = 'B';
    $colExpVal = 'C';
    $colRevCat = 'E';
    $colRevItm = 'F';
    $colRevVal = 'G';
    $colTimeName = 'I';
    $colTimeVal = 'J';

    // 見出し行 (4行目)
    $headerRow = 4;
    $sheet->setCellValue("{$colExpAcc}{$headerRow}", "経費（勘定科目）");
    $sheet->setCellValue("{$colExpDet}{$headerRow}", "詳細項目");
    $sheet->setCellValue("{$colExpVal}{$headerRow}", "金額");

    $sheet->setCellValue("{$colRevCat}{$headerRow}", "収入（カテゴリ）");
    $sheet->setCellValue("{$colRevItm}{$headerRow}", "収入項目");
    $sheet->setCellValue("{$colRevVal}{$headerRow}", "金額");

    $sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFont()->setBold(true);

    // -----------------------------------------
    // 左側：経費詳細 (A, B, C列)
    // -----------------------------------------
    $queryExp = "
        SELECT 
            a.name AS account_name,
            det.name AS detail_name,
            d.amount
        FROM monthly_plan_details d
        JOIN details det ON d.detail_id = det.id
        JOIN accounts a ON det.account_id = a.id
        WHERE 
            d.plan_id = :monthly_plan_id 
            AND det.office_id = :office_id 
            AND d.amount != 0 
        ORDER BY a.sort_order ASC, a.id ASC, det.sort_order ASC, det.id ASC";
    $stmtExp = $dbh->prepare($queryExp);
    $stmtExp->execute([':monthly_plan_id' => $monthlyPlanId, ':office_id' => $officeId]);
    $expRows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    $startRow = 5;
    $currentRowExp = $startRow;
    $expenseTotal = 0;

    foreach ($expRows as $row) {
        $val = (float)$row['amount'];
        $sheet->setCellValue("{$colExpAcc}{$currentRowExp}", $row['account_name']);
        $sheet->setCellValue("{$colExpDet}{$currentRowExp}", $row['detail_name']);
        $sheet->setCellValue("{$colExpVal}{$currentRowExp}", $val)->getStyle("{$colExpVal}{$currentRowExp}")->getNumberFormat()->setFormatCode('#,##0');
        $expenseTotal += $val;
        $currentRowExp++;
    }

    // -----------------------------------------
    // 中央：収入詳細 (E, F, G列)
    // -----------------------------------------
    $queryRev = "
        SELECT 
            c.name AS category_name,
            i.name AS item_name,
            r.amount
        FROM monthly_plan_revenues r
        JOIN revenue_items i ON r.revenue_item_id = i.id
        JOIN revenue_categories c ON i.revenue_category_id = c.id
        WHERE 
            r.plan_id = :monthly_plan_id
            AND i.office_id = :office_id
            AND r.amount != 0
        ORDER BY c.sort_order ASC, c.id ASC, i.sort_order ASC, i.id ASC";
    $stmtRev = $dbh->prepare($queryRev);
    $stmtRev->execute([':monthly_plan_id' => $monthlyPlanId, ':office_id' => $officeId]);
    $revRows = $stmtRev->fetchAll(PDO::FETCH_ASSOC);

    $currentRowRev = $startRow;
    $revenueTotal = 0;

    foreach ($revRows as $row) {
        $val = (float)$row['amount'];
        $sheet->setCellValue("{$colRevCat}{$currentRowRev}", $row['category_name']);
        $sheet->setCellValue("{$colRevItm}{$currentRowRev}", $row['item_name']);
        $sheet->setCellValue("{$colRevVal}{$currentRowRev}", $val)->getStyle("{$colRevVal}{$currentRowRev}")->getNumberFormat()->setFormatCode('#,##0');
        $revenueTotal += $val;
        $currentRowRev++;
    }

    // -----------------------------------------
    // 右側：時間管理・合計 (I, J列)
    // -----------------------------------------
    $queryTime = "
        SELECT 
            standard_hours, overtime_hours, transferred_hours, 
            fulltime_count, contract_count, dispatch_count
        FROM monthly_plan_time 
        WHERE monthly_plan_id = :monthly_plan_id AND office_id = :office_id";
    $stmtTime = $dbh->prepare($queryTime);
    $stmtTime->execute([':monthly_plan_id' => $monthlyPlanId, ':office_id' => $officeId]);
    $timeData = $stmtTime->fetch(PDO::FETCH_ASSOC);
    $timeData = $timeData ?: [
        'standard_hours' => 0,
        'overtime_hours' => 0,
        'transferred_hours' => 0,
        'fulltime_count' => 0,
        'contract_count' => 0,
        'dispatch_count' => 0
    ];
    $timeData['hourly_rate'] = $commonHourlyRate;

    // 時間データの開始行は経費や収入の行数に関わらず上(5行目)から詰める
    $currentRowTime = 5;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '定時間');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['standard_hours'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '残業時間');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['overtime_hours'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '振替時間');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['transferred_hours'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $currentRowTime++; // 空行

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '正社員');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (int)$timeData['fulltime_count']);
    $currentRowTime++;
    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '契約社員');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (int)$timeData['contract_count']);
    $currentRowTime++;
    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '派遣社員');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (int)$timeData['dispatch_count']);
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '賃率');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['hourly_rate'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $currentRowTime++; // 空行

    // 計算
    $totalHours = (float)$timeData['standard_hours'] + (float)$timeData['overtime_hours'] + (float)$timeData['transferred_hours'];
    $laborCost = round($totalHours * (float)$timeData['hourly_rate']);
    $totalCost = $expenseTotal + $laborCost;
    $grossProfit = $revenueTotal - $expenseTotal;
    $preTaxProfit = $revenueTotal - $totalCost;

    // 合計ブロック
    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '総時間');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $totalHours)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '収入合計');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $revenueTotal)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '経費合計');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $expenseTotal)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '差引収益');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $grossProfit)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '労務費');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $laborCost)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '総合計');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $totalCost)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '税引前利益');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $preTaxProfit)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');

    // 列幅自動調整
    foreach ([$colExpAcc, $colExpDet, $colExpVal, $colRevCat, $colRevItm, $colRevVal, $colTimeName, $colTimeVal] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// ダウンロード用ヘッダー
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=plan_details_{$year}_{$month}.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
