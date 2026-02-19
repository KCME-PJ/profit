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
// role = viewerなら強制終了
if ($userRole === 'viewer') {
    die("エラー: 閲覧専用アカウント(Viewer)ではデータの更新・保存はできません。");
}

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

        // ============================================================
        // 依存関係チェック (Dependency Check) - 厳格版
        // Plan操作時に、後続の Outlook が確定済みでないか厳密に確認する
        // ============================================================

        // A. 親レベルのチェック (ロック解除時)
        if ($actionType === 'parent_unlock') {
            // 1. Outlookの親データを取得
            $stmtCheckNext = $dbh->prepare("SELECT id, status FROM monthly_outlook WHERE year = ? AND month = ? LIMIT 1");
            $stmtCheckNext->execute([$year, $month]);
            $nextData = $stmtCheckNext->fetch(PDO::FETCH_ASSOC);

            if ($nextData) {
                // (1) Outlookが全社確定(fixed)されている場合 -> NG
                if ($nextData['status'] === 'fixed') {
                    throw new Exception("後続の「月末見込み」が既に全社確定されています。整合性を保つため、先に「月末見込み」のロックを解除してください。");
                }

                // (2) Outlookの各営業所データ(子)にFixedが1つでも含まれる場合 -> NG
                $stmtCheckChild = $dbh->prepare("SELECT COUNT(*) FROM monthly_outlook_time WHERE monthly_outlook_id = ? AND status = 'fixed'");
                $stmtCheckChild->execute([$nextData['id']]);
                $fixedCount = $stmtCheckChild->fetchColumn();

                if ($fixedCount > 0) {
                    throw new Exception("後続の「月末見込み」において、既に確定済みの営業所が {$fixedCount} 件存在します。\n整合性を保つため、「予定」を解除する前に、「月末見込み」側でそれらの営業所を差し戻してください。");
                }
            }
        }

        // B. 営業所レベルのチェック (差し戻し時)
        if ($actionType === 'reject') {
            $targetOfficeId = $_POST['target_office_id'] ?? null;
            if (!$targetOfficeId) {
                throw new Exception("差し戻し対象の営業所が指定されていません。");
            }

            // 1. Outlookの親IDを取得
            $stmtOutlookId = $dbh->prepare("SELECT id FROM monthly_outlook WHERE year = ? AND month = ? LIMIT 1");
            $stmtOutlookId->execute([$year, $month]);
            $outlookId = $stmtOutlookId->fetchColumn();

            if ($outlookId) {
                // 2. その営業所のステータスを確認
                $stmtOutlookOffice = $dbh->prepare("SELECT status FROM monthly_outlook_time WHERE monthly_outlook_id = ? AND office_id = ?");
                $stmtOutlookOffice->execute([$outlookId, $targetOfficeId]);
                $outlookOfficeStatus = $stmtOutlookOffice->fetchColumn();

                if ($outlookOfficeStatus === 'fixed') {
                    throw new Exception("この営業所の「月末見込み」データが既に確定されています。整合性を保つため、先に見込み側の該当営業所を差し戻し(修正状態に)してください。");
                } elseif ($outlookOfficeStatus === 'draft') {
                    // OutlookがDraftなら、Plan差し戻しと同時に「Outlook」を削除する
                    // Outlook Time削除
                    $dbh->prepare("DELETE FROM monthly_outlook_time WHERE monthly_outlook_id = ? AND office_id = ?")
                        ->execute([$outlookId, $targetOfficeId]);

                    // Outlook Details削除
                    $dbh->prepare("DELETE od FROM monthly_outlook_details od INNER JOIN details d ON od.detail_id = d.id WHERE od.outlook_id = ? AND d.office_id = ?")
                        ->execute([$outlookId, $targetOfficeId]);

                    // Outlook Revenues削除
                    $dbh->prepare("DELETE orv FROM monthly_outlook_revenues orv INNER JOIN revenue_items r ON orv.revenue_item_id = r.id WHERE orv.outlook_id = ? AND r.office_id = ?")
                        ->execute([$outlookId, $targetOfficeId]);

                    // もし今回の削除で「子データ(Time)」が1件もなくなったら、「親データ(monthly_outlook)」自体を削除する
                    $stmtCount = $dbh->prepare("SELECT COUNT(*) FROM monthly_outlook_time WHERE monthly_outlook_id = ?");
                    $stmtCount->execute([$outlookId]);
                    if ($stmtCount->fetchColumn() == 0) {

                        // 親レコードを削除
                        $dbh->prepare("DELETE FROM monthly_outlook WHERE id = ?")->execute([$outlookId]);
                    }
                }
            }
        }

        // ============================================================
        // アクション実行 (関数呼び出し)
        // ※ログ記録は各関数内で行うため、ここでは記述しない
        // ============================================================

        if ($actionType === 'parent_fix') {
            // 親ステータスを fixed にし、Outlookへ反映
            fixParentPlan((int)$planId, $dbh);
            $msg = "{$year}年{$month}月を確定し、月末見込みへ反映しました。";
        } elseif ($actionType === 'parent_unlock') {
            // 親ステータスを draft に (ロック解除)
            unlockParentPlan((int)$planId, $dbh);
            $msg = "{$year}年{$month}月のロックを解除しました。";
        } elseif ($actionType === 'reject') {
            // 指定した営業所のデータを差し戻し
            if (empty($targetOfficeId)) {
                $targetOfficeId = $_POST['target_office_id'] ?? null;
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
