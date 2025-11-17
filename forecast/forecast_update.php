<?php
require_once '../includes/database.php';
require_once '../includes/forecast_functions.php';

$actionType = $_POST['action_type'] ?? 'update';
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);

$officeTimeData = $_POST['officeTimeData'] ?? [];
if (!is_array($officeTimeData)) {
    // JSON文字列の場合にデコードを試みる
    $officeTimeData = json_decode($officeTimeData, true) ?? [];
}

// ★ 修正: 経費(amounts)、収入(revenues)、賃率(hourly_rate) を正しく取得
$detailAmounts = $_POST['amounts'] ?? [];
$revenueAmounts = $_POST['revenues'] ?? [];
$hourly_rate = $_POST['hourly_rate'] ?? 0;

$monthlyForecastId = $_POST['monthly_forecast_id'] ?? null;


$dbh = getDb();
$dbh->beginTransaction();

try {
    // ★ 修正: DB関数に渡す $data 配列を正しく構築
    $data = [
        'monthly_forecast_id' => $monthlyForecastId,
        'year' => $year,
        'month' => $month,
        'hourly_rate' => $hourly_rate, // 共通賃率
        'officeTimeData' => $officeTimeData, // 時間・人数
        'amounts' => $detailAmounts,    // 経費
        'revenues' => $revenueAmounts    // 収入
    ];

    if ($actionType === 'fixed') {
        // 確定処理: update後にplanへ反映
        confirmMonthlyForecast($data, $dbh);
        $message = "見通しの確定・予定への反映が完了しました。";
    } else {
        // 修正処理: updateのみ
        updateMonthlyForecast($data, $dbh);
        $message = "見通しの更新が完了しました。";
    }

    $dbh->commit();

    // 成功リダイレクト
    $redirectUrl = "forecast_edit.php?success=1&year={$year}&month={$month}&msg=" . urlencode($message);
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    $dbh->rollBack();
    // エラーリダイレクト
    $redirectUrl = "forecast_edit.php?error=" . urlencode("登録エラー: " . $e->getMessage()) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
