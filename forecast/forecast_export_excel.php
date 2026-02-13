<?php
require_once '../vendor/autoload.php';
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;

if (!$year || !$month) {
    header("Location: forecast_edit.php?error=" . urlencode("年度と月を指定してください。"));
    exit;
}

function redirectWithError($msg, $year, $month)
{
    $safeMsg = urlencode($msg);
    header("Location: forecast_edit.php?year={$year}&month={$month}&error={$safeMsg}");
    exit;
}

$dbh = getDb();

// Forecastデータチェック
$queryStatus = "SELECT id, status, hourly_rate FROM monthly_forecast WHERE year = :year AND month = :month";
$stmt = $dbh->prepare($queryStatus);
$stmt->execute([':year' => $year, ':month' => $month]);
$forecastData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$forecastData) {
    redirectWithError("【{$year}年度 {$month}月】の見通しは未登録です。", $year, $month);
}

$monthlyForecastId = $forecastData['id'];
$commonHourlyRate = (float)($forecastData['hourly_rate'] ?? 0);

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
    FROM monthly_forecast_details d 
    JOIN details det ON d.detail_id = det.id
    JOIN accounts a ON det.account_id = a.id
    WHERE d.forecast_id = :monthly_forecast_id 
    GROUP BY det.office_id, a.id";
$stmt = $dbh->prepare($queryExpense);
$stmt->execute([':monthly_forecast_id' => $monthlyForecastId]);
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
    FROM monthly_forecast_revenues r
    JOIN revenue_items i ON r.revenue_item_id = i.id
    JOIN revenue_categories c ON i.revenue_category_id = c.id
    WHERE r.forecast_id = :monthly_forecast_id
    GROUP BY i.office_id, c.id";
$stmt = $dbh->prepare($queryRevenue);
$stmt->execute([':monthly_forecast_id' => $monthlyForecastId]);
$revenueDataRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$revenueData = [];
foreach ($revenueDataRaw as $row) {
    // revenue_itemsにoffice_idがある前提 (NULLなら共通=0扱いや別途処理が必要だが、ここではi.office_idを使用)
    // ※ forecast_edit.phpでは items.office_id を見ていますが、集計単位が営業所ごとなら
    //    本来は revenue_items.office_id = target_office_id のものだけ集計すべきです。
    //    ここでは単純に itemに紐づくoffice_id でグルーピングします。
    //    (共通項目 office_id IS NULL はどう扱うか？ → 現状のロジックだと office_id=0 か NULL になる)
    //    今回は「営業所ごと」のシートを作るため、office_id が一致するもの、または共通(NULL)を表示するか検討必要ですが、
    //    既存ロジックに合わせて「紐づくoffice_id」をキーにします。
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
        FROM monthly_forecast_time 
        WHERE monthly_forecast_id = :monthly_forecast_id AND office_id = :office_id";
    $stmtTime = $dbh->prepare($queryTime);
    $stmtTime->execute([':monthly_forecast_id' => $monthlyForecastId, ':office_id' => $officeId]);
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

    if ($forecastData['status'] === 'draft') {
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

    // office_id=0(共通)の経費も含めるべきか？ 現状は $officeId のみ参照
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

    // office_id=0(共通)の収入も含めるべきか？
    // forecast_edit.phpでは共通(NULL)も表示しているが、ここでは一旦当該officeIdのみ
    // ※必要に応じて「共通」データの加算ロジックを追加してください
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

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '差引収益'); // 追加
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $grossProfit)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '労務費');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $laborCost)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '総合計');
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $totalCost)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');
    $currentRowTime++;

    $sheet->setCellValue("{$colTimeName}{$currentRowTime}", '税引前利益'); // 追加
    $sheet->setCellValue("{$colTimeVal}{$currentRowTime}", $preTaxProfit)->getStyle("{$colTimeVal}{$currentRowTime}")->getNumberFormat()->setFormatCode('#,##0');


    // 列幅調整
    foreach ([$colExpName, $colExpVal, $colRevName, $colRevVal, $colTimeName, $colTimeVal] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=forecast_summary_{$year}_{$month}.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
