<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';
require_once '../includes/cp_functions.php';

// 1. ユーザーコンテキスト
$userContext = [
    'user_id'   => $_SESSION['user_id'] ?? 0,
    'office_id' => $_SESSION['office_id'] ?? null,
    'role'      => $_SESSION['role'] ?? 'viewer',
    'username'  => $_SESSION['username'] ?? 'unknown'
];

// role = viewerなら強制終了
if (($userContext['role'] ?? 'viewer') === 'viewer') {
    die("エラー: 閲覧専用アカウント(Viewer)ではデータの更新・保存はできません。");
}

// 2. POSTデータ取得
$actionType = $_POST['cpMode'] ?? 'update'; // update, fixed, reject, parent_fix, parent_unlock
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$monthlyCpId = $_POST['monthly_cp_id'] ?? null;
$postedTargetOffice = $_POST['target_office_id'] ?? null;

// 全社(all)が選択されている場合、
// 「全社確定(parent_fix)」と「ロック解除(parent_unlock)」以外の操作（通常の更新や修正）であれば強制停止
if ($postedTargetOffice === 'all' && $actionType !== 'parent_fix' && $actionType !== 'parent_unlock') {
    $errorMsg = "エラー: 「全社」表示中は更新・確定操作を行えません。自身の営業所を選択してください。";
    $redirectUrl = "cp_edit.php?error=" . urlencode($errorMsg) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}

// ターゲット営業所ID
$targetOfficeId = null;

// Adminの場合の特殊処理
if (($userContext['role'] ?? '') === 'admin') {
    // 親ステータス操作の場合は target_office_id は不要
    if ($actionType !== 'parent_fix' && $actionType !== 'parent_unlock') {
        $targetOfficeId = $_POST['target_office_id'] ?? null;
        if (empty($targetOfficeId)) {
            $errorMsg = "エラー: 更新対象の営業所が特定できませんでした。";
            $redirectUrl = "cp_edit.php?error=" . urlencode($errorMsg) . "&year={$year}&month={$month}";
            header("Location: {$redirectUrl}");
            exit;
        }
    }
} else {
    $targetOfficeId = $userContext['office_id'];
}

// データ整理
$officeTimeData = $_POST['officeTimeData'] ?? [];
if (!is_array($officeTimeData)) $officeTimeData = json_decode($officeTimeData, true) ?? [];

$detailAmounts = $_POST['amounts'] ?? [];
$revenueAmounts = $_POST['revenues'] ?? [];

if (!empty($_POST['bulkJsonData'])) {
    $bulkData = json_decode($_POST['bulkJsonData'], true);
    if (is_array($bulkData)) {
        if (isset($bulkData['revenues'])) $revenueAmounts = $bulkData['revenues'];
        if (isset($bulkData['amounts'])) $detailAmounts = $bulkData['amounts'];
    }
}

$hourly_rate_input = $_POST['hidden_hourly_rate'] ?? '';
$hourly_rate = ($hourly_rate_input !== '') ? (float)$hourly_rate_input : null;

// 3. 処理実行
$dbh = getDb();
$dbh->beginTransaction();

try {
    // ============================================================
    // 依存関係チェック (Dependency Check) - 厳格版
    // CP操作時に、後続の Forecast が確定済みでないか厳密に確認する
    // ============================================================

    // A. 親レベルのチェック (ロック解除時)
    if ($actionType === 'parent_unlock') {
        // 1. Forecastの親データを取得
        $stmtCheckNext = $dbh->prepare("SELECT id, status FROM monthly_forecast WHERE year = ? AND month = ? LIMIT 1");
        $stmtCheckNext->execute([$year, $month]);
        $nextData = $stmtCheckNext->fetch(PDO::FETCH_ASSOC);

        if ($nextData) {
            // (1) Forecastが全社確定(fixed)されている場合 -> NG
            if ($nextData['status'] === 'fixed') {
                throw new Exception("後続の「見通し」が既に全社確定されています。整合性を保つため、先に「見通し」のロックを解除してください。");
            }

            // (2) Forecastの各営業所データ(子)にFixedが1つでも含まれる場合 -> NG
            // 親がDraftでも、子がFixedならロック解除は危険なため禁止する
            $stmtCheckChild = $dbh->prepare("SELECT COUNT(*) FROM monthly_forecast_time WHERE monthly_forecast_id = ? AND status = 'fixed'");
            $stmtCheckChild->execute([$nextData['id']]);
            $fixedCount = $stmtCheckChild->fetchColumn();

            if ($fixedCount > 0) {
                throw new Exception("後続の「見通し」において、既に確定済みの営業所が {$fixedCount} 件存在します。\n整合性を保つため、「CP」をUnlockする前に、「見通し」側でそれらの営業所を差し戻してください。");
            }
        }
    }

    // B. 営業所レベルのチェック (差し戻し時)
    if ($actionType === 'reject' && $targetOfficeId) {
        // 1. Forecastの親IDを取得
        $stmtFcId = $dbh->prepare("SELECT id FROM monthly_forecast WHERE year = ? AND month = ? LIMIT 1");
        $stmtFcId->execute([$year, $month]);
        $forecastId = $stmtFcId->fetchColumn();

        if ($forecastId) {
            // 2. その営業所のステータスを確認
            $stmtFcOffice = $dbh->prepare("SELECT status FROM monthly_forecast_time WHERE monthly_forecast_id = ? AND office_id = ?");
            $stmtFcOffice->execute([$forecastId, $targetOfficeId]);
            $fcOfficeStatus = $stmtFcOffice->fetchColumn();

            if ($fcOfficeStatus === 'fixed') {
                throw new Exception("この営業所の「見通し」データが既に確定されています。整合性を保つため、先に「見通し」側の該当営業所を差し戻し(修正可能状態に)してください。");
            } elseif ($fcOfficeStatus === 'draft') {
                // forecastがDraftなら、CP差し戻しと同時に「見通し」を削除する                
                // Forecast Time削除
                $dbh->prepare("DELETE FROM monthly_forecast_time WHERE monthly_forecast_id = ? AND office_id = ?")
                    ->execute([$forecastId, $targetOfficeId]);

                // Forecast Details削除
                $dbh->prepare("DELETE fd FROM monthly_forecast_details fd INNER JOIN details d ON fd.detail_id = d.id WHERE fd.forecast_id = ? AND d.office_id = ?")
                    ->execute([$forecastId, $targetOfficeId]);

                // Forecast Revenues削除
                $dbh->prepare("DELETE fr FROM monthly_forecast_revenues fr INNER JOIN revenue_items r ON fr.revenue_item_id = r.id WHERE fr.forecast_id = ? AND r.office_id = ?")
                    ->execute([$forecastId, $targetOfficeId]);

                // もし今回の削除で「子データ(Time)」が1件もなくなったら、「親データ(monthly_forecast)」自体を削除する
                $stmtCount = $dbh->prepare("SELECT COUNT(*) FROM monthly_forecast_time WHERE monthly_forecast_id = ?");
                $stmtCount->execute([$forecastId]);
                if ($stmtCount->fetchColumn() == 0) {

                    // 親レコードを削除
                    $dbh->prepare("DELETE FROM monthly_forecast WHERE id = ?")->execute([$forecastId]);
                }
            }
        }
    }

    // ============================================================
    // データ構築・更新処理
    // ============================================================
    $data = [
        'monthly_cp_id' => $monthlyCpId,
        'year' => $year,
        'month' => $month,
        'hourly_rate' => $hourly_rate,
        'officeTimeData' => $officeTimeData,
        'amounts' => $detailAmounts,
        'revenues' => $revenueAmounts,
        'updated_at' => $_POST['updated_at'] ?? null,
        'target_office_id' => $targetOfficeId,
        'action_type' => $actionType
    ];

    $message = "";

    // アクション分岐
    if ($actionType === 'parent_fix') {
        // 全社確定 (Adminのみ)
        updateParentStatus($data, 'fixed', $dbh, $userContext);
        $message = "月次締め（全社確定）が完了しました。";
    } elseif ($actionType === 'parent_unlock') {
        // ロック解除 (Adminのみ)
        updateParentStatus($data, 'draft', $dbh, $userContext);
        $message = "月のロックを解除しました。";
    } elseif ($actionType === 'reject') {
        // 差し戻し (Adminのみ)
        rejectMonthlyCp($data, $dbh, $userContext);
        $message = "データを差し戻しました（Draftへ変更）。";
    } elseif ($actionType === 'fixed') {
        // 個別確定 (Manager)
        confirmMonthlyCp($data, $dbh, $userContext);
        $message = "CPの確定が完了しました。";
    } else {
        // 通常更新 (Manager)
        updateMonthlyCp($data, $dbh, $userContext);
        $message = "CPの更新が完了しました。";
    }

    $dbh->commit();

    // 成功時
    $redirectUrl = "cp_edit.php?success=1&year={$year}&month={$month}&msg=" . urlencode($message);
    header("Location: {$redirectUrl}");
    exit;
} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    // エラー時
    $errorMsg = "エラー: " . $e->getMessage();
    $redirectUrl = "cp_edit.php?error=" . urlencode($errorMsg) . "&year={$year}&month={$month}";
    header("Location: {$redirectUrl}");
    exit;
}
