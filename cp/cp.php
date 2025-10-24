<?php
require_once '../includes/database.php';
require_once '../includes/cp_ui_functions.php';

// DB接続
$dbh = getDb();

// 年度選択
$availableYears = getAvailableCpYears($dbh);

// 選択された年度・月（GET優先、なければ今年度＋4月）
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedMonth = (int)($_GET['month'] ?? 4);

// ステータス表示用の基準年度
$currentYear = $selectedYear;

// 各月のステータス
$cpStatusList = getCpStatusByYear($currentYear, $dbh);
$statusColors = ['fixed' => 'success', 'draft' => 'primary', 'none' => 'secondary'];

// 勘定科目と詳細
$stmt = $dbh->query("SELECT * FROM accounts ORDER BY id ASC");
$accounts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $accounts[$row['id']] = ['name' => $row['name'], 'details' => []];
}
$stmt = $dbh->query("SELECT d.*, a.id as account_id FROM details d JOIN accounts a ON d.account_id=a.id ORDER BY d.id ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $accounts[$row['account_id']]['details'][] = ['id' => $row['id'], 'name' => $row['name']];
}

// 営業所リスト
$stmt = $dbh->query("SELECT * FROM offices ORDER BY id ASC");
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedMonth = 4;
$selectedOffice = $offices[0]['id'] ?? 0;

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
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            概算
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../result/result_edit.php">概算実績編集</a></li>
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
        <?php if (isset($_GET['error'])): ?>
            <div id="errorAlert" class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_GET['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['success']) && $_GET['success'] === '1'):
            $sy = isset($_GET['year']) ? (int)$_GET['year'] : null;
            $sm = isset($_GET['month']) ? (int)$_GET['month'] : null;
        ?>
            <div id="successAlert" class="alert alert-success alert-dismissible fade show" role="alert">
                <?php if ($sy && $sm): ?>
                    <?= htmlspecialchars("{$sy}年度 {$sm}月 を登録しました。") ?>
                <?php else: ?>
                    登録が完了しました。
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>
        <form id="cpForm" action="process_cp.php" method="POST">
            <div class="row mb-3">
                <div class="col-md-2">
                    <h4>CP 計画入力</h4>
                </div>
                <div class="col-md-10">
                    <label>各月の状況：
                        <span class="text-secondary">未登録</span>,
                        <span class="text-primary">登録済</span>,
                        <span class="text-success">確定済</span>
                    </label><br>
                    <?php
                    $startMonth = 4;
                    for ($i = 0; $i < 12; $i++):
                        $month = ($startMonth + $i - 1) % 12 + 1;
                        $status = $cpStatusList[$month];
                        $colorClass = $statusColors[$status] ?? 'secondary';
                    ?>
                        <button type="button" class="btn btn-<?= $colorClass ?> btn-sm me-1 mb-1" disabled><?= $month ?>月</button>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="info-box p-3 border mb-3">
                <div class="row align-items-end mb-2">
                    <div class="col-md-2">
                        <label>年度</label>
                        <select id="yearSelect" name="year" class="form-select form-select-sm" onchange="onYearChange()">
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?= $year ?>" <?= $year == $currentYear ? 'selected' : '' ?>><?= $year ?>年度</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>月</label>
                        <select id="monthSelect" name="month" class="form-select form-select-sm">
                            <?php for ($i = 4; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == $selectedMonth ? 'selected' : '' ?>><?= $i ?>月</option>
                            <?php endfor; ?>
                            <?php for ($i = 1; $i <= 3; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == $selectedMonth ? 'selected' : '' ?>><?= $i ?>月</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>営業所</label>
                        <select id="officeSelect" class="form-select form-select-sm" onchange="onOfficeChange()">
                            <?php foreach ($offices as $office): ?>
                                <option value="<?= $office['id'] ?>" <?= $office['id'] == $selectedOffice ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($office['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <strong>総時間：</strong><span id="totalHours">0.00</span> 時間<br>
                        <strong>労務費：</strong>¥<span id="laborCost">0</span>
                    </div>
                    <div class="col-md-3">
                        <strong>経費合計：</strong>¥<span id="expenseTotal">0</span><br>
                        <strong>総合計：</strong>¥<span id="grandTotal">0</span>
                    </div>
                </div>

                <div class="row align-items-end mb-2">
                    <div class="col-md-2">
                        <label>賃率</label>
                        <input type="number" step="1" id="hourlyRate" name="hourly_rate" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['hourly_rate'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label>定時間</label>
                        <input type="number" step="0.01" class="form-control form-control-sm time-input" data-field="standard_hours">
                    </div>
                    <div class="col-md-2">
                        <label>残業時間</label>
                        <input type="number" step="0.01" class="form-control form-control-sm time-input" data-field="overtime_hours">
                    </div>
                    <div class="col-md-2">
                        <label>時間移動</label>
                        <input type="number" step="0.01" class="form-control form-control-sm time-input" data-field="transferred_hours">
                    </div>
                    <div class="col-md-4"></div>
                </div>

                <div class="row align-items-end mb-2">
                    <div class="col-md-2">
                        <label>正社員</label>
                        <input type="number" step="1" class="form-control form-control-sm time-input" data-field="fulltime_count">
                    </div>
                    <div class="col-md-2">
                        <label>契約社員</label>
                        <input type="number" step="1" class="form-control form-control-sm time-input" data-field="contract_count">
                    </div>
                    <div class="col-md-2">
                        <label>派遣社員</label>
                        <input type="number" step="1" class="form-control form-control-sm time-input" data-field="dispatch_count">
                    </div>
                    <div class="col-md-6"></div>
                </div>
                <input type="hidden" name="officeTimeData" id="officeTimeData">
                <button type="submit" class="btn btn-outline-danger btn-sm register-button">登録</button>
            </div>

            <!-- 勘定科目と詳細 -->
            <div class="table-container mb-3">
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
                                    <?= $account['total'] ?? 0 ?>
                                </td>
                            </tr>
                            <!-- 詳細（子） -->
                            <?php foreach ($account['details'] as $detail): ?>
                                <tr class="collapse" id="child-<?= $accountId ?>">
                                    <td class="ps-4"><?= htmlspecialchars($detail['name']) ?></td>
                                    <td class="details-cell">
                                        <input type="number" step="0.01"
                                            class="form-control form-control-sm text-end input-value detail-input"
                                            data-account-id="<?= $accountId ?>"
                                            name="accounts[<?= $detail['id'] ?>]"
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
        // 営業所毎の時間管理データを保持
        const officeTimeData = {};
        const officeSelect = document.getElementById('officeSelect');
        const hourlyRateInput = document.getElementById('hourlyRate');
        const timeInputs = document.querySelectorAll('.time-input');

        function calculate() {
            let totalStandard = 0,
                totalOvertime = 0,
                totalTransferred = 0;
            for (const officeId in officeTimeData) {
                const data = officeTimeData[officeId];
                totalStandard += parseFloat(data.standard_hours || 0);
                totalOvertime += parseFloat(data.overtime_hours || 0);
                totalTransferred += parseFloat(data.transferred_hours || 0);
            }
            const totalHours = totalStandard + totalOvertime + totalTransferred;
            const laborCost = totalHours * (parseFloat(hourlyRateInput.value) || 0);
            document.getElementById('totalHours').innerText = totalHours.toFixed(2);
            document.getElementById('laborCost').innerText = Math.round(laborCost).toLocaleString();

            // 経費計算
            let expenseTotal = 0;
            document.querySelectorAll('.input-value').forEach(input => {
                expenseTotal += parseFloat(input.value) || 0;
            });
            document.getElementById('expenseTotal').innerText = Math.round(expenseTotal).toLocaleString();

            const grandTotal = laborCost + expenseTotal;
            document.getElementById('grandTotal').innerText = Math.round(grandTotal).toLocaleString();

            // 勘定科目ごとの合計
            const accountTotals = {};
            document.querySelectorAll('.input-value').forEach(input => {
                const accountId = input.getAttribute('data-account-id');
                accountTotals[accountId] = (accountTotals[accountId] || 0) + (parseFloat(input.value) || 0);
            });
            Object.keys(accountTotals).forEach(accountId => {
                const elem = document.getElementById(`total-account-${accountId}`);
                if (elem) elem.textContent = Math.round(accountTotals[accountId]);
            });
        }

        // 入力値変更時
        timeInputs.forEach(input => {
            input.addEventListener('input', () => {
                const officeId = officeSelect.value;
                const field = input.dataset.field;
                if (!officeTimeData[officeId]) officeTimeData[officeId] = {};
                officeTimeData[officeId][field] = input.value;
                calculate();
            });
        });
        hourlyRateInput.addEventListener('input', calculate);
        document.querySelectorAll('.input-value').forEach(input => input.addEventListener('input', calculate));

        // 営業所切替時
        function onOfficeChange() {
            const officeId = officeSelect.value;
            const data = officeTimeData[officeId] || {};
            timeInputs.forEach(input => {
                const field = input.dataset.field;
                input.value = data[field] || '';
            });
            calculate();
        }

        // 年度変更時
        function onYearChange() {
            const year = document.getElementById('yearSelect').value;
            const url = new URL(window.location.href);
            url.searchParams.set('year', year);
            window.location.href = url.toString();
        }

        // フォーム送信時に officeTimeData を hidden input にセット
        document.getElementById("cpForm").addEventListener("submit", function() {
            document.getElementById("officeTimeData").value = JSON.stringify(officeTimeData);
        });

        // アイコンの切替
        document.querySelectorAll('.toggle-icon').forEach(icon => {
            icon.addEventListener('click', function() {
                const iElem = this.querySelector('i');
                iElem.classList.toggle('bi-plus');
                iElem.classList.toggle('bi-dash');
            });
        });

        // 初期化処理
        onOfficeChange(); // 初期営業所の値を復元
        calculate(); // 初期計算
    </script>
    <script>
        // エラーアラート処理
        const errorAlertElem = document.getElementById('errorAlert');
        if (errorAlertElem) {
            errorAlertElem.addEventListener('close.bs.alert', function() {
                const url = new URL(window.location.href);
                url.searchParams.delete('error');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            });
        }

        // 成功アラート処理
        const successAlertElem = document.getElementById('successAlert');
        if (successAlertElem) {
            successAlertElem.addEventListener('close.bs.alert', function() {
                const url = new URL(window.location.href);
                url.searchParams.delete('success');
                url.searchParams.delete('month');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            });
        }
    </script>

</body>

</html>