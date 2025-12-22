<?php
require_once '../includes/database.php';
require_once '../includes/cp_functions.php';

$actionType = $_POST['cpMode'] ?? 'update';
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);

$officeTimeData = $_POST['officeTimeData'] ?? [];
if (!is_array($officeTimeData)) {
    // JSON文字列の場合にデコードを試みる
    $officeTimeData = json_decode($officeTimeData, true) ?? [];
}

$detailAmounts = $_POST['amounts'] ?? [];
$revenueAmounts = $_POST['revenues'] ?? [];

// bulkJsonData の受け取り
if (!empty($_POST['bulkJsonData'])) {
    $bulkData = json_decode($_POST['bulkJsonData'], true);
    if (is_array($bulkData)) {
        if (isset($bulkData['revenues']) && is_array($bulkData['revenues'])) {
            $revenueAmounts = $bulkData['revenues'];
        }
        if (isset($bulkData['amounts']) && is_array($bulkData['amounts'])) {
            $detailAmounts = $bulkData['amounts'];
        }
    }
}

// ★★★ 修正: 隠しフィールド (hidden_hourly_rate) から値を受け取る ★★★
$hourly_rate_input = $_POST['hidden_hourly_rate'] ?? '';

// 空文字なら null、値があれば float に変換
$hourly_rate = ($hourly_rate_input !== '') ? (float)$hourly_rate_input : null;

$monthlyCpId = $_POST['monthly_cp_id'] ?? null;

$dbh = getDb();
$dbh->beginTransaction();

try {
    $data = [
        'monthly_cp_id' => $monthlyCpId,
        'year' => $year,
        'month' => $month,
        'hourly_rate' => $hourly_rate, // nullの場合は function 側で既存値を維持
        'officeTimeData' => $officeTimeData,
        'amounts' => $detailAmounts,
        'revenues' => $revenueAmounts
    ];

    if ($actionType === 'fixed') {
        confirmMonthlyCp($data, $dbh);
        $message = "CPの確定・見通しへの反映が完了しました。";
    } else {
        updateMonthlyCp($data, $dbh);
        $message = "CPの更新が完了しました。";
    }

    $dbh->commit();

    // 成功リダイレクト
    $redirectUrl = "cp_edit.php?success=1&year={$year}&month={$month}&msg=" . urlencode($message);
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    $dbh->rollBack();
    // エラーリダイレクト
    $redirectUrl = "cp_edit.php?error=" . urlencode("登録エラー: " . $e->getMessage()) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
