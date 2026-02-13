<?php
// users/update.php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("アクセス権限がありません。");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$id = $_POST['id'] ?? '';
$mode = $_POST['mode'] ?? 'create';
$username = $_POST['username'] ?? '';
// 氏名(分離)
$last_name = $_POST['last_name'] ?? '';
$first_name = $_POST['first_name'] ?? '';
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'manager';
$office_id = $_POST['office_id'] === '' ? null : $_POST['office_id'];

// バリデーション
if ($mode === 'create' && empty($password)) {
    header("Location: edit.php?error=新規登録時はパスワードが必須です");
    exit;
}
if (empty($last_name) || empty($first_name)) {
    $redirectId = $id ? "?id={$id}" : "";
    header("Location: edit.php{$redirectId}&error=氏名は必須です");
    exit;
}

try {
    $dbh = getDb();

    if ($mode === 'create') {
        // 重複チェック
        $stmtCheck = $dbh->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmtCheck->execute([$username]);
        if ($stmtCheck->fetchColumn() > 0) {
            header("Location: edit.php?error=そのユーザーIDは既に使用されています");
            exit;
        }

        // 新規登録
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (username, password, last_name, first_name, role, office_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([$username, $hash, $last_name, $first_name, $role, $office_id]);

        header("Location: index.php?success=ユーザーを作成しました");
    } else {
        // 更新
        if (empty($id)) throw new Exception("IDが指定されていません");

        // is_active の処理（チェックボックス）
        $isActiveVal = isset($_POST['is_active']) ? 1 : 0;

        $sql = "UPDATE users SET last_name = ?, first_name = ?, role = ?, office_id = ?, is_active = ?";
        $params = [$last_name, $first_name, $role, $office_id, $isActiveVal];

        // パスワード変更がある場合のみ更新
        if (!empty($password)) {
            $sql .= ", password = ?";
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;

        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);

        header("Location: index.php?success=ユーザー情報を更新しました");
    }
} catch (Exception $e) {
    // エラー時は編集画面に戻す
    $redirectUrl = ($mode === 'create') ? 'edit.php' : "edit.php?id={$id}";
    header("Location: {$redirectUrl}&error=" . urlencode($e->getMessage()));
}
