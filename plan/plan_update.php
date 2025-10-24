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

$detailAmounts = $_POST['amounts'] ?? [];
$planId = $_POST['plan_id'] ?? null;

$dbh = getDb();
$dbh->beginTransaction();

try {
    // フォームデータ構造をDB関数が期待する形式に再構築
    $data = [
        'plan_id' => $planId,
        'year' => $year,
        'month' => $month,
        'officeTimeData' => $officeTimeData,
        'amounts' => $detailAmounts,
    ];

    if ($actionType === 'fixed') {
        // 確定処理: update後にoutlookへ反映
        confirmMonthlyPlan($data, $dbh);

        // 成功リダイレクト (確定時はメッセージを残す)
        $redirectUrl = "plan_edit.php?success=1&year={$year}&month={$month}";
    } else {
        // 修正処理: updateのみ
        updateMonthlyPlan($data, $dbh);

        // 修正リダイレクト (メッセージを残す)
        $redirectUrl = "plan_edit.php?success=1&year={$year}&month={$month}";
    }

    $dbh->commit();

    // 成功リダイレクト
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    $dbh->rollBack();
    // エラーリダイレクト
    $redirectUrl = "plan_edit.php?error=" . urlencode("登録エラー: " . str_replace('見通し', '予定', $e->getMessage())) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
