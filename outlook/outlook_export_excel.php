<?php
require_once '../vendor/autoload.php';
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;

if (!$year || !$month) {
    header("Location: outlook_edit.php?error=" . urlencode("年度と月を指定してください。"));
    exit;
}

function redirectWithError($msg, $year, $month)
{
    $safeMsg = urlencode($msg);
    header("Location: outlook_edit.php?year={$year}&month={$month}&error={$safeMsg}");
    exit;
}

$dbh = getDb();

// Outlookデータチェック
$queryStatus = "SELECT id, status, hourly_rate FROM monthly_outlook WHERE year = :year AND month = :month";
$stmt = $dbh->prepare($queryStatus);
$stmt->execute([':year' => $year, ':month' => $month]);
$outlookData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$outlookData) {
    redirectWithError("【{$year}年度 {$month}月】の月末見込みは未登録です。", $year, $month);
}

$monthlyOutlookId = $outlookData['id'];
$commonHourlyRate = (float)($outlookData['hourly_rate'] ?? 0);

// 営業所
$queryOffices = "SELECT id, name FROM offices ORDER BY id";
$stmt = $dbh->prepare($queryOffices);
$stmt->execute();
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$offices) {
    redirectWithError("営業所データが見つかりません。", $year, $month);
}

// ---------------------------------------------
// マスタ取得 (sort_order優先)
// ---------------------------------------------
// 勘定科目
$stmtAccounts = $dbh->query("SELECT id, name FROM accounts ORDER BY sort_order ASC, id ASC");
$accountsList = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

// 収入カテゴリ
$stmtRevCats = $dbh->query("SELECT id, name FROM revenue_categories ORDER BY sort_order ASC, id ASC");
$revenueCategoriesList = $stmtRevCats->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------
// データ集計取得
// ---------------------------------------------
// 経費集計 (office_id, account_id 単位)
$queryExpense = "
    SELECT 
        det.office_id, 
        a.id AS account_id, 
        SUM(d.amount) AS total
    FROM monthly_outlook_details d 
    JOIN details det ON d.detail_id = det.id
    JOIN accounts a ON det.account_id = a.id
    WHERE d.outlook_id = :monthly_outlook_id 
    GROUP BY det.office_id, a.id";
$stmt = $dbh->prepare($queryExpense);
$stmt->execute([':monthly_outlook_id' => $monthlyOutlookId]);
$expenseDataRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$expenseData = [];
foreach ($expenseDataRaw as $row) {
    $expenseData[$row['office_id']][$row['account_id']] = $row['total'];
}

// 収入集計 (office_id, category_id 単位)
// revenue_items -> revenue_categories
$queryRevenue = "
    SELECT 
        i.office_id, 
        c.id AS category_id, 
        SUM(r.amount) AS total
    FROM monthly_outlook_revenues r
    JOIN revenue_items i ON r.revenue_item_id = i.id
    JOIN revenue_categories c ON i.revenue_category_id = c.id
    WHERE r.outlook_id = :monthly_outlook_id
    GROUP BY i.office_id, c.id";
$stmt = $dbh->prepare($queryRevenue);
$stmt->execute([':monthly_outlook_id' => $monthlyOutlookId]);
$revenueDataRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$revenueData = [];
foreach ($revenueDataRaw as $row) {
    $oId = $row['office_id'] ?: 0; // NULLなら0(共通)
    $revenueData[$oId][$row['category_id']] = $row['total'];
}

// =============================================
// Excel 出力
// =============================================
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

foreach ($offices as $office) {
    $officeId = $office['id'];
    $officeName = $office['name'];

    // 時間データ
    $queryTime = "
        SELECT 
            standard_hours, overtime_hours, transferred_hours, 
            fulltime_count, contract_count, dispatch_count
        FROM monthly_outlook_time 
        WHERE monthly_outlook_id = :monthly_outlook_id AND office_id = :office_id";
    $stmtTime = $dbh->prepare($queryTime);
    $stmtTime->execute([':monthly_outlook_id' => $monthlyOutlookId, ':office_id' => $officeId]);
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

    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($officeName);

    // 基本情報
    $sheet->setCellValue("A1", "{$year}年度");
    $sheet->setCellValue("B1", "{$month}月");
    $sheet->setCellValue("A2", "営業所名");
    $sheet->setCellValue("B2", $officeName);

    if ($outlookData['status'] === 'draft') {
        $sheet->setCellValue("D1", "【注意】未確定 (Draft)");
        $sheet->getStyle('D1')->getFont()->getColor()->setARGB('FFFF0000');
    }

    // 列定義
    $colExpName = 'A';
    $colExpVal = 'B';
    $colRevName = 'D';
    $colRevVal = 'E';
    $colTimeName = 'G';
    $colTimeVal = 'H';

    // ヘッダー
    $headerRow = 4;
    $sheet->setCellValue("{$colExpName}{$headerRow}", "経費");
    $sheet->setCellValue("{$colRevName}{$headerRow}", "収入");
    $sheet->getStyle("{$colExpName}{$headerRow}")->getFont()->setBold(true);
    $sheet->getStyle("{$colRevName}{$headerRow}")->getFont()->setBold(true);

    // -----------------------------------------
    // 左側：経費リスト (A, B列)
    // -----------------------------------------
    $startRow = 5;
    $currentRowExp = $startRow;
    $expenseTotal = 0;
    $officeExpense = $expenseData[$officeId] ?? [];

    foreach ($accountsList as $acc) {
        $val = (float)($officeExpense[$acc['id']] ?? 0);
        $sheet->setCellValue("{$colExpName}{$currentRowExp}", $acc['name']);
        $sheet->setCellValue("{$colExpVal}{$currentRowExp}", $val)->getStyle("{$colExpVal}{$currentRowExp}")->getNumberFormat()->setFormatCode('#,##0');
        $expenseTotal += $val;
        $currentRowExp++;
    }
    // 部内共通費
    $sheet->setCellValue("{$colExpName}{$currentRowExp}", "部内共通費");
    $sheet->setCellValue("{$colExpVal}{$currentRowExp}", 0)->getStyle("{$colExpVal}{$currentRowExp}")->getNumberFormat()->setFormatCode('#,##0');

    // -----------------------------------------
    // 中央：収入リスト (D, E列)
    // -----------------------------------------
    $currentRowRev = $startRow;
    $revenueTotal = 0;
    $officeRevenue = $revenueData[$officeId] ?? [];

    foreach ($revenueCategoriesList as $cat) {
        $val = (float)($officeRevenue[$cat['id']] ?? 0);
        $sheet->setCellValue("{$colRevName}{$currentRowRev}", $cat['name']);
        $sheet->setCellValue("{$colRevVal}{$currentRowRev}", $val)->getStyle("{$colRevVal}{$currentRowRev}")->getNumberFormat()->setFormatCode('#,##0');
        $revenueTotal += $val;
        $currentRowRev++;
    }

    // -----------------------------------------
    // 右側：時間管理・合計 (G, H列)
    // -----------------------------------------
    // 開始行はヘッダーと同じ4行目から
    $currentRowTime = 4;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "定時間");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['standard_hours'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "残業時間");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['overtime_hours'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "部内共通時間");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", 0)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "振替時間");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['transferred_hours'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('0.00');
    $currentRowTime++;

    $currentRowTime++; // 空行

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "正社員");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (int)$timeData['fulltime_count']);
    $currentRowTime++;
    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "契約社員");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (int)$timeData['contract_count']);
    $currentRowTime++;
    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "派遣社員");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (int)$timeData['dispatch_count']);
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", "賃率");
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", (float)$timeData['hourly_rate'])->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $currentRowTime++; // 空行

    // 計算
    $totalHours = (float)$timeData['standard_hours'] + (float)$timeData['overtime_hours'] + (float)$timeData['transferred_hours'];
    $laborCost = round($totalHours * (float)$timeData['hourly_rate']);
    $totalCost = $expenseTotal + $laborCost;     // 総合計
    $grossProfit = $revenueTotal - $expenseTotal; // 差引収益
    $preTaxProfit = $revenueTotal - $totalCost;   // 税引前利益 (収入 - 総合計)

    // 合計ブロック出力
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


    // 列幅調整
    foreach ([$colExpName, $colExpVal, $colRevName, $colRevVal, $colTimeName, $colTimeVal] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=outlook_summary_{$year}_{$month}.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
