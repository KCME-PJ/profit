<?php
require_once '../includes/database.php';
require_once '../includes/result_functions.php';

$actionType = $_POST['action_type'] ?? 'update';
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);

$officeTimeData = $_POST['officeTimeData'] ?? [];
if (!is_array($officeTimeData)) {
    // JSON文字列の場合にデコードを試みる
    $officeTimeData = json_decode($officeTimeData, true) ?? [];
}

// ★ 修正: revenues と hourly_rate を追加 (outlook_update.php L18-L20)
$detailAmounts = $_POST['amounts'] ?? [];
$revenueAmounts = $_POST['revenues'] ?? [];
$hourly_rate = $_POST['hourly_rate'] ?? 0;
$resultId = $_POST['result_id'] ?? null;

$dbh = getDb();
$dbh->beginTransaction();

try {
    // ★ 修正: フォームデータ構造をDB関数が期待する形式に再構築 (outlook_update.php L26-L36)
    $data = [
        'result_id' => $resultId,
        'year' => $year,
        'month' => $month,
        'hourly_rate' => $hourly_rate, // 共通賃率
        'officeTimeData' => $officeTimeData, // 時間・人数
        'amounts' => $detailAmounts,      // 経費
        'revenues' => $revenueAmounts      // 収入
    ];

    // ★ 修正: $message を設定 (outlook_update.php L39-L47)
    if ($actionType === 'fixed') {
        // 確定処理: updateのみ（Resultは最終工程のため、次の工程への反映は不要）
        confirmMonthlyResult($data, $dbh);
        $message = "概算実績の確定が完了しました。"; // ★ 修正 (反映がない)
    } else {
        // 修正処理: updateのみ
        updateMonthlyResult($data, $dbh);
        $message = "概算実績の更新が完了しました。";
    }

    $dbh->commit();

    // ★ 修正: 成功リダイレクト (msg パラメータを使用)
    $redirectUrl = "result_edit.php?success=1&year={$year}&month={$month}&msg=" . urlencode($message);
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    $dbh->rollBack();
    // エラーリダイレクト
    $errorMessage = str_replace(['予定', '月末見込み', '見通し'], '概算実績', $e->getMessage()); // ★ '見通し'も追加
    $redirectUrl = "result_edit.php?error=" . urlencode("登録エラー: " . $errorMessage) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
