<?php
require_once '../vendor/autoload.php'; // PhpSpreadsheet
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 入力チェック
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : null;
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;

if (!$year || !$month) {
    header("Location: forecast_edit.php?error=" . urlencode("年度と月を指定してください。"));
    exit;
}

// エラーリダイレクト用関数
function redirectWithError($msg, $year, $month)
{
    $safeMsg = urlencode($msg);
    header("Location: forecast_edit.php?year={$year}&month={$month}&error={$safeMsg}");
    exit;
}

$dbh = getDb();

// Forecastデータの存在と確定ステータスチェック
// 共通賃率(hourly_rate)もここで取得する
$queryStatus = "SELECT id, status, hourly_rate FROM monthly_forecast WHERE year = :year AND month = :month";
$stmt = $dbh->prepare($queryStatus);
$stmt->execute([':year' => $year, ':month' => $month]);
$forecastData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$forecastData) {
    redirectWithError("【{$year}年度 {$month}月】の見通しは未登録です。", $year, $month);
} elseif ($forecastData['status'] !== 'fixed') {
    redirectWithError("【{$year}年度 {$month}月】の見通しは未確定です。確定後に出力してください。", $year, $month);
}
$monthlyForecastId = $forecastData['id'];
// 親テーブルから共通賃率を取得
$commonHourlyRate = (float)($forecastData['hourly_rate'] ?? 0);

// 営業所の一覧を取得 (officesテーブルを使用)
$queryOffices = "SELECT id, name FROM offices ORDER BY id";
$stmt = $dbh->prepare($queryOffices);
$stmt->execute();
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$offices) {
    redirectWithError("営業所データが見つかりません。", $year, $month);
}

// ===== Excel 出力 =====
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // デフォルトシート削除

// 営業所ごとにシートを作成
foreach ($offices as $office) {
    $officeId = $office['id'];
    $officeName = $office['name'];

    // 新しいシートを作成
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($officeName);

    // ヘッダー情報
    $sheet->setCellValue("A1", "{$year}年度");
    $sheet->setCellValue("B1", "{$month}月");
    $sheet->setCellValue("A2", "営業所名");
    $sheet->setCellValue("B2", $officeName);

    // 営業所ごとの時間・人数データを取得 (Forecastデータのみ)
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
    // 共通賃率を $timeData 配列にマージ
    $timeData['hourly_rate'] = $commonHourlyRate;

    // 営業所ごとの詳細項目ごとの金額取得 (金額 > 0 のみ)
    $queryDetails = "
        SELECT 
            a.id AS account_id,
            a.name AS account_name,
            det.id AS detail_id,
            det.name AS detail_name,
            d.amount
        FROM monthly_forecast_details d
        JOIN details det ON d.detail_id = det.id
        JOIN accounts a ON det.account_id = a.id
        WHERE 
            d.forecast_id = :monthly_forecast_id 
            AND det.office_id = :office_id 
            AND d.amount > 0 
        ORDER BY a.id ASC, det.id ASC
    ";
    $stmtDetails = $dbh->prepare($queryDetails);
    $stmtDetails->execute([
        ':monthly_forecast_id' => $monthlyForecastId,
        ':office_id' => $officeId
    ]);
    $detailRows = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

    // 見出し行
    $headerRow = 4;
    $sheet->setCellValue("A{$headerRow}", "勘定科目");
    $sheet->setCellValue("B{$headerRow}", "詳細項目");
    $sheet->setCellValue("C{$headerRow}", "金額");
    $sheet->getStyle("A{$headerRow}:C{$headerRow}")->getFont()->setBold(true);

    // データ行書き込み & 経費合計計算
    $currentRow = $headerRow + 1;
    $expenseTotal = 0;
    foreach ($detailRows as $row) {
        $amount = (float)$row['amount'];
        $sheet->setCellValue("A{$currentRow}", $row['account_name']);
        $sheet->setCellValue("B{$currentRow}", $row['detail_name']);
        $sheet->setCellValue("C{$currentRow}", $amount)->getStyle("C{$currentRow}")->getNumberFormat()->setFormatCode('#,##0');
        $expenseTotal += $amount;
        $currentRow++;
    }

    // 時間・人数データ書き込み (E列以降)
    $timeColStart = 'E';
    $timeRowStart = 3;
    $sheet->setCellValue("{$timeColStart}{$timeRowStart}", '定時間');
    $sheet->setCellValue("F{$timeRowStart}", (float)$timeData['standard_hours'])->getStyle("F{$timeRowStart}")->getNumberFormat()->setFormatCode('0.00');
    $timeRowStart++;
    $sheet->setCellValue("{$timeColStart}{$timeRowStart}", '残業時間');
    $sheet->setCellValue("F{$timeRowStart}", (float)$timeData['overtime_hours'])->getStyle("F{$timeRowStart}")->getNumberFormat()->setFormatCode('0.00');
    $timeRowStart++;
    $sheet->setCellValue("{$timeColStart}{$timeRowStart}", '振替時間');
    $sheet->setCellValue("F{$timeRowStart}", (float)$timeData['transferred_hours'])->getStyle("F{$timeRowStart}")->getNumberFormat()->setFormatCode('0.00');
    $timeRowStart += 2; // 空白行
    $sheet->setCellValue("{$timeColStart}{$timeRowStart}", '正社員');
    $sheet->setCellValue("F{$timeRowStart}", (int)$timeData['fulltime_count']);
    $timeRowStart++;
    $sheet->setCellValue("{$timeColStart}{$timeRowStart}", '契約社員');
    $sheet->setCellValue("F{$timeRowStart}", (int)$timeData['contract_count']);
    $timeRowStart++;
    $sheet->setCellValue("{$timeColStart}{$timeRowStart}", '派遣社員');
    $sheet->setCellValue("F{$timeRowStart}", (int)$timeData['dispatch_count']);
    $timeRowStart++;
    $sheet->setCellValue("{$timeColStart}{$timeRowStart}", '賃率');
    $sheet->setCellValue("F{$timeRowStart}", (float)$timeData['hourly_rate'])->getStyle("F{$timeRowStart}")->getNumberFormat()->setFormatCode('#,##0');

    // 合計計算
    $totalHours = (float)$timeData['standard_hours'] + (float)$timeData['overtime_hours'] + (float)$timeData['transferred_hours'];
    $laborCost = round($totalHours * (float)$timeData['hourly_rate']);
    $grandTotal = $laborCost + $expenseTotal;

    // 合計値を書き込み (E列)
    $totalRowStart = $timeRowStart + 2; // 空白行を挟む
    $sheet->setCellValue("{$timeColStart}{$totalRowStart}", '総時間');
    $sheet->setCellValue("F{$totalRowStart}", $totalHours)->getStyle("F{$totalRowStart}")->getNumberFormat()->setFormatCode('0.00');
    $totalRowStart++;
    $sheet->setCellValue("{$timeColStart}{$totalRowStart}", '経費合計');
    $sheet->setCellValue("F{$totalRowStart}", $expenseTotal)->getStyle("F{$totalRowStart}")->getNumberFormat()->setFormatCode('#,##0');
    $totalRowStart++;
    $sheet->setCellValue("{$timeColStart}{$totalRowStart}", '労務費');
    $sheet->setCellValue("F{$totalRowStart}", $laborCost)->getStyle("F{$totalRowStart}")->getNumberFormat()->setFormatCode('#,##0');
    $totalRowStart++;
    $sheet->setCellValue("{$timeColStart}{$totalRowStart}", '総合計');
    $sheet->setCellValue("F{$totalRowStart}", $grandTotal)->getStyle("F{$totalRowStart}")->getNumberFormat()->setFormatCode('#,##0');

    // 列幅自動調整
    foreach (['A', 'B', 'C', 'E', 'F'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// ダウンロード用ヘッダー
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=forecast_details_{$year}_{$month}.xlsx"); // ファイル名を変更
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
