<?php
require_once '../includes/database.php';

// データベース接続
$dbh = getDb();

try {
    // 月次 CP 登録済みの年と月を取得
    $query = "SELECT DISTINCT year, month FROM monthly_cp ORDER BY year ASC, month ASC";
    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $registeredDates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // データを年ごとにグループ化
    $years = [];
    foreach ($registeredDates as $date) {
        $years[$date['year']][] = $date['month'];
    }

    // 勘定科目、詳細、金額、時間管理内容をマージ
    $accountsQuery =
        "SELECT 
        a.id AS account_id,
        a.name AS account_name,
        d.id AS detail_id,
        d.name AS detail_name
        FROM accounts a
        LEFT JOIN details d ON a.id = d.account_id
        ORDER BY a.id ASC, d.id ASC";
    $accountsStmt = $dbh->prepare($accountsQuery);
    $accountsStmt->execute();
    $accountsDetails = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($accountsDetails)) {
        echo "データが見つかりません。";
    }

    // データを構造化
    $accounts = [];
    foreach ($accountsDetails as $row) {
        $accountId = $row['account_id'];
        if (!isset($accounts[$accountId])) {
            $accounts[$accountId] = [
                'name' => $row['account_name'],
                'details' => []
            ];
        }
        if (!is_null($row['detail_id'])) {
            $accounts[$accountId]['details'][] = [
                'id' => $row['detail_id'],
                'name' => $row['detail_name'],
            ];
        }
    }
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
    $accounts = [];
}
?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CP 計画入力</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/cp_edit.css">
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
                            <li><a class="dropdown-item" href="#">Action</a></li>
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
                            <li><a class="dropdown-item" href="#">Action</a></li>
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
                            月末見込み
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#">Action</a></li>
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
                            概算
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#">Action</a></li>
                            <li><a class="dropdown-item" href="#">Another action</a></li>
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
        <form action="./cp_edit_load.php" method="POST">
            <h3 class="mb-4">CP 編集</h3>

            <!-- 上部の入力フォーム -->
            <div class="info-box">
                <div class="row">
                    <!-- 年度と月の選択 -->
                    <div class="col-md-2">
                        <label>年度</label>
                        <select id="yearSelect" name="year" class="form-select form-select-sm" onchange="updateMonths()">
                            <option value="" disabled selected>年度を選択</option>
                            <?php foreach (array_keys($years) as $year): ?>
                                <option value="<?= $year ?>"><?= $year ?>年度</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>月</label>
                        <select id="monthSelect" name="month" class="form-select form-select-sm" disabled>
                            <option value="" disabled selected>月を選択</option>
                        </select>
                    </div>
                    <!-- 時間管理 -->
                    <div class="col-md-2">
                        <label>定時間 (時間)</label>
                        <input type="number" step="0.01" id="standardHours" name="standard_hours" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>残業時間 (時間)</label>
                        <input type="number" step="0.01" id="overtimeHours" name="overtime_hours" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>時間移動 (時間)</label>
                        <input type="number" step="0.01" id="transferredHours" name="transferred_hours" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>賃率 (¥)</label>
                        <input type="number" step="1" id="hourlyRate" name="hourly_rate" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <strong>総時間：</strong> <span id="totalHours">0.00 時間</span><br>
                            <strong>労務費：</strong> ¥<span id="laborCost">0</span>
                        </div>
                        <div class="col-md-4">
                            <strong>経費合計：</strong> ¥<span id="expenseTotal">0</span><br>
                            <strong>　総合計：</strong> ¥<span id="grandTotal">0</span>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-danger btn-sm register-button2" name="action" value="update">修正</button>
            </div>

            <!-- 勘定科目と詳細の入力フォーム -->
            <div class="table-container">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>勘定科目/詳細</th>
                            <th style="width: 150px;">CP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 勘定科目（親） -->
                        <?php foreach ($accounts as $accountId => $account): ?>
                            <tr>
                                <td>
                                    <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon" data-bs-toggle="collapse"
                                        data-bs-target="#child-<?= $accountId ?>" aria-expanded="true">
                                        <i class="bi bi-plus icon-small"></i>
                                    </button>
                                    <?= htmlspecialchars($account['name']) ?>
                                </td>
                                <td class="text-end fw-bold" id="total-account-<?= $accountId ?>">0
                                    <input type="hidden" name="total_account[<?= $accountId ?>]" value="0">
                                </td>
                            </tr>
                            <!-- 詳細（子） -->
                            <?php foreach ($account['details'] as $detail): ?>
                                <tr class="collapse" id="child-<?= $accountId ?>">
                                    <td class="ps-4"><?= htmlspecialchars($detail['name']) ?></td>
                                    <td class="details-cell">
                                        <input type="hidden" name="detail_ids[]" value="<?= $detail['id'] ?>">
                                        <input type="number" step="1"
                                            class="form-control form-control-sm text-end input-value detail-input"
                                            data-parent="account-<?= $accountId ?>"
                                            data-account-id="<?= $accountId ?>"
                                            data-detail-id="<?= $detail['id'] ?>"
                                            name="amounts[<?= $detail['id'] ?>]"
                                            placeholder="0">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // アイコンの切り替え
            document.querySelectorAll('.toggle-icon').forEach(function(icon) {
                icon.addEventListener('click', function() {
                    const iconElement = icon.querySelector('i');
                    if (iconElement.classList.contains('bi-dash-lg')) {
                        iconElement.classList.remove('bi-dash-lg');
                        iconElement.classList.add('bi-plus-lg');
                    } else {
                        iconElement.classList.remove('bi-plus-lg');
                        iconElement.classList.add('bi-dash-lg');
                    }
                });
            });
        });
    </script>
    <script>
        // 年ごとに対応する月データを保持
        const yearMonthData = <?= json_encode($years) ?>;
    </script>
    <script src="../js/cp_edit.js"></script>
</body>

</html>