<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit index</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-primary p-0" data-bs-theme="dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">採算表</a>
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
    <?php
    require_once '../includes/database.php';

    try {
        $dbh = getDb();
        $sql = "SELECT * FROM accounts";
        $stmt = $dbh->prepare($sql);
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo "データ取得失敗: " . $e->getMessage();
        exit;
    }
    ?>
    <div class="container mt-5">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($_SESSION['success']); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <h2>勘定科目一覧</h2>
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>勘定科目名</th>
                    <th>一意識別子</th>
                    <th>説明</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td><?= htmlspecialchars($account['id']) ?></td>
                        <td><?= htmlspecialchars($account['name']) ?></td>
                        <td><?= htmlspecialchars($account['identifier']) ?></td>
                        <td><?= htmlspecialchars($account['note']) ?></td>
                        <td>
                            <!-- 編集ボタン -->
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal"
                                data-bs-target="#editModal-<?= $account['id'] ?>">編集</button>
                            <!-- 削除ボタン -->
                            <button
                                class="btn btn-sm btn-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteModal"
                                data-id="<?php echo htmlspecialchars($account['id']); ?>"
                                data-name="<?php echo htmlspecialchars($account['name']); ?>">
                                削除
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- 編集ボタンmodal -->
    <?php foreach ($accounts as $account): ?>
        <div class="modal fade" id="editModal-<?= $account['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel-<?= $account['id'] ?>" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="update_account.php" method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editModalLabel-<?= $account['id'] ?>">勘定科目の編集</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="account_id" value="<?= $account['id'] ?>"> <!-- 編集対象ID -->
                            <div class="mb-3">
                                <label for="editAccountName-<?= $account['id'] ?>" class="form-label">勘定科目名</label>
                                <input type="text" class="form-control" id="editAccountName-<?= $account['id'] ?>" name="account_name" value="<?= htmlspecialchars($account['name']) ?>"
                                    required>
                            </div>
                            <div class="mb-3">
                                <label for="editAccountIdentifier-<?= $account['id'] ?>" class="form-label">一意識別子</label>
                                <input type="text" class="form-control" id="editAccountIdentifier-<?= $account['id'] ?>" name="account_identifier"
                                    value="<?= htmlspecialchars($account['identifier']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="editDescription-<?= $account['id'] ?>" class="form-label">説明 (任意)</label>
                                <textarea class="form-control" id="editDescription-<?= $account['id'] ?>" name="description"
                                    rows="3"><?= htmlspecialchars($account['note']) ?></textarea>
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
    <?php endforeach; ?>
    <!-- 削除ボタンmodal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="delete_account.php" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">削除確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="account_id" id="deleteAccountId" value="">
                        本当に <span id="deleteAccountName"></span> を削除してもよろしいですか？
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">はい</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz"
        crossorigin="anonymous">
    </script>
    <script>
        const deleteModal = document.getElementById('deleteModal');
        deleteModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const accountId = button.getAttribute('data-id');
            const accountName = button.getAttribute('data-name');

            const deleteAccountIdInput = deleteModal.querySelector('#deleteAccountId');
            const deleteAccountNameSpan = deleteModal.querySelector('#deleteAccountName');

            deleteAccountIdInput.value = accountId;
            deleteAccountNameSpan.textContent = accountName;
        });
    </script>
</body>

</html>