<?php
require_once '../includes/auth_check.php';
$session_error = $_SESSION['error'] ?? null;
$session_success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);

require_once '../includes/database.php';

try {
    $dbh = getDb();

    // 収入項目リストの取得 (親カテゴリと営業所をJOIN)
    $sql = "SELECT i.id, i.name AS item_name, i.note, i.sort_order,
                   i.revenue_category_id, i.office_id, 
                   c.name AS category_name, o.name AS office_name
            FROM revenue_items i
            JOIN revenue_categories c ON i.revenue_category_id = c.id
            JOIN offices o ON i.office_id = o.id
            ORDER BY 
                c.sort_order ASC,   /* 1. カテゴリの表示順 */
                i.sort_order ASC,   /* 2. 項目の表示順 */
                o.id ASC            /* 3. 営業所ID */
            ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // モーダルのセレクトボックス用データ取得
    $stmtCats = $dbh->query("SELECT id, name FROM revenue_categories ORDER BY sort_order ASC, id ASC");
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

    $stmtOffices = $dbh->query("SELECT id, name FROM offices ORDER BY id ASC");
    $offices = $stmtOffices->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("エラー: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>収入項目一覧 - Profit index</title>

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
                            <li><a class="dropdown-item" href="../offices/office_list.php">係リスト</a></li>
                            <li><a class="dropdown-item" href="./revenue_category_list.php">収入カテゴリリスト</a></li>
                            <li><a class="dropdown-item" href="./revenue_item_list.php">収入項目リスト</a></li>
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

    <div class="container-fluid mt-3 px-5">
        <?php if ($session_error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($session_error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($session_success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($session_success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">収入項目リスト</h4>
            <div>
                <a href="revenue_item.php" class="btn btn-primary btn-sm me-2"><i class="bi bi-plus-lg"></i> 新規登録</a>
                <button id="resetStateBtn" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> 初期状態に戻す
                </button>
            </div>
        </div>

        <table id="itemTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th style="width: 50px;">表示順</th>
                    <th>収入項目名</th>
                    <th>説明(備考)</th>
                    <th style="width: 150px;">収入カテゴリ</th>
                    <th style="width: 100px;">営業所名</th>
                    <th style="width: 120px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['id']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($item['sort_order']) ?></td>
                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                        <td><?= htmlspecialchars($item['note']) ?></td>
                        <td><?= htmlspecialchars($item['category_name']) ?></td>
                        <td><?= htmlspecialchars($item['office_name']) ?></td>
                        <td>
                            <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal-<?= $item['id'] ?>">編集</button>
                            <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal"
                                data-id="<?= htmlspecialchars($item['id']) ?>"
                                data-name="<?= htmlspecialchars($item['item_name']) ?>">
                                削除
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($items as $item): ?>
        <div class="modal fade" id="editModal-<?= $item['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="edit_revenue_item.php" method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">収入項目の編集</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">

                            <div class="mb-3">
                                <label class="form-label">収入項目名</label>
                                <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($item['item_name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">収入カテゴリ</label>
                                <select class="form-select" name="revenue_category_id" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $item['revenue_category_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">営業所</label>
                                <select class="form-select" name="office_id" required>
                                    <?php foreach ($offices as $off): ?>
                                        <option value="<?= $off['id'] ?>" <?= $off['id'] == $item['office_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($off['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">表示順</label>
                                <input type="number" class="form-control" name="sort_order" value="<?= htmlspecialchars($item['sort_order']) ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">説明 (備考)</label>
                                <textarea class="form-control" name="note" rows="3"><?= htmlspecialchars($item['note']) ?></textarea>
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

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="delete_revenue_item.php" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">削除確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="deleteItemId" value="">
                        本当に <span id="deleteItemName" class="fw-bold"></span> を削除してもよろしいですか？
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">はい、削除します</button>
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
            var table = $('#itemTable').DataTable({
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/ja.json"
                },
                lengthMenu: [
                    [10, 25, 50, -1],
                    [10, 25, 50, "全件"]
                ],
                order: [],
                stateSave: true,
            });

            $('#resetStateBtn').on('click', function() {
                table.state.clear();
                table.search('').columns().search('');
                table.order([]);
                table.page(0);
                table.draw();
                window.location.reload();
            });
        });

        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('deleteItemId').value = button.getAttribute('data-id');
                document.getElementById('deleteItemName').textContent = button.getAttribute('data-name');
            });
        }
    </script>
</body>

</html>