<?php
require_once '../includes/database.php';
require_once '../includes/cp_ui_functions.php';
$dbh = getDb();

// データ取得
$query = "SELECT a.id AS account_id, a.name AS account_name, 
                 d.id AS detail_id, d.name AS detail_name, 
                 IFNULL(SUM(m.amount), 0) AS total_amount
          FROM accounts a
          LEFT JOIN details d ON a.id = d.account_id
          LEFT JOIN monthly_cp_details m ON d.id = m.detail_id
          GROUP BY a.id, d.id, a.name, d.name
          ORDER BY a.id, d.id";
$stmt = $dbh->prepare($query);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// データを構造化
$accounts = [];
foreach ($rows as $row) {
    $accountId = $row['account_id'];
    if (!isset($accounts[$accountId])) {
        $accounts[$accountId] = [
            'name' => $row['account_name'],
            'total' => 0,
            'details' => []
        ];
    }
    if (!is_null($row['detail_id'])) {
        $accounts[$accountId]['details'][] = [
            'id' => $row['detail_id'],
            'name' => $row['detail_name'],
            'amount' => floor($row['total_amount']) // 小数点以下は不要
        ];
        $accounts[$accountId]['total'] += floor($row['total_amount']);
    }
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
    <link rel="stylesheet" href="../css/cp.css">
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
                            <li><a class="dropdown-item" href="./cp_edit.php">CP編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            見通し
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../forecast/forecast_edit.php">見通し編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            予定
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../plan/plan_edit.php">予定編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            月末見込み
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../outlook/outlook_edit.php">月末見込み編集</a></li>
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
                            <li><a class="dropdown-item" href="../result/result_edit.php">概算実績編集</a></li>
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
                            勘定科目設定
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../account/account.php">勘定科目登録</a></li>
                            <li><a class="dropdown-item" href="../account/account_list.php">勘定科目リスト</a></li>
                            <li><a class="dropdown-item" href="../details/detail.php">詳細登録</a></li>
                            <li><a class="dropdown-item" href="../details/detail_list.php">詳細リスト</a></li>
                            <li><a class="dropdown-item" href="../offices/office.php">係登録</a></li>
                            <li><a class="dropdown-item" href="../offices/office_list.php">係リスト</a></li>
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
    <div class="container mt-2">
        <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                登録が完了しました。
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_GET['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>
        <form action="process_cp.php" method="POST">
            <h4 class="mb-2">CP 計画入力</h4>
            <?php
            $availableYears = getAvailableCpYears($dbh);
            $currentYear = (int)($_GET['year'] ?? date('Y'));
            $cpStatusList = getCpStatusByYear($currentYear, $dbh);
            $statusColors = [
                'fixed' => 'success',  // 緑
                'draft' => 'primary',  // 青
                'none' => 'secondary', // 灰色
            ];
            ?>

            <div class="mb-3">
                <label class="form-label mb-1">各月の状況：<span class="text-secondary">未登録</span>、<span class="text-primary">登録済</span>、<span class="text-success">確定済</span></label><br>
                <?php
                $startMonth = 4; // 会計年度開始月
                for ($i = 0; $i < 12; $i++):
                    $month = ($startMonth + $i - 1) % 12 + 1;
                    $status = $cpStatusList[$month];
                    $colorClass = $statusColors[$status] ?? 'secondary';
                ?>
                    <button type="button" class="btn btn-<?= $colorClass ?> btn-sm me-1 mb-1" disabled>
                        <?= $month ?>月
                    </button>
                <?php endfor; ?>
            </div>
            <!-- 上部の入力フォーム -->
            <div class="info-box">
                <div class="row">
                    <!-- 年度の選択 -->
                    <div class="col-md-2">
                        <label>年度</label>
                        <select id="yearSelect" name="year" class="form-select form-select-sm" onchange="onYearChange()">
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?= $year ?>" <?= $year == $currentYear ? 'selected' : '' ?>>
                                    <?= $year ?>年度
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- 月の選択 -->
                    <?php
                    $selectedMonth = 4;
                    ?>

                    <div class="col-md-2">
                        <label>月</label>
                        <select id="monthSelect" name="month" class="form-select form-select-sm">
                            <?php
                            // 4月から12月を先に出力
                            for ($i = 4; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == $selectedMonth ? 'selected' : '' ?>>
                                    <?= $i ?>月
                                </option>
                            <?php endfor; ?>
                            <?php
                            // 1月から3月を後に出力
                            for ($i = 1; $i <= 3; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == $selectedMonth ? 'selected' : '' ?>>
                                    <?= $i ?>月
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>定時間</label>
                        <input type="number" step="0.01" id="standardHours" name="standard_hours" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>残業時間</label>
                        <input type="number" step="0.01" id="overtimeHours" name="overtime_hours" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>時間移動</label>
                        <input type="number" step="0.01" id="transferredHours" name="transferred_hours" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>賃率</label>
                        <input type="number" step="1" id="hourlyRate" name="hourly_rate" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="row mt-2 mb-5">
                        <div class="col-md-2">
                            <label>正社員</label>
                            <input type="number" id="fulltimeCount" name="fulltime_count" class="form-control form-control-sm" min="0" value="<?= htmlspecialchars($_POST['fulltime_count'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label>契約社員</label>
                            <input type="number" id="contractCount" name="contract_count" class="form-control form-control-sm" min="0" value="<?= htmlspecialchars($_POST['contract_count'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label>派遣社員</label>
                            <input type="number" id="dispatchCount" name="dispatch_count" class="form-control form-control-sm" min="0" value="<?= htmlspecialchars($_POST['dispatch_count'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <strong>総時間：</strong> <span id="totalHours">0.00 時間</span><br>
                            <strong>労務費：</strong> ¥<span id="laborCost">0</span>
                        </div>
                        <div class="col-md-3">
                            <strong>経費合計：</strong> ¥<span id="expenseTotal">0</span><br>
                            <strong>　総合計：</strong> ¥<span id="grandTotal">0</span>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-danger btn-sm register-button">登録</button>
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
                                <td class="text-end fw-bold" id="total-account-<?= $accountId ?>">
                                    <?= $account['total'] ?>
                                    <input type="hidden" name="total_account[<?= $accountId ?>]" value="<?= htmlspecialchars($account['total']) ?>">
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
                                            name="amounts[]"
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
        function calculate() {
            // 時間管理計算
            const standardHours = parseFloat(document.getElementById('standardHours').value) || 0;
            const overtimeHours = parseFloat(document.getElementById('overtimeHours').value) || 0;
            const transferredHours = parseFloat(document.getElementById('transferredHours').value) || 0;
            const hourlyRate = parseFloat(document.getElementById('hourlyRate').value) || 0;

            const totalHours = standardHours + overtimeHours + transferredHours;
            const laborCost = totalHours * hourlyRate;

            document.getElementById('totalHours').innerText = totalHours.toFixed(2);
            document.getElementById('laborCost').innerText = new Intl.NumberFormat().format(Math.round(laborCost));

            // 経費合計計算
            let expenseTotal = 0;
            document.querySelectorAll('.input-value').forEach(input => {
                expenseTotal += parseFloat(input.value) || 0;
            });
            document.getElementById('expenseTotal').innerText = new Intl.NumberFormat().format(Math.round(expenseTotal));

            // 総合計計算
            const grandTotal = laborCost + expenseTotal;
            document.getElementById('grandTotal').innerText = new Intl.NumberFormat().format(Math.round(grandTotal));

            // 勘定科目ごとの合計計算
            const accountTotals = {};
            document.querySelectorAll('.input-value').forEach(input => {
                const accountId = input.getAttribute('data-account-id');
                accountTotals[accountId] = (accountTotals[accountId] || 0) + (parseFloat(input.value) || 0);
            });

            Object.keys(accountTotals).forEach(accountId => {
                const accountTotalElement = document.getElementById(`total-account-${accountId}`);
                if (accountTotalElement) {
                    accountTotalElement.textContent = Math.round(accountTotals[accountId]);
                }
            });
        }

        // 入力値変更時に計算
        document.querySelectorAll('.info-box input, .input-value').forEach(input => {
            input.addEventListener('input', calculate);
        });

        // 初期計算
        calculate();
    </script>
    <script>
        // 詳細の入力値が変更されたら合計を計算し親（勘定科目）に反映する
        document.querySelectorAll('.input-value').forEach(input => {
            input.addEventListener('input', () => {
                const parentId = input.getAttribute('data-parent');
                let total = 0;

                // 同じ親の子要素を合計
                document.querySelectorAll(`.input-value[data-parent='${parentId}']`).forEach(item => {
                    const value = parseFloat(item.value) || 0;
                    total += value;
                });

                // 親の合計値を更新
                document.getElementById(`total-${parentId}`).textContent = total;
            });
        });
    </script>
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
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            if (url.searchParams.has('success')) {
                // クエリパラメータを削除して履歴を書き換え
                url.searchParams.delete('success');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            }
        }
    </script>
    <script>
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            if (url.searchParams.has('error')) {
                // クエリパラメータを削除して履歴を書き換え
                url.searchParams.delete('error');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            }
        }
    </script>
    <script>
        function onYearChange() {
            const year = document.getElementById('yearSelect').value;
            const url = new URL(window.location.href);
            url.searchParams.set('year', year);
            window.location.href = url.toString(); // リロードして反映
        }
    </script>

</body>

</html>