<?php
require_once '../vendor/autoload.php'; // PhpSpreadsheet 読込
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// 入力チェック
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
    header("Location: cp_edit.php?error=" . urlencode("営業所データが見つかりません。"));
    exit;
}

// 勘定科目マスターを「表示順 (sort_order)」で取得
// sort_order が同じ場合は ID 順
$stmtAccounts = $dbh->query("SELECT id, name FROM accounts ORDER BY sort_order ASC, id ASC");
$accountsList = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC); // [['id'=>1, 'name'=>'...'], ...]

// Excel 作成
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

// 年度内の月リスト
$months = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// 勘定科目の出力開始行 (4行目から)
$accountStartRow = 4;

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

    // ============================================
    // 1. 勘定科目データの事前取得 (パフォーマンス対策)
    // ============================================
    // 月ごとの勘定科目合計を取得しておく
    // 構造: $monthlyAccountData[月][勘定科目ID] = 金額
    $monthlyAccountData = [];

    // CPデータのIDを特定
    $cpIds = []; // [月 => monthly_cp_id]
    foreach ($months as $m) {
        $stmt = $dbh->prepare("SELECT id FROM monthly_cp WHERE year = :year AND month = :month");
        $stmt->execute([':year' => $year, ':month' => $m]);
        $cpIds[$m] = $stmt->fetchColumn() ?: 0;
    }

    // 各月のデータを取得
    foreach ($months as $m) {
        $monthlyCpId = $cpIds[$m];
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
            $results = $stmtAcc->fetchAll(PDO::FETCH_KEY_PAIR);
            $monthlyAccountData[$m] = $results;
        } else {
            $monthlyAccountData[$m] = [];
        }
    }

    // ============================================
    // 2. 勘定科目行の出力 (動的配置)
    // ============================================
    $currentRow = $accountStartRow;

    // A列: 科目名、B列以降: 金額
    foreach ($accountsList as $acc) {
        $accId = $acc['id'];
        $accName = $acc['name'];

        // 科目名
        $sheet->setCellValue("A{$currentRow}", $accName);

        // 各月の金額
        $colIndex = 2; // B列スタート
        foreach ($months as $m) {
            $colStr = Coordinate::stringFromColumnIndex($colIndex);

            // ヘッダー月 (ループ内で上書きしても問題なし)
            if ($currentRow === $accountStartRow) {
                $sheet->setCellValue("{$colStr}3", "{$m}月");
            }

            $amount = (float)($monthlyAccountData[$m][$accId] ?? 0);
            $sheet->setCellValue("{$colStr}{$currentRow}", $amount);
            $sheet->getStyle("{$colStr}{$currentRow}")->getNumberFormat()->setFormatCode('#,##0');

            $colIndex++;
        }
        $currentRow++;
    }

    // 部内共通費 (勘定科目の直下)
    $commonCostRow = $currentRow;
    $sheet->setCellValue("A{$commonCostRow}", "部内共通費");

    $colIndex = 2;
    foreach ($months as $m) {
        $colStr = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->setCellValue("{$colStr}{$commonCostRow}", 0);
        $sheet->getStyle("{$colStr}{$commonCostRow}")->getNumberFormat()->setFormatCode('#,##0');
        $colIndex++;
    }

    // ============================================
    // 3. 時間・人数項目の出力
    // ============================================
    // 空行を挟んで次のセクションへ (例: 3行あけるなら +4)
    // 元のレイアウト感に合わせて調整してください
    $timeStartRow = $currentRow + 4;

    // 項目名セット
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

    // 各月の時間・人数・合計データ
    $colIndex = 2;
    foreach ($months as $m) {
        $colStr = Coordinate::stringFromColumnIndex($colIndex);
        $monthlyCpId = $cpIds[$m];

        // 時間データの取得
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

        $standard_hours = (float)($timeData['standard_hours'] ?? 0);
        $overtime_hours = (float)($timeData['overtime_hours'] ?? 0);
        $transferred_hours = (float)($timeData['transferred_hours'] ?? 0);
        $common_hours = 0;
        $fulltime = (int)($timeData['fulltime_count'] ?? 0);
        $contract = (int)($timeData['contract_count'] ?? 0);
        $dispatch = (int)($timeData['dispatch_count'] ?? 0);
        $hourly_rate = (float)($timeData['hourly_rate'] ?? 0);

        // 経費合計の計算 (科目の合計)
        $expenseTotal = 0;
        foreach ($accountsList as $acc) {
            $expenseTotal += (float)($monthlyAccountData[$m][$acc['id']] ?? 0);
        }
        // 部内共通費があれば加算 (現状0)
        $expenseTotal += 0;

        // 出力
        $row = $timeStartRow;
        $sheet->setCellValue("{$colStr}{$row}", $standard_hours)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $overtime_hours)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $common_hours)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $transferred_hours)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $row++; // 空行
        $sheet->setCellValue("{$colStr}{$row}", $fulltime);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $contract);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $dispatch);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $hourly_rate)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;
        $row++; // 空行

        // 合計
        $totalHours = $standard_hours + $overtime_hours + $transferred_hours;
        $laborCost = round($totalHours * $hourly_rate);
        $grandTotal = $laborCost + $expenseTotal;

        $sheet->setCellValue("{$colStr}{$row}", $totalHours)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $expenseTotal)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $laborCost)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $grandTotal)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');

        $colIndex++;
    }

    // 列幅調整
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
