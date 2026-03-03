<?php
session_start();
require_once '../includes/auth_check.php';

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);

require_once '../includes/database.php';

try {
    $dbh = getDb();
    $stmt = $dbh->query('SELECT * FROM offices ORDER BY identifier ASC');
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = '営業所データの取得に失敗しました: ' . $e->getMessage();
    $offices = [];
}
?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>係一覧 - Profit index</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-primary p-0" data-bs-theme="dark">
        <div class="container-fluid">
            <a class="navbar-brand dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">採算表</a>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="../index.php"><i class="bi bi-speedometer2 me-2"></i>ダッシュボード</a></li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li><a class="dropdown-item" href="../analysis.php"><i class="bi bi-table me-2"></i>詳細集計</a></li>
            </ul>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false"
                aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">CP</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../cp/cp.php">CP計画</a></li>
                            <li><a class="dropdown-item" href="../cp/cp_edit.php">CP編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">見通し</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../forecast/forecast_edit.php">見通し編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">予定</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../plan/plan_edit.php">予定編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">月末見込み</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../outlook/outlook_edit.php">月末見込み編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">概算</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../result/result_edit.php">概算編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">マスター設定</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../account/account_list.php">勘定科目リスト</a></li>
                            <li><a class="dropdown-item" href="../details/detail_list.php">勘定科目詳細リスト</a></li>
                            <li><a class="dropdown-item" href="./office_list.php">係リスト</a></li>
                            <li><a class="dropdown-item" href="../revenue/revenue_category_list.php">収入カテゴリリスト</a></li>
                            <li><a class="dropdown-item" href="../revenue/revenue_item_list.php">収入項目リスト</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="../users/">ユーザー管理</a></li>
                        </ul>
                    </li>
                </ul>
            </div>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-fill"></i>&nbsp; <?= htmlspecialchars($_SESSION['display_name']) ?> さん
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../profile/password_edit.php">パスワード変更</a></li>
                        <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>
    <div class="container mt-4">

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">係リスト</h4>
            <div>
                <a href="office.php" class="btn btn-primary btn-sm me-2"><i class="bi bi-plus-lg"></i> 新規登録</a>
                <button id="resetStateBtn" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> 初期状態に戻す
                </button>
            </div>
        </div>

        <table id="officeTable" class="table table-striped table-bordered">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>係名</th>
                    <th>コード</th>
                    <th>説明</th>
                    <th style="width: 120px;">操作</th>
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
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal-<?= $office['id'] ?>">
                                修正
                            </button>
                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal"
                                data-bs-target="#deleteModal" data-id="<?= htmlspecialchars($office['id']) ?>"
                                data-name="<?= htmlspecialchars($office['name']) ?>">
                                削除
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($offices as $office): ?>
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
                                <label for="officeIdentifier-<?= $office['id'] ?>" class="form-label">営業所コード</label>
                                <input type="text" class="form-control" id="officeIdentifier-<?= $office['id'] ?>" name="office_identifier"
                                    value="<?= htmlspecialchars($office['identifier']) ?>"
                                    pattern="^[a-zA-Z0-9_-]+$" placeholder="ex: 13E14210" required>
                                <div class="form-text">半角英数字で入力してください。</div>
                            </div>

                            <div class="mb-3">
                                <label for="officeName-<?= $office['id'] ?>" class="form-label">営業所名（係名）</label>
                                <input type="text" class="form-control" id="officeName-<?= $office['id'] ?>" name="office_name"
                                    value="<?= htmlspecialchars($office['name']) ?>" required>
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
    <?php endforeach; ?>

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="delete_office.php" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">削除確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="deleteOfficeId" value="">
                        本当に <span id="deleteOfficeName" class="fw-bold"></span> を削除してもよろしいですか？
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">はい</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            var table = $('#officeTable').DataTable({
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/ja.json"
                },
                lengthMenu: [
                    [10, 25, 50, -1],
                    [10, 25, 50, "全件"]
                ],
                order: [
                    [0, "asc"]
                ],
                stateSave: true,
            });

            // 初期状態に戻すボタン
            $('#resetStateBtn').on('click', function() {
                table.state.clear();
                table.search('').columns().search('');
                table.order([
                    [0, "asc"]
                ]); // ID順に戻す
                table.page(0);
                table.draw();
                window.location.reload();
            });
        });

        // 削除モーダルへIDと名前を渡す
        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const officeId = button.getAttribute('data-id');
                const officeName = button.getAttribute('data-name');

                document.getElementById('deleteOfficeId').value = officeId;
                document.getElementById('deleteOfficeName').textContent = officeName;
            });
        }
    </script>
</body>

</html>