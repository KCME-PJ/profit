<?php
// users/index.php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

// 1. 権限チェック (管理者以外はアクセス拒否)
if ($_SESSION['role'] !== 'admin') {
    die("アクセス権限がありません。");
}

$dbh = getDb();

// 2. ユーザー一覧を取得（営業所名も結合して取得）
$sql = "
    SELECT u.*, o.name as office_name 
    FROM users u
    LEFT JOIN offices o ON u.office_id = o.id
    ORDER BY u.id ASC
";
$stmt = $dbh->query($sql);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>ユーザー管理 | 採算管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">採算管理システム - ユーザー管理</span>
            <div>
                <span class="text-light me-3">
                    <i class="bi bi-person-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['display_name']); ?> さん
                </span>
                <a href="logs.php" class="btn btn-outline-info btn-sm me-2">
                    <i class="bi bi-list-columns"></i> 操作ログ
                </a>
                <a href="../index.html" class="btn btn-outline-light btn-sm">ダッシュボードへ戻る</a>
                <a href="../logout.php" class="btn btn-outline-danger btn-sm">ログアウト</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>ユーザー一覧</h2>
            <a href="edit.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> 新規登録</a>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>ユーザーID</th>
                            <th>氏名</th>
                            <th>権限</th>
                            <th>所属営業所</th>
                            <th>状態</th>
                            <th style="width: 280px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo $u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($u['last_name'] . ' ' . $u['first_name']); ?>
                                </td>
                                <td>
                                    <?php
                                    $roles = [
                                        'admin'   => '<span class="badge bg-dark">管理者</span>',
                                        'manager' => '<span class="badge bg-primary">営業所長</span>',
                                        'viewer'  => '<span class="badge bg-info text-dark">閲覧のみ</span>'
                                    ];
                                    echo $roles[$u['role']] ?? $u['role'];
                                    ?>
                                </td>
                                <td>
                                    <?php echo $u['office_id'] ? htmlspecialchars($u['office_name']) : '<span class="text-muted">全社(本社)</span>'; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge bg-success">有効</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">無効</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="edit.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i> 編集
                                        </a>

                                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                            <?php if ($u['is_active']): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning text-dark"
                                                    onclick="confirmReset(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($u['last_name'] . ' ' . $u['first_name'], ENT_QUOTES); ?>')">
                                                    <i class="bi bi-key"></i> PW初期化
                                                </button>

                                                <a href="delete.php?id=<?php echo $u['id']; ?>"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('このユーザーを無効化しますか？');">
                                                    <i class="bi bi-ban"></i> 無効化
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form id="resetForm" action="reset_password.php" method="POST" style="display:none;">
        <input type="hidden" name="id" id="resetUserId">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmReset(id, username, name) {
            // username(社員番号)の下6桁を取得
            let defaultPass = username;
            if (username.length > 6) {
                defaultPass = username.slice(-6);
            }

            const msg = '【確認】\n' + name + ' (' + username + ') さんのパスワードを初期化しますか？\n\n' +
                '初期パスワードは社員番号の下6桁 [' + defaultPass + '] に設定されます。';

            if (confirm(msg)) {
                document.getElementById('resetUserId').value = id;
                document.getElementById('resetForm').submit();
            }
        }
    </script>
</body>

</html>