<?php
require_once '../includes/database.php';
require_once '../includes/plan_functions.php';
require_once '../includes/logger.php';

// セッション開始（権限チェック用）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userRole = $_SESSION['role'] ?? 'viewer';
$isAdmin = ($userRole === 'admin');

$actionType = $_POST['action_type'] ?? 'update';
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$planId = $_POST['plan_id'] ?? null;
$updatedAt = $_POST['updated_at'] ?? null;

$dbh = getDb();
$dbh->beginTransaction();

try {
    // --------------------------------------------------------
    // A. 管理者アクション (月次確定 / ロック解除 / 差し戻し)
    // --------------------------------------------------------
    if ($actionType === 'parent_fix' || $actionType === 'parent_unlock' || $actionType === 'reject') {
        if (!$isAdmin) {
            throw new Exception("権限がありません。");
        }
        if (!$planId) {
            throw new Exception("対象データが見つかりません。");
        }

        if ($actionType === 'parent_fix') {
            // 親ステータスを fixed にし、Outlookへ反映
            fixParentPlan((int)$planId, $dbh);
            $msg = "{$year}年{$month}月を確定(Fixed)し、月末見込み(Outlook)へ反映しました。";
        } elseif ($actionType === 'parent_unlock') {
            // 親ステータスを draft に (ロック解除)
            unlockParentPlan((int)$planId, $dbh);
            $msg = "{$year}年{$month}月のロックを解除しました。";
        } elseif ($actionType === 'reject') {
            // 指定した営業所のデータを差し戻し
            $targetOfficeId = $_POST['target_office_id'] ?? null;
            if (!$targetOfficeId) {
                throw new Exception("差し戻し対象の営業所が指定されていません。");
            }
            rejectMonthlyPlan((int)$planId, (int)$targetOfficeId, $dbh);
            $msg = "指定した営業所のデータを差し戻しました。";
        }

        $dbh->commit();
        header("Location: plan_edit.php?year={$year}&month={$month}&success=1&msg=" . urlencode($msg));
        exit;
    }

    // --------------------------------------------------------
    // B. 通常の更新 / 確定 (Manager/Admin)
    // --------------------------------------------------------

    // データ準備
    $officeTimeData = $_POST['officeTimeData'] ?? [];
    if (!is_array($officeTimeData)) {
        $officeTimeData = json_decode($officeTimeData, true) ?? [];
    }

    $detailAmounts = $_POST['amounts'] ?? [];
    $revenueAmounts = $_POST['revenues'] ?? [];

    if (!empty($_POST['bulk_json_data'])) {
        $bulkData = json_decode($_POST['bulk_json_data'], true);
        if (is_array($bulkData)) {
            if (isset($bulkData['revenues']) && is_array($bulkData['revenues'])) {
                $revenueAmounts = $bulkData['revenues'];
            }
            if (isset($bulkData['amounts']) && is_array($bulkData['amounts'])) {
                $detailAmounts = $bulkData['amounts'];
            }
        }
    }

    // 共通賃率
    $hourly_rate_input = $_POST['hidden_hourly_rate'] ?? '';
    $hourly_rate = ($hourly_rate_input !== '') ? (float)$hourly_rate_input : null;

    $data = [
        'plan_id' => $planId,
        'year' => $year,
        'month' => $month,
        'hourly_rate' => $hourly_rate,
        'officeTimeData' => $officeTimeData,
        'amounts' => $detailAmounts,
        'revenues' => $revenueAmounts,
        'updated_at' => $updatedAt,
        'target_office_id' => $_POST['target_office_id'] ?? null // どの営業所の操作か
    ];

    if ($actionType === 'fixed') {
        // 通常更新 (保存) + 確定 + ログ記録
        confirmMonthlyPlan($data, $dbh);
        $message = "データを確定しました。";
    } else {
        // 通常修正 (update) + ログ記録
        updateMonthlyPlan($data, $dbh);
        $message = "データを更新しました。";
    }

    $dbh->commit();

    // 成功リダイレクト
    $redirectUrl = "plan_edit.php?success=1&year={$year}&month={$month}&msg=" . urlencode($message);
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    // エラーリダイレクト
    $redirectUrl = "plan_edit.php?error=" . urlencode("登録エラー: " . $e->getMessage()) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
