<?php
session_start();
require_once '../includes/database.php';

try {
    $dbh = getDb();

    // 営業所データを取得
    $stmt = $dbh->query('SELECT * FROM offices ORDER BY id ASC');
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = '営業所データの取得に失敗しました。';
    header('Location: office.php');
    exit;
}
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>係一覧</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-primary p-0" data-bs-theme="dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.html">採算表</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false"
                aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            CP
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="./cp/cp.html">2025</a></li>
                            <li><a class="dropdown-item" href="#">2024</a></li>
                            <li><a class="dropdown-item" href="#">2023</a></li>
                            <li><a class="dropdown-item" href="#">2022</a></li>
                            <li><a class="dropdown-item" href="#">2021</a></li>
                            <li><a class="dropdown-item" href="#">2020</a></li>

                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            見通し
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#">見通し入力</a></li>
                            <li><a class="dropdown-item" href="#">Another action</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="#">Something else here</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            予定
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#">予定入力</a></li>
                            <li><a class="dropdown-item" href="#">CP差確認</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="#">Something else here</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            月末見込み
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#">月末見込み入力</a></li>
                            <li><a class="dropdown-item" href="#">予定差確認</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="#">Something else here</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            概算
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="monthly_actual/input_monthly_actual.html">実績入力</a></li>
                            <li><a class="dropdown-item" href="monthly_actual/check_monthly_actual.html">予実確認</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="#">Something else here</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            勘定科目設定
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../account/account_list.php">勘定科目</a></li>
                            <li><a class="dropdown-item" href="../details/detail_list.php">詳細</a></li>
                            <li><a class="dropdown-item" href="./office.php">係登録</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="#">Something else here</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
            <div class="navbar-nav ms-auto">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="bi bi-person-fill"></i>&nbsp;
                            user name さん
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <h2 class="mb-4">係リスト</h2>
        <!-- メッセージ表示 -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- テーブル -->
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>係名</th>
                    <th>一意識別子</th>
                    <th>説明</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offices as $office): ?>
                    <tr>
                        <td><?= htmlspecialchars($office['id']) ?></td>
                        <td><?= htmlspecialchars($office['name']) ?></td>
                        <td><?= htmlspecialchars($office['identifier']) ?></td>
                        <td><?= htmlspecialchars($office['note']) ?></td>
                        <td>
                            <!-- 修正ボタン -->
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal-<?= $office['id'] ?>">
                                修正
                            </button>

                            <!-- 削除ボタン -->
                            <form action="delete_office.php" method="POST" style="display: inline;">
                                <input type="hidden" name="id" value="<?= $office['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('<?= htmlspecialchars($office['name']) ?> を本当に削除して良いですか?');">
                                    削除
                                </button>
                            </form>
                        </td>
                    </tr>

                    <!-- 修正用モーダル -->
                    <div class="modal fade" id="editModal-<?= $office['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel-<?= $office['id'] ?>" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="edit_office.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="editModalLabel-<?= $office['id'] ?>">営業所修正</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="id" value="<?= $office['id'] ?>">
                                        <div class="mb-3">
                                            <label for="officeName-<?= $office['id'] ?>" class="form-label">営業所名</label>
                                            <input type="text" class="form-control" id="officeName-<?= $office['id'] ?>" name="office_name" value="<?= htmlspecialchars($office['name']) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="officeIdentifier-<?= $office['id'] ?>" class="form-label">一意識別子</label>
                                            <input type="text" class="form-control" id="officeIdentifier-<?= $office['id'] ?>" name="office_identifier" value="<?= htmlspecialchars($office['identifier']) ?>" pattern="^[a-zA-Z0-9_-]+$" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="officeNote-<?= $office['id'] ?>" class="form-label">説明 (任意)</label>
                                            <textarea class="form-control" id="officeNote-<?= $office['id'] ?>" name="note" rows="3"><?= htmlspecialchars($office['note']) ?></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                                        <button type="submit" class="btn btn-primary">保存</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- モーダルここまで -->
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>