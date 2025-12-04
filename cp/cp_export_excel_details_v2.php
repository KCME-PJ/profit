<?php
require_once '../vendor/autoload.php';
require_once '../includes/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// バッファをクリア（以前の出力やエラー表示を消すため）
if (ob_get_length()) ob_clean();

$year = isset($_GET['year']) ? (int)$_GET['year'] : null;

if (!$year) {
    header("Location: cp_edit.php?error=" . urlencode("年度を指定してください。"));
    exit;
}

$dbh = getDb();
$months = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// 営業所一覧
$stmt = $dbh->prepare("SELECT id, name FROM offices ORDER BY id");
$stmt->execute();
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

foreach ($offices as $office) {
    $officeId = $office['id'];
    $officeName = $office['name'];

    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($officeName);

    // ヘッダー情報
    $sheet->setCellValue("A1", "{$year}年度 CP詳細表");
    $sheet->setCellValue("A2", "営業所名: {$officeName}");
    $sheet->setCellValue("A3", "項目 (勘定科目 - 詳細)");

    // 月ヘッダー (B3〜M3)
    $colIndex = 2;
    foreach ($months as $m) {
        $colStr = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->setCellValue("{$colStr}3", "{$m}月");
        $colIndex++;
    }

    // ============================================
    // 1. 詳細項目のリストアップ
    // ============================================

    // 詳細項目リスト取得
    $queryDetailsList = "
        SELECT DISTINCT 
            a.id as account_id, 
            a.name as account_name, 
            det.id as detail_id, 
            det.name as detail_name
        FROM monthly_cp_details d
        JOIN monthly_cp mcp ON d.monthly_cp_id = mcp.id
        JOIN details det ON d.detail_id = det.id
        JOIN accounts a ON det.account_id = a.id
        WHERE 
            det.office_id = :office_id
            AND d.type = 'cp'
            AND d.amount > 0
            AND mcp.year = :year
        ORDER BY a.id, det.id
    ";

    $stmtList = $dbh->prepare($queryDetailsList);
    $stmtList->execute([
        ':office_id' => $officeId,
        ':year' => $year
    ]);
    $uniqueDetails = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // 2. 行ごとのデータ構築と出力（金額）
    // ============================================

    // 現在の行位置
    $currentRow = 4;

    // 各月の経費合計を保持する配列 [月 => 合計金額]
    $monthlyExpenseTotal = array_fill_keys($months, 0);

    foreach ($uniqueDetails as $uDetail) {
        $detailId = $uDetail['detail_id'];
        // A列: 項目名
        $itemName = $uDetail['account_name'] . " - " . $uDetail['detail_name'];
        $sheet->setCellValue("A{$currentRow}", $itemName);

        // B列以降: 月次データ埋め込み
        $colIndex = 2;
        foreach ($months as $m) {
            // ★ここ：年度管理なのでそのまま $year を使う
            $targetYear = $year;

            $colStr = Coordinate::stringFromColumnIndex($colIndex);

            // 金額取得
            $qVal = "
                SELECT d.amount 
                FROM monthly_cp_details d
                JOIN monthly_cp mcp ON d.monthly_cp_id = mcp.id
                WHERE d.detail_id = :detail_id
                  AND mcp.year = :year
                  AND mcp.month = :month
                  AND d.type = 'cp'
            ";
            $stmtVal = $dbh->prepare($qVal);
            $stmtVal->execute([':detail_id' => $detailId, ':year' => $targetYear, ':month' => $m]);
            $amount = (float)$stmtVal->fetchColumn();

            $sheet->setCellValue("{$colStr}{$currentRow}", $amount);
            $sheet->getStyle("{$colStr}{$currentRow}")->getNumberFormat()->setFormatCode('#,##0');

            // 合計加算
            $monthlyExpenseTotal[$m] += $amount;

            $colIndex++;
        }
        $currentRow++;
    }

    // ============================================
    // 3. 時間・人数・賃率・合計の出力 (詳細項目の下に出力)
    // ============================================
    // 出力開始行の設定
    $timeStartRow = $currentRow + 1;

    // 項目名セット (A列)
    $row = $timeStartRow;
    $sheet->setCellValue("A{$row}", "定時間");
    $row++;
    $sheet->setCellValue("A{$row}", "残業時間");
    $row++;
    $sheet->setCellValue("A{$row}", "振替時間");
    $row++;
    $row++;
    $sheet->setCellValue("A{$row}", "正社員");
    $row++;
    $sheet->setCellValue("A{$row}", "契約社員");
    $row++;
    $sheet->setCellValue("A{$row}", "派遣社員");
    $row++;
    $sheet->setCellValue("A{$row}", "賃率");
    $row++;
    $row++;
    $sheet->setCellValue("A{$row}", "総時間");
    $row++;
    $sheet->setCellValue("A{$row}", "経費合計");
    $row++;
    $sheet->setCellValue("A{$row}", "労務費");
    $row++;
    $sheet->setCellValue("A{$row}", "総合計");

    // データセット (B列以降)
    $colIndex = 2;
    foreach ($months as $m) {
        // ★修正ポイント：ここも $yearEnd ではなく $year をそのまま使います
        $targetYear = $year;

        $colStr = Coordinate::stringFromColumnIndex($colIndex);

        // 時間データ取得
        $qTime = "
            SELECT * FROM monthly_cp_time t
            JOIN monthly_cp mcp ON t.monthly_cp_id = mcp.id
            WHERE mcp.year = :year 
              AND mcp.month = :month
              AND t.office_id = :office_id
              AND t.type = 'cp'
        ";
        $stmtTime = $dbh->prepare($qTime);
        $stmtTime->execute([':year' => $targetYear, ':month' => $m, ':office_id' => $officeId]);
        $tData = $stmtTime->fetch(PDO::FETCH_ASSOC);

        $stdH = (float)($tData['standard_hours'] ?? 0);
        $overH = (float)($tData['overtime_hours'] ?? 0);
        $transH = (float)($tData['transferred_hours'] ?? 0);
        $full = (int)($tData['fulltime_count'] ?? 0);
        $cont = (int)($tData['contract_count'] ?? 0);
        $disp = (int)($tData['dispatch_count'] ?? 0);
        $rate = (float)($tData['hourly_rate'] ?? 0);

        // 出力
        $row = $timeStartRow;
        $sheet->setCellValue("{$colStr}{$row}", $stdH)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $overH)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $transH)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $full);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $cont);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $disp);
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $rate)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;
        $row++;

        // 計算
        $totalH = $stdH + $overH + $transH;
        $expenseT = $monthlyExpenseTotal[$m];
        $laborC = round($totalH * $rate);
        $grandT = $laborC + $expenseT;

        $sheet->setCellValue("{$colStr}{$row}", $totalH)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $expenseT)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $laborC)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $row++;
        $sheet->setCellValue("{$colStr}{$row}", $grandT)->getStyle("{$colStr}{$row}")->getNumberFormat()->setFormatCode('#,##0');

        $colIndex++;
    }

    // 列幅
    for ($i = 1; $i <= 13; $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
}

// ダウンロードヘッダー
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=cp_details_annual_{$year}.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
