<?php
require_once '../vendor/autoload.php'; // PhpSpreadsheet 読込
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 入力チェック
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;

if (!$year || !$month) {
    header("Location: cp_edit.php?error=" . urlencode("年度と月を指定してください。"));
    exit;
}

// エラーリダイレクト用関数
function redirectWithError($msg, $year, $month)
{
    $safeMsg = urlencode($msg);
    header("Location: cp_edit.php?year={$year}&month={$month}&error={$safeMsg}");
    exit;
}

$dbh = getDb();

// CPデータの存在と確定ステータスチェック
$queryStatus = "SELECT id, status FROM monthly_cp WHERE year = :year AND month = :month";
$stmt = $dbh->prepare($queryStatus);
$stmt->execute([':year' => $year, ':month' => $month]);
$cpData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cpData) {
    redirectWithError("【{$year}年度 {$month}月】のCPは未登録です。", $year, $month);
} elseif ($cpData['status'] !== 'fixed') {
    redirectWithError("【{$year}年度 {$month}月】のCPは未確定です。確定後に出力してください。", $year, $month);
}
$monthlyCpId = $cpData['id'];

// 営業所の一覧を取得 (officesテーブルを使用)
$queryOffices = "SELECT id, name FROM offices ORDER BY id";
$stmt = $dbh->prepare($queryOffices);
$stmt->execute();
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$offices) {
    redirectWithError("営業所データが見つかりません。", $year, $month);
}

// ★ 勘定科目マスターを取得 (IDと名前のマッピングのため)
$stmtAccounts = $dbh->query("SELECT id, name FROM accounts");
$accountsList = $stmtAccounts->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

// 勘定科目ごとの集計データを取得 (全営業所分, CPデータのみ)
// (detailsテーブルのoffice_idを利用してグループ化)
$queryAccount = "
    SELECT 
        det.office_id, 
        a.id AS account_id, 
        SUM(d.amount) AS total
    FROM monthly_cp_details d
    JOIN details det ON d.detail_id = det.id
    JOIN accounts a ON det.account_id = a.id
    WHERE d.monthly_cp_id = :monthly_cp_id AND d.type = 'cp' 
    GROUP BY det.office_id, a.id
    ORDER BY det.office_id, a.id";

$stmt = $dbh->prepare($queryAccount);
$stmt->execute([':monthly_cp_id' => $monthlyCpId]);
$accountRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// データを [office_id][account_id] => total の形に再編成
$groupedAccountData = [];
foreach ($accountRows as $row) {
    $groupedAccountData[$row['office_id']][$row['account_id']] = $row['total'];
}

// Excel 出力
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // デフォルトシート削除

// ★ 勘定科目ID => Excel行番号のマッピング (新しい仕様に変更)
$accountRowMap = [
    1 => 4,
    2 => 5,
    3 => 6,
    4 => 7,
    5 => 8,
    6 => 9,
    7 => 10,
    8 => 11,
    9 => 12,
    10 => 13,
    11 => 14,
    12 => 15,
    13 => 16,
    14 => 17,
    15 => 18,
    16 => 19,
    17 => 20,
    18 => 21,
    19 => 22,
    20 => 24,
    21 => 25
];

// 営業所ごとにシートを作成
foreach ($offices as $office) {
    $officeId = $office['id'];
    $officeName = $office['name'];

    // 営業所ごとの時間・人数・賃率データを取得 (CPデータのみ)
    $queryTime = "
        SELECT 
            standard_hours, overtime_hours, transferred_hours, hourly_rate, 
            fulltime_count, contract_count, dispatch_count
        FROM monthly_cp_time 
        WHERE monthly_cp_id = :monthly_cp_id AND office_id = :office_id AND type = 'cp'";
    $stmtTime = $dbh->prepare($queryTime);
    $stmtTime->execute([':monthly_cp_id' => $monthlyCpId, ':office_id' => $officeId]);
    $timeData = $stmtTime->fetch(PDO::FETCH_ASSOC);

    // データがない場合のデフォルト値
    $timeData = $timeData ?: [
        'standard_hours' => 0,
        'overtime_hours' => 0,
        'transferred_hours' => 0,
        'hourly_rate' => 0,
        'fulltime_count' => 0,
        'contract_count' => 0,
        'dispatch_count' => 0
    ];

    // 新しいシートを作成
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($officeName);

    // ヘッダー情報
    $sheet->setCellValue("A1", "{$year}年度");
    $sheet->setCellValue("B1", "{$month}月");
    $sheet->setCellValue("A2", "営業所名");
    $sheet->setCellValue("B2", $officeName);

    // ★ 勘定科目ごとの集計データ書き込み & 経費合計計算 (マッピング基準に変更)
    $expenseTotal = 0;
    $accountDataForOffice = $groupedAccountData[$officeId] ?? [];

    foreach ($accountRowMap as $id => $row) {
        $amount = (float)($accountDataForOffice[$id] ?? 0);
        $name = $accountsList[$id] ?? "勘定科目{$id}"; // マスターから名前を取得

        $sheet->setCellValue("A{$row}", $name);
        $sheet->setCellValue("B{$row}", $amount)->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $expenseTotal += $amount;
    }

    // ★ 固定項目（部内共通費）
    $sheet->setCellValue("A23", "部内共通費");
    $sheet->setCellValue("B23", 0)->getStyle("B23")->getNumberFormat()->setFormatCode('#,##0');

    // ★ D/E列を1行下げて書き込み
    $sheet->setCellValue("D4", "定時間"); // D3->D4
    $sheet->setCellValue("E4", (float)$timeData['standard_hours'])->getStyle('E4')->getNumberFormat()->setFormatCode('0.00');
    $sheet->setCellValue("D5", "残業時間"); // D4->D5
    $sheet->setCellValue("E5", (float)$timeData['overtime_hours'])->getStyle('E5')->getNumberFormat()->setFormatCode('0.00');
    $sheet->setCellValue("D6", "部内共通時間"); // D5->D6
    $sheet->setCellValue("E6", 0)->getStyle('E6')->getNumberFormat()->setFormatCode('0.00');
    $sheet->setCellValue("D7", "振替時間"); // D6->D7
    $sheet->setCellValue("E7", (float)$timeData['transferred_hours'])->getStyle('E7')->getNumberFormat()->setFormatCode('0.00');

    $sheet->setCellValue("D9", "正社員"); // D8->D9
    $sheet->setCellValue("E9", (int)$timeData['fulltime_count']);
    $sheet->setCellValue("D10", "契約社員"); // D9->D10
    $sheet->setCellValue("E10", (int)$timeData['contract_count']);
    $sheet->setCellValue("D11", "派遣社員"); // D10->D11
    $sheet->setCellValue("E11", (int)$timeData['dispatch_count']);

    $sheet->setCellValue("D12", "賃率"); // D11->D12
    $sheet->setCellValue("E12", (float)$timeData['hourly_rate'])->getStyle('E12')->getNumberFormat()->setFormatCode('#,##0');

    // 合計計算 (変更なし)
    $totalHours = (float)$timeData['standard_hours'] + (float)$timeData['overtime_hours'] + (float)$timeData['transferred_hours'];
    $laborCost = round($totalHours * (float)$timeData['hourly_rate']);
    $grandTotal = $laborCost + $expenseTotal;

    // ★ 合計値を1行下げて書き込み (D14-E17)
    $sheet->setCellValue('D14', '総時間'); // D13->D14
    $sheet->setCellValue('E14', $totalHours)->getStyle('E14')->getNumberFormat()->setFormatCode('0.00');
    $sheet->setCellValue('D15', '経費合計'); // D14->D15
    $sheet->setCellValue('E15', $expenseTotal)->getStyle('E15')->getNumberFormat()->setFormatCode('#,##0');
    $sheet->setCellValue('D16', '労務費'); // D15->D16
    $sheet->setCellValue('E16', $laborCost)->getStyle('E16')->getNumberFormat()->setFormatCode('#,##0');
    $sheet->setCellValue('D17', '総合計'); // D16->D17
    $sheet->setCellValue('E17', $grandTotal)->getStyle('E17')->getNumberFormat()->setFormatCode('#,##0');

    // 列幅自動調整
    foreach (['A', 'B', 'D', 'E'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// ダウンロード用ヘッダー
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=cp_summary_{$year}_{$month}.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
