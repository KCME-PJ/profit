<?php
require_once '../vendor/autoload.php'; // PhpSpreadsheet 読込
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// 入力チェック (年度のみ必須に変更)
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;

if (!$year) {
    header("Location: cp_edit.php?error=" . urlencode("年度を指定してください。"));
    exit;
}

$dbh = getDb();

// 営業所の一覧を取得
$queryOffices = "SELECT id, name FROM offices ORDER BY id";
$stmt = $dbh->prepare($queryOffices);
$stmt->execute();
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$offices) {
    // エラー処理（簡略化）
    header("Location: cp_edit.php?error=" . urlencode("営業所データが見つかりません。"));
    exit;
}

// 勘定科目マスターを取得
$stmtAccounts = $dbh->query("SELECT id, name FROM accounts ORDER BY id");
$accountsList = $stmtAccounts->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

// Excel 作成
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

// 年度内の月リスト (4月〜翌3月)
$months = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// 勘定科目の出力行マッピング (A列の何行目に出すか)
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
// 部内共通費の行
$commonCostRow = 23;

// 時間管理項目の開始行（勘定科目の下）
// マッピングの最大行(25)の次から配置
$timeStartRow = 27;

// 営業所ごとにシート作成
foreach ($offices as $office) {
    $officeId = $office['id'];
    $officeName = $office['name'];

    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($officeName);

    // ヘッダー (A列: 項目名)
    $sheet->setCellValue("A1", "{$year}年度 CP管理表");
    $sheet->setCellValue("A2", "営業所名: {$officeName}");
    $sheet->setCellValue("A3", "項目");

    // 勘定科目名のセット
    foreach ($accountRowMap as $accId => $rowNum) {
        $accName = $accountsList[$accId] ?? "科目{$accId}";
        $sheet->setCellValue("A{$rowNum}", $accName);
    }
    $sheet->setCellValue("A{$commonCostRow}", "部内共通費");

    // 時間管理・人数の項目名セット (A列)
    $row = $timeStartRow;
    $sheet->setCellValue("A{$row}", "定時間");
    $row++;
    $sheet->setCellValue("A{$row}", "残業時間");
    $row++;
    $sheet->setCellValue("A{$row}", "部内共通時間");
    $row++;
    $sheet->setCellValue("A{$row}", "振替時間");
    $row++;
    $row++; // 空行
    $sheet->setCellValue("A{$row}", "正社員");
    $row++;
    $sheet->setCellValue("A{$row}", "契約社員");
    $row++;
    $sheet->setCellValue("A{$row}", "派遣社員");
    $row++;
    $sheet->setCellValue("A{$row}", "賃率");
    $row++;
    $row++; // 空行
    $sheet->setCellValue("A{$row}", "総時間");
    $row++;
    $sheet->setCellValue("A{$row}", "経費合計");
    $row++;
    $sheet->setCellValue("A{$row}", "労務費");
    $row++;
    $sheet->setCellValue("A{$row}", "総合計");

    // 月ごとのデータ処理
    $colIndex = 2; // B列スタート
    foreach ($months as $m) {
        $currentYear = $year;

        // 列文字取得 (B, C, D...)
        $colStr = Coordinate::stringFromColumnIndex($colIndex);

        // ヘッダーに月を表示
        $sheet->setCellValue("{$colStr}3", "{$m}月");

        // 1. CPデータの取得
        $queryStatus = "SELECT id, status FROM monthly_cp WHERE year = :year AND month = :month";
        $stmt = $dbh->prepare($queryStatus);
        $stmt->execute([':year' => $currentYear, ':month' => $m]);
        $cpData = $stmt->fetch(PDO::FETCH_ASSOC);

        // データさえあれば、statusに関わらずIDを取得する
        if (!$cpData) {
            $monthlyCpId = 0;
        } else {
            $monthlyCpId = $cpData['id'];
        }

        // 2. 勘定科目データの取得
        $accountData = [];
        if ($monthlyCpId) {
            $queryAccount = "
                SELECT a.id AS account_id, SUM(d.amount) AS total
                FROM monthly_cp_details d
                JOIN details det ON d.detail_id = det.id
                JOIN accounts a ON det.account_id = a.id
                WHERE d.monthly_cp_id = :monthly_cp_id 
                  AND det.office_id = :office_id
                  AND d.type = 'cp'
                GROUP BY a.id";
            $stmtAcc = $dbh->prepare($queryAccount);
            $stmtAcc->execute([':monthly_cp_id' => $monthlyCpId, ':office_id' => $officeId]);
            $accountData = $stmtAcc->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // 3. 勘定科目の書き込み & 経費計
        $expenseTotal = 0;
        foreach ($accountRowMap as $accId => $rowNum) {
            $amount = (float)($accountData[$accId] ?? 0);
            $sheet->setCellValue("{$colStr}{$rowNum}", $amount);
            $sheet->getStyle("{$colStr}{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            $expenseTotal += $amount;
        }
        // 部内共通費 (固定0)
        $sheet->setCellValue("{$colStr}{$commonCostRow}", 0);
        $sheet->getStyle("{$colStr}{$commonCostRow}")->getNumberFormat()->setFormatCode('#,##0');


        // 4. 時間データの取得
        $timeData = [];
        if ($monthlyCpId) {
            $queryTime = "
                SELECT * FROM monthly_cp_time 
                WHERE monthly_cp_id = :monthly_cp_id 
                  AND office_id = :office_id 
                  AND type = 'cp'";
            $stmtTime = $dbh->prepare($queryTime);
            $stmtTime->execute([':monthly_cp_id' => $monthlyCpId, ':office_id' => $officeId]);
            $timeData = $stmtTime->fetch(PDO::FETCH_ASSOC);
        }
        // デフォルト値
        $standard_hours = (float)($timeData['standard_hours'] ?? 0);
        $overtime_hours = (float)($timeData['overtime_hours'] ?? 0);
        $transferred_hours = (float)($timeData['transferred_hours'] ?? 0);
        $common_hours = 0; // 部内共通時間は0固定
        $fulltime = (int)($timeData['fulltime_count'] ?? 0);
        $contract = (int)($timeData['contract_count'] ?? 0);
        $dispatch = (int)($timeData['dispatch_count'] ?? 0);
        $hourly_rate = (float)($timeData['hourly_rate'] ?? 0);

        // 5. 時間・人数の書き込み
        $row = $timeStartRow;
        $sheet->setCellValue("{$colStr}{$row}", $standard_hours);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;

        $sheet->setCellValue("{$colStr}{$row}", $overtime_hours);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;

        $sheet->setCellValue("{$colStr}{$row}", $common_hours);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;

        $sheet->setCellValue("{$colStr}{$row}", $transferred_hours);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;

        $row++; // 空行

        $sheet->setCellValue("{$colStr}{$row}", $fulltime);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $contract);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $dispatch);
        $row++;

        $sheet->setCellValue("{$colStr}{$row}", $hourly_rate);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $row++; // 空行

        // 6. 合計計算
        $totalHours = $standard_hours + $overtime_hours + $transferred_hours;
        $laborCost = round($totalHours * $hourly_rate);
        $grandTotal = $laborCost + $expenseTotal;

        // 合計書き込み
        $sheet->setCellValue("{$colStr}{$row}", $totalHours);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;

        $sheet->setCellValue("{$colStr}{$row}", $expenseTotal);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $sheet->setCellValue("{$colStr}{$row}", $laborCost);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $sheet->setCellValue("{$colStr}{$row}", $grandTotal);
        $sheet->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');

        $colIndex++;
    }

    // 列幅調整 (A〜M)
    for ($i = 1; $i <= 13; $i++) {
        $colStr = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($colStr)->setAutoSize(true);
    }
}

// ダウンロードヘッダー
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=cp_summary_annual_{$year}.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
