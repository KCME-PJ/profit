<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';
require_once '../includes/logger.php';

require_once '../includes/cp_functions.php';
require_once '../includes/forecast_functions.php';
require_once '../includes/plan_functions.php';
require_once '../includes/outlook_functions.php';
require_once '../includes/result_functions.php';

// 1. 管理者権限チェック
if (($_SESSION['role'] ?? '') !== 'admin') {
    die("アクセス権限がありません。");
}

// 2. POSTデータ取得
$log_id = $_POST['log_id'] ?? null;
if (!$log_id) {
    header("Location: logs.php?error=" . urlencode("ログIDが指定されていません。"));
    exit;
}

$dbh = getDb();

try {
    // 3. ログデータを取得
    $stmt = $dbh->prepare("SELECT phase, action, content FROM audit_logs WHERE id = ?");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log || empty($log['content'])) {
        throw new Exception("ログデータが見つかりません、またはデータが空です。");
    }

    // JSONデコード
    $restoreData = json_decode($log['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSONデータの解析に失敗しました。");
    }

    // ========================================================
    // 排他制御(updated_atチェック)を回避する
    // ========================================================
    $restoreData['updated_at'] = '';

    // ========================================================
    // ★追加: ログ用のメタデータ注入
    // ========================================================
    $restoreData['msg'] = 'データ復元 (Restored from Log ID: ' . $log_id . ')';

    // audit_logsテーブルのoffice_idカラムに入るように明示的にセット
    if (!empty($restoreData['target_office_id'])) {
        $restoreData['office_id'] = $restoreData['target_office_id'];
    }

    // ========================================================
    // ユーザーコンテキストの作成 (Adminガード回避用)
    // ========================================================
    $userContext = [
        'user_id'   => $_SESSION['user_id'] ?? 0,
        'office_id' => $restoreData['target_office_id'] ?? null,
        'role'      => 'system_restore', // 特権モード
        'username'  => $_SESSION['username'] ?? 'admin'
    ];

    // 4. フェーズに応じて復元処理を分岐
    $dbh->beginTransaction();
    $msg = "";

    switch ($log['phase']) {
        case 'cp':
            updateMonthlyCp($restoreData, $dbh, $userContext);
            $msg = "計画(CP)";
            break;

        case 'forecast':
            updateMonthlyForecast($restoreData, $dbh);
            $msg = "見通し(Forecast)";
            break;

        case 'plan':
            updateMonthlyPlan($restoreData, $dbh);
            $msg = "予定(Plan)";
            break;

        case 'outlook':
            updateMonthlyOutlook($restoreData, $dbh);
            $msg = "月末見込み(Outlook)";
            break;

        case 'result':
            updateMonthlyResult($restoreData, $dbh);
            $msg = "概算実績(Result)";
            break;

        default:
            throw new Exception("復元に対応していないフェーズです: " . htmlspecialchars($log['phase']));
    }

    $dbh->commit();

    // 成功時
    $successMsg = "ログID:{$log_id} の内容で {$msg} を復元しました。";
    header("Location: logs.php?success=" . urlencode($successMsg));
    exit;
} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    // エラー時
    $errorMsg = "復元エラー: " . $e->getMessage();
    header("Location: logs.php?error=" . urlencode($errorMsg));
    exit;
}
