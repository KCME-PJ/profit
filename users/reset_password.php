<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';
require_once '../includes/logger.php';

// 1. 権限チェック
if ($_SESSION['role'] !== 'admin') {
    die("アクセス権限がありません。");
}

// 2. リクエストメソッドチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$id = $_POST['id'] ?? null;

if (!$id) {
    header("Location: index.php?error=IDが指定されていません");
    exit;
}

try {
    $dbh = getDb();

    // 3. 対象ユーザー情報の取得 (usernameが必要)
    $stmt = $dbh->prepare("SELECT username, last_name, first_name FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("ユーザーが見つかりません。");
    }

    $username = $user['username'];

    // 4. 初期パスワードの生成 (usernameの下6桁)
    // usernameが6文字未満の場合はそのまま使用
    $newPasswordPlain = (strlen($username) > 6) ? substr($username, -6) : $username;

    // ハッシュ化
    $hash = password_hash($newPasswordPlain, PASSWORD_DEFAULT);

    // 5. 更新実行
    $updateStmt = $dbh->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$hash, $id]);

    // 6. 監査ログの記録
    // logAudit($dbh, $phase, $target_id, $action, $content)
    // users操作なので phase='users', target_id=$id とします
    if (function_exists('logAudit')) {
        $logContent = [
            'msg' => "Password reset by admin",
            'target_user' => $user['last_name'] . ' ' . $user['first_name'] . ' (' . $username . ')'
        ];
        logAudit($dbh, 'users', $id, 'pw_reset', $logContent);
    }

    $msg = "パスワードを初期化しました。\n初期パスワード: " . $newPasswordPlain;
    header("Location: index.php?success=" . urlencode($msg));
} catch (Exception $e) {
    header("Location: index.php?error=" . urlencode("処理に失敗しました: " . $e->getMessage()));
}
