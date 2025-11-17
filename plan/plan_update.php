<?php
require_once '../includes/database.php';
require_once '../includes/plan_functions.php';

$actionType = $_POST['action_type'] ?? 'update';
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);

$officeTimeData = $_POST['officeTimeData'] ?? [];
if (!is_array($officeTimeData)) {
    // JSON文字列の場合にデコードを試みる
    $officeTimeData = json_decode($officeTimeData, true) ?? [];
}

// ★ 修正: forecast_update.php L15-L17 を参考に revenues と hourly_rate を追加
$detailAmounts = $_POST['amounts'] ?? [];
$revenueAmounts = $_POST['revenues'] ?? [];
$hourly_rate = $_POST['hourly_rate'] ?? 0;
$planId = $_POST['plan_id'] ?? null;


$dbh = getDb();
$dbh->beginTransaction();

try {
    // ★ 修正: DB関数に渡す $data 配列を正しく構築
    $data = [
        'plan_id' => $planId,
        'year' => $year,
        'month' => $month,
        'hourly_rate' => $hourly_rate, // 共通賃率
        'officeTimeData' => $officeTimeData, // 時間・人数
        'amounts' => $detailAmounts,      // 経費
        'revenues' => $revenueAmounts      // 収入
    ];

    // ★ 修正: forecast_update.php L37-L44 を参考に $message を設定
    if ($actionType === 'fixed') {
        // 確定処理: update後にoutlookへ反映
        confirmMonthlyPlan($data, $dbh);
        $message = "予定の確定・月末見込みへの反映が完了しました。";
    } else {
        // 修正処理: updateのみ
        updateMonthlyPlan($data, $dbh);
        $message = "予定の更新が完了しました。";
    }

    $dbh->commit();

    // ★ 修正: 成功リダイレクト (msg パラメータを使用)
    $redirectUrl = "plan_edit.php?success=1&year={$year}&month={$month}&msg=" . urlencode($message);
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    $dbh->rollBack();
    // エラーリダイレクト (修正なし)
    $redirectUrl = "plan_edit.php?error=" . urlencode("登録エラー: " . str_replace('見通し', '予定', $e->getMessage())) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
