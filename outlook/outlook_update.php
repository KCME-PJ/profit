<?php
require_once '../includes/database.php';
require_once '../includes/outlook_functions.php';

$actionType = $_POST['action_type'] ?? 'update';
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$officeTimeData = $_POST['officeTimeData'] ?? [];
if (!is_array($officeTimeData)) {
    // JSON文字列の場合にデコードを試みる
    $officeTimeData = json_decode($officeTimeData, true) ?? [];
}

// ----------------------------------------------------------------
// bulk_json_data (一括送信データ) の受け取り処理
// ----------------------------------------------------------------
$detailAmounts = $_POST['amounts'] ?? [];
$revenueAmounts = $_POST['revenues'] ?? [];

if (!empty($_POST['bulk_json_data'])) {
    $bulkData = json_decode($_POST['bulk_json_data'], true);
    if (is_array($bulkData)) {
        // 収入データ
        if (isset($bulkData['revenues']) && is_array($bulkData['revenues'])) {
            $revenueAmounts = $bulkData['revenues'];
        }
        // 経費データ
        if (isset($bulkData['amounts']) && is_array($bulkData['amounts'])) {
            $detailAmounts = $bulkData['amounts'];
        }
    }
}
// ----------------------------------------------------------------

// ★共通仕様: hidden_hourly_rate から値を受け取る
$hourly_rate_input = $_POST['hidden_hourly_rate'] ?? '';

// 空文字なら null、値があれば float に変換
$hourly_rate = ($hourly_rate_input !== '') ? (float)$hourly_rate_input : null;
$outlookId = $_POST['outlook_id'] ?? null;

$dbh = getDb();
$dbh->beginTransaction();

try {
    // DB関数に渡す $data 配列
    $data = [
        'outlook_id' => $outlookId,
        'year' => $year,
        'month' => $month,
        'hourly_rate' => $hourly_rate, // 共通賃率
        'officeTimeData' => $officeTimeData, // 時間・人数
        'amounts' => $detailAmounts,    // 経費
        'revenues' => $revenueAmounts    // 収入
    ];

    if ($actionType === 'fixed') {
        // 確定処理: update後にResultへ反映
        confirmMonthlyOutlook($data, $dbh);
        $message = "月末見込みの確定・概算実績への反映が完了しました。";
    } else {
        // 修正処理: updateのみ
        updateMonthlyOutlook($data, $dbh);
        $message = "月末見込みの更新が完了しました。";
    }

    $dbh->commit();

    // 成功リダイレクト
    $redirectUrl = "outlook_edit.php?success=1&year={$year}&month={$month}&msg=" . urlencode($message);
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    $dbh->rollBack();
    // エラーリダイレクト
    $redirectUrl = "outlook_edit.php?error=" . urlencode("登録エラー: " . $e->getMessage()) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
