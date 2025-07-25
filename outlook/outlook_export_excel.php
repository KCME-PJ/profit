<?php
require_once '../vendor/autoload.php'; // PhpSpreadsheet 読込
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 入力チェック
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

// 確定済チェック
$queryStatus = "SELECT status FROM monthly_outlook WHERE year = :year AND month = :month";
$stmt = $dbh->prepare($queryStatus);
$stmt->execute([':year' => $year, ':month' => $month]);
$status = $stmt->fetchColumn();

if (!$status) {
    header("Location: outlook_edit.php?error=" . urlencode("【{$year}年度 {$month}月】の月末見込は未登録です。"));
    exit;
} elseif ($status !== 'fixed') {
    header("Location: outlook_edit.php?error=" . urlencode("【{$year}年度 {$month}月】の月末見込は未確定です。確定後に出力してください。"));
    exit;
}

// 労務費データ取得（outlook用）
$queryTime = "SELECT o.standard_hours, o.overtime_hours, o.transferred_hours, o.hourly_rate,
                     o.fulltime_count, o.contract_count, o.dispatch_count
              FROM monthly_outlook o
              WHERE o.year = :year AND o.month = :month";
$stmt = $dbh->prepare($queryTime);
$stmt->execute([':year' => $year, ':month' => $month]);
$timeData = $stmt->fetch(PDO::FETCH_ASSOC);

// 勘定科目ごとの合計金額取得（outlook明細を合計）
$queryAccount = "SELECT a.id AS account_id, a.name AS account_name, SUM(d.amount) AS total
                 FROM monthly_outlook_details d
                 JOIN monthly_outlook o ON d.outlook_id = o.id
                 JOIN details det ON d.detail_id = det.id
                 JOIN accounts a ON det.account_id = a.id
                 WHERE o.year = :year AND o.month = :month
                 GROUP BY a.id, a.name
                 ORDER BY a.id";
$stmt = $dbh->prepare($queryAccount);
$stmt->execute([':year' => $year, ':month' => $month]);
$accountRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Excel 出力
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("月末見込_{$year}_{$month}");

// 年度と月
$sheet->setCellValue("A1", "{$year}年度");
$sheet->setCellValue("B1", "{$month}月");

// 空欄ゼロ埋め
$sheet->setCellValue("A7", "販売手数料");
$sheet->setCellValue("B7", "0");
$sheet->setCellValue("A8", "販促積立費");
$sheet->setCellValue("B8", "0");
$sheet->setCellValue("A9", "販促取崩費");
$sheet->setCellValue("B9", "0");
$sheet->setCellValue("A10", "販促費");
$sheet->setCellValue("B10", "0");
$sheet->setCellValue("A12", "接待交際費");
$sheet->setCellValue("B12", "0");
$sheet->setCellValue("A18", "内部消工費");
$sheet->setCellValue("B18", "0");
$sheet->setCellValue("D5", "部内共通時間");
$sheet->setCellValue("E5", "0");
$sheet->getStyle('E5')->getNumberFormat()->setFormatCode('0.00');

// 勘定科目idを元に行番号を指定
$accountRowMap = [
    1 => 3,  // 業務委託費
    2 => 4,  // 雑給
    3 => 5,  // 福利厚生費
    11 => 6, // 荷造運賃費
    12 => 11, // 広告宣伝費
    13 => 13, // 電話通信費
    14 => 14, // 旅費交通費
    15 => 15, // 消耗品費
    16 => 16, // 賃借料
    17 => 17, // 雑費
    18 => 19, // 減価償却費
    19 => 20, // 社内金利
    20 => 21, // 内部諸経費
];

foreach ($accountRows as $account) {
    $id = (int)$account['account_id'];
    $row = $accountRowMap[$id] ?? null;

    if (!$row) continue;

    $sheet->setCellValue("A{$row}", $account['account_name']);
    $sheet->setCellValue("B{$row}", (int)$account['total']);
    $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0');
}

// 時間管理
if ($timeData) {
    $sheet->setCellValue('D3', '定時間');
    $sheet->setCellValue('E3', $timeData['standard_hours']);
    $sheet->getStyle('E3')->getNumberFormat()->setFormatCode('0.00');

    $sheet->setCellValue('D4', '残業時間');
    $sheet->setCellValue('E4', $timeData['overtime_hours']);
    $sheet->getStyle('E4')->getNumberFormat()->setFormatCode('0.00');

    $sheet->setCellValue('D6', '振替時間');
    $sheet->setCellValue('E6', $timeData['transferred_hours']);
    $sheet->getStyle('E6')->getNumberFormat()->setFormatCode('0.00');

    $sheet->setCellValue('D8', '正社員');
    $sheet->setCellValue('E8', $timeData['fulltime_count']);

    $sheet->setCellValue('D9', '契約社員');
    $sheet->setCellValue('E9', $timeData['contract_count']);

    $sheet->setCellValue('D10', '派遣社員');
    $sheet->setCellValue('E10', $timeData['dispatch_count']);
}

// A, B, D, E 列の幅を自動調整
foreach (['A', 'B', 'D', 'E'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ダウンロード用ヘッダー
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=月末見込_{$year}_{$month}.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
