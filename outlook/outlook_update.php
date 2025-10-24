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

$detailAmounts = $_POST['amounts'] ?? [];
$outlookId = $_POST['outlook_id'] ?? null;

$dbh = getDb();
$dbh->beginTransaction();

try {
    // フォームデータ構造をDB関数が期待する形式に再構築
    $data = [
        'outlook_id' => $outlookId,
        'year' => $year,
        'month' => $month,
        'officeTimeData' => $officeTimeData,
        'amounts' => $detailAmounts,
    ];

    if ($actionType === 'fixed') {
        // 確定処理: update後にresultへ反映
        confirmMonthlyOutlook($data, $dbh);

        // 成功リダイレクト (確定時はメッセージを残す)
        $redirectUrl = "outlook_edit.php?success=1&year={$year}&month={$month}";
    } else {
        // 修正処理: updateのみ
        updateMonthlyOutlook($data, $dbh);

        // 修正リダイレクト (メッセージを残す)
        $redirectUrl = "outlook_edit.php?success=1&year={$year}&month={$month}";
    }

    $dbh->commit();

    // 成功リダイレクト
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    $dbh->rollBack();
    // エラーリダイレクト
    $errorMessage = str_replace('予定', '月末見込み', $e->getMessage());
    $redirectUrl = "outlook_edit.php?error=" . urlencode("登録エラー: " . $errorMessage) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
