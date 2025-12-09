<html lang="ja">

<?php
session_start();
require_once '../includes/database.php';
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>係登録</title>

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
                            <li><a class="dropdown-item" href="../details/detail_list.php">詳細</a></li>
                            <li><a class="dropdown-item" href="./office_list.php">係一覧</a></li>
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
        <h4>係の新規登録</h4>

        <!-- エラーメッセージ表示 -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- 成功メッセージ表示 -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <form action="add_office.php" method="POST">
            <div class="mb-3">
                <label for="officeIdentifier" class="form-label">営業所コード</label>
                <input type="text" class="form-control" id="officeIdentifier" name="office_identifier"
                    pattern="^[a-zA-Z0-9_-]+$" placeholder="ex: 13E14210" required>
                <div class="form-text">半角英数字で入力してください。</div>
            </div>

            <div class="mb-3">
                <label for="officeName" class="form-label">営業所名（係名）</label>
                <input type="text" class="form-control" id="officeName" name="office_name" placeholder="ex: 安全品質センター" required>
            </div>

            <div class="mb-3">
                <label for="officeNote" class="form-label">説明 (任意)</label>
                <textarea class="form-control" id="officeNote" name="note" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">登録</button>
        </form>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz"
        crossorigin="anonymous"></script>

</body>

</html>