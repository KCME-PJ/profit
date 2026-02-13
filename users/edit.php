<?php
// users/edit.php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

if ($_SESSION['role'] !== 'admin') {
    die("アクセス権限がありません。");
}

$dbh = getDb();
$id = $_GET['id'] ?? null;
$user = [];
$mode = 'create';

// 編集モードの場合、既存データを取得
if ($id) {
    $mode = 'edit';
    $stmt = $dbh->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) die("ユーザーが見つかりません。");
}

// 営業所リスト取得
$offices = $dbh->query("SELECT id, name FROM offices ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>ユーザー編集 | 採算管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><?php echo $mode === 'create' ? 'ユーザー新規登録' : 'ユーザー編集'; ?></h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_GET['error'])): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
                        <?php endif; ?>

                        <form action="update.php" method="POST">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($user['id'] ?? ''); ?>">
                            <input type="hidden" name="mode" value="<?php echo $mode; ?>">

                            <div class="mb-3">
                                <label class="form-label">ログインID（社員番号）<span class="badge bg-danger">必須</span></label>
                                <input type="text" name="username" class="form-control"
                                    value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>"
                                    required <?php echo $mode === 'edit' ? 'readonly style="background-color:#e9ecef;"' : ''; ?>>
                                <?php if ($mode === 'edit'): ?>
                                    <div class="form-text">IDは変更できません。</div>
                                <?php endif; ?>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">姓 <span class="badge bg-danger">必須</span></label>
                                    <input type="text" name="last_name" class="form-control"
                                        value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required placeholder="例: 山田">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">名 <span class="badge bg-danger">必須</span></label>
                                    <input type="text" name="first_name" class="form-control"
                                        value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required placeholder="例: 太郎">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">パスワード</label>
                                <input type="password" name="password" class="form-control" placeholder="<?php echo $mode === 'edit' ? '変更する場合のみ入力してください' : '初期パスワード'; ?>">
                                <?php if ($mode === 'create'): ?>
                                    <div class="form-text text-danger">※新規登録時は必須です。</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">権限ロール <span class="badge bg-danger">必須</span></label>
                                <select name="role" class="form-select" required>
                                    <option value="manager" <?php if (($user['role'] ?? '') === 'manager') echo 'selected'; ?>>営業所長 (Manager)</option>
                                    <option value="viewer" <?php if (($user['role'] ?? '') === 'viewer') echo 'selected'; ?>>閲覧のみ (Viewer)</option>
                                    <option value="admin" <?php if (($user['role'] ?? '') === 'admin') echo 'selected'; ?>>システム管理者 (Admin)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">所属営業所</label>
                                <select name="office_id" class="form-select">
                                    <option value="">なし (全社/本社)</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo $office['id']; ?>"
                                            <?php if (($user['office_id'] ?? '') == $office['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($office['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">管理者の場合は「なし」を推奨します。</div>
                            </div>

                            <?php if ($mode === 'edit'): ?>
                                <div class="mb-4">
                                    <label class="form-label">アカウント状態</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                                            <?php if (!isset($user['is_active']) || $user['is_active']) echo 'checked'; ?>>
                                        <label class="form-check-label" for="isActive">有効 (ログイン可能)</label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary">戻る</a>
                                <button type="submit" class="btn btn-primary">保存する</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>