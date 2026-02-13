<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

// 1. 権限チェック (管理者のみ)
if ($_SESSION['role'] !== 'admin') {
    die("アクセス権限がありません。");
}

$id = $_GET['id'] ?? null;

// 2. IDのバリデーション
if (!$id) {
    header("Location: index.php?error=IDが指定されていません");
    exit;
}

// 3. 自分自身は削除(無効化)できないようにする
if ($id == $_SESSION['user_id']) {
    header("Location: index.php?error=自分自身のアカウントを無効化することはできません");
    exit;
}

try {
    $dbh = getDb();

    // 4. 論理削除 (is_active = 0 に更新)
    // 完全に削除したい場合は "DELETE FROM users WHERE id = ?" に変更しますが、
    // 監査ログの整合性を保つため推奨しません。
    $stmt = $dbh->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    header("Location: index.php?success=ユーザーを無効化しました");
} catch (Exception $e) {
    header("Location: index.php?error=" . urlencode("処理に失敗しました: " . $e->getMessage()));
}
