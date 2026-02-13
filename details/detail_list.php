<?php
require_once '../includes/auth_check.php';
$error = $_SESSION['error'] ?? null;
$form_data = $_SESSION['form_data'] ?? null;
// errorは一度セッション変数を受け取ってから消す
unset($_SESSION['error'], $_SESSION['form_data']);
$session_error = $_SESSION['error'] ?? null;
$session_success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']); // 表示用変数に入れたので消す

require_once '../includes/database.php';

try {
    $dbh = getDb();

    $sql = "SELECT details.id, details.name AS detail_name, details.identifier, details.note, details.sort_order,
                   details.account_id, details.office_id, accounts.name AS account_name, offices.name AS office_name
            FROM details
            JOIN accounts ON details.account_id = accounts.id
            LEFT JOIN offices ON details.office_id = offices.id
            ORDER BY 
                accounts.sort_order ASC,   /* 1. 勘定科目 (最優先) */
                details.sort_order ASC,    /* 2. 詳細 (第2優先) */
                offices.identifier ASC     /* 3. 営業所コード (第3優先) */
            ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // accounts テーブルのデータを取得
    $sqlAccounts = "SELECT id, name FROM accounts ORDER BY id ASC";
    $stmtAccounts = $dbh->prepare($sqlAccounts);
    $stmtAccounts->execute();
    $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

    // offices テーブルのデータを取得
    $sqlOffices = "SELECT id, name FROM offices ORDER BY name ASC";
    $stmtOffices = $dbh->prepare($sqlOffices);
    $stmtOffices->execute();
    $offices = $stmtOffices->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("エラー: " . $e->getMessage());
}
?>

<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit index</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
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
                            aria-expanded="false">CP
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../cp/cp.php">CP計画</a></li>
                            <li><a class="dropdown-item" href="../cp/cp_edit.php">CP編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">見通し
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../forecast/forecast_edit.php">見通し編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">予定
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../plan/plan_edit.php">予定編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">月末見込み
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../outlook/outlook_edit.php">月末見込み編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">概算
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../result/result_edit.php">概算編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            勘定科目設定
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../account/account_list.php">勘定科目</a></li>
                            <li><a class="dropdown-item" href="./detail.php">詳細登録</a></li>
                            <li><a class="dropdown-item" href="../offices/office_list.php">係一覧</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="../users/">ユーザー管理</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
            <div class="navbar-nav ms-auto">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="bi bi-person-fill"></i>&nbsp; <?= htmlspecialchars($_SESSION['display_name']) ?> さん
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../profile/password_edit.php">パスワード変更</a></li>
                            <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container-fluid mt-3 px-5">
        <?php if ($session_error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($session_error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>

        <?php if ($session_success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($session_success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">詳細リスト</h4>
            <button id="resetStateBtn" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-counterclockwise"></i> 一覧を初期状態に戻す
            </button>
        </div>

        <table id="detailTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th style="width: 50px;">表示順</th>
                    <th>詳細名</th>
                    <th>一意識別子</th>
                    <th>説明</th>
                    <th style="width: 100px;">勘定科目</th>
                    <th style="width: 100px;">係名</th>
                    <th style="width: 80px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($details as $detail): ?>
                    <tr>
                        <td><?= htmlspecialchars($detail['id']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($detail['sort_order']) ?></td>
                        <td><?= htmlspecialchars($detail['detail_name']) ?></td>
                        <td><?= htmlspecialchars($detail['identifier']) ?></td>
                        <td><?= htmlspecialchars($detail['note']) ?></td>
                        <td><?= htmlspecialchars($detail['account_name']) ?></td>
                        <td><?= htmlspecialchars($detail['office_name']) ?></td>
                        <td>
                            <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal-<?= $detail['id'] ?>">編集</button>

                            <button class="btn btn-danger btn-sm"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteModal"
                                data-id="<?= htmlspecialchars($detail['id']) ?>"
                                data-name="<?= htmlspecialchars($detail['detail_name']) ?>">
                                削除
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($details as $detail): ?>
        <div class="modal fade" id="editModal-<?= $detail['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel-<?= $detail['id'] ?>" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="edit_detail.php" method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editModalLabel-<?= $detail['id'] ?>">詳細の編集</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="id" value="<?= $detail['id'] ?>">
                            <div class="mb-3">
                                <label for="detailName-<?= $detail['id'] ?>" class="form-label">詳細名</label>
                                <input type="text" class="form-control" id="detailName-<?= $detail['id'] ?>" name="detail_name"
                                    value="<?= htmlspecialchars($detail['detail_name']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="detailIdentifier-<?= $detail['id'] ?>" class="form-label">一意識別子</label>
                                <input type="text" class="form-control" id="detailIdentifier-<?= $detail['id'] ?>" name="detail_identifier"
                                    value="<?= htmlspecialchars($detail['identifier']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="detailSortOrder-<?= $detail['id'] ?>" class="form-label">表示順</label>
                                <input type="number" class="form-control" id="detailSortOrder-<?= $detail['id'] ?>" name="sort_order"
                                    value="<?= htmlspecialchars($detail['sort_order']) ?>">
                            </div>

                            <div class="mb-3">
                                <label for="detailNote-<?= $detail['id'] ?>" class="form-label">説明 (任意)</label>
                                <textarea class="form-control" id="detailNote-<?= $detail['id'] ?>" name="note" rows="3"><?= htmlspecialchars($detail['note']) ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="detailAccount-<?= $detail['id'] ?>" class="form-label">勘定科目</label>
                                <select class="form-select" id="detailAccount-<?= $detail['id'] ?>" name="account_id" required>
                                    <option value="<?= htmlspecialchars($detail['account_id']) ?>" selected>
                                        <?= htmlspecialchars($detail['account_name']) ?>
                                    </option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?= $account['id'] ?>"><?= htmlspecialchars($account['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="detailOffice-<?= $detail['id'] ?>" class="form-label">営業所</label>
                                <select class="form-select" id="detailOffice-<?= $detail['id'] ?>" name="office_id" required>
                                    <option value="<?= htmlspecialchars($detail['office_id']) ?>" selected>
                                        <?= htmlspecialchars($detail['office_name'] ?? '未設定') ?>
                                    </option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?= $office['id'] ?>"><?= htmlspecialchars($office['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
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
                <form action="delete_detail.php" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">削除確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="deleteDetailId" value="">
                        本当に <span id="deleteDetailName" class="fw-bold"></span> を削除してもよろしいですか？
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">はい、削除します</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz"
        crossorigin="anonymous"></script>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            // 変数 table に代入する
            var table = $('#detailTable').DataTable({
                // 日本語化設定
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/ja.json"
                },
                // 表示件数の設定
                lengthMenu: [
                    [10, 25, 50, -1],
                    [10, 25, 50, "全件"]
                ],
                // 初期並び順
                order: [],
                stateSave: true,
            });
            // リセットボタンの動作
            $('#resetStateBtn').on('click', function() {
                // 1. 保存されたステート（並び順、検索条件、ページ位置など）を削除
                table.state.clear();

                // 2. 検索条件をクリア
                table.search('').columns().search('');

                // 3. 並び順を初期化（空配列＝PHPの出力順に戻す）
                table.order([]);

                // 4. ページネーションを1ページ目に戻す
                table.page(0);

                // 5. 画面を再描画して変更を反映
                table.draw();

                // リロードして完全に反映させる
                window.location.reload();
            });
        });

        // 削除モーダルへIDと名前を渡すスクリプト
        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function(event) {
                // モーダルを呼び出したボタンを取得
                const button = event.relatedTarget;
                // データ属性から値を取得
                const detailId = button.getAttribute('data-id');
                const detailName = button.getAttribute('data-name');

                // モーダル内の要素に値をセット
                const modalIdInput = deleteModal.querySelector('#deleteDetailId');
                const modalNameSpan = deleteModal.querySelector('#deleteDetailName');

                modalIdInput.value = detailId;
                modalNameSpan.textContent = detailName;
            });
        }
    </script>
</body>

</html>