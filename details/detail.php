<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

try {
    // データベースから勘定科目リストを取得
    $dbh = getDb();
    $stmt = $dbh->query('SELECT id, name FROM accounts');
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 営業所情報を取得
    $stmt = $dbh->query('SELECT id, name FROM offices');
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = 'データの取得に失敗しました: ' . $e->getMessage();
    $accounts = [];
    $offices = [];
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>詳細登録 - Profit index</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-primary p-0" data-bs-theme="dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">採算表</a>
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
                            <li><a class="dropdown-item" href="../account/account_list.php">勘定科目</a></li>
                            <li><a class="dropdown-item" href="./detail_list.php">詳細</a></li>
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
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
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
    <div class="container mt-4">

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">詳細登録</h4>
            <a href="detail_list.php" class="btn btn-outline-secondary btn-sm">一覧に戻る</a>
        </div>

        <form action="add_detail.php" method="POST">
            <div class="mb-3">
                <label for="detailName" class="form-label">詳細名</label>
                <input type="text" class="form-control" id="detailName" name="name" required>
            </div>
            <div class="mb-3">
                <label for="detailIdentifier" class="form-label">一意識別子</label>
                <input type="text" class="form-control" id="detailIdentifier" name="identifier" required>
            </div>
            <div class="mb-3">
                <label for="sortOrder" class="form-label">表示順</label>
                <input type="number" class="form-control" id="sortOrder" name="sort_order" value="100" placeholder="例: 10">
                <div class="form-text">小さい数字ほど画面で優先的に表示されます（デフォルト: 100）</div>
            </div>
            <div class="mb-3">
                <label for="detailNote" class="form-label">説明</label>
                <textarea class="form-control" id="detailNote" name="note" rows="3"></textarea>
            </div>
            <div class="mb-3">
                <label for="account" class="form-label">勘定科目</label>
                <select class="form-select" id="account" name="account_id" required>
                    <option value="">-- 選択してください --</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= htmlspecialchars($account['id']) ?>">
                            <?= htmlspecialchars($account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="office" class="form-label">営業所</label>
                <select class="form-select" id="office" name="office_id" required>
                    <option value="">-- 選択してください --</option>
                    <?php foreach ($offices as $office): ?>
                        <option value="<?= htmlspecialchars($office['id']) ?>">
                            <?= htmlspecialchars($office['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">登録</button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>