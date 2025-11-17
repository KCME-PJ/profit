<?php
require_once '../includes/database.php';
require_once '../includes/cp_ui_functions.php';

// DB接続
$dbh = getDb();

try {
    // 年度選択
    $availableYears = getAvailableCpYears($dbh);
    $selectedYear = (int)($_GET['year'] ?? date('Y'));
    $selectedMonth = (int)($_GET['month'] ?? 4);
    $currentYear = $selectedYear;

    // 各月のステータス
    $cpStatusList = getCpStatusByYear($currentYear, $dbh);
    $statusColors = ['fixed' => 'success', 'draft' => 'primary', 'none' => 'secondary'];

    // --------------------------------------------------------
    // 1. 収入カテゴリと収入項目を取得
    // --------------------------------------------------------
    $revenueQuery = "SELECT c.id AS category_id, c.name AS category_name, i.id AS item_id, i.name AS item_name, i.note AS item_note
                     FROM revenue_categories c
                     LEFT JOIN revenue_items i ON c.id = i.revenue_category_id
                     ORDER BY c.sort_order ASC, c.id ASC, i.id ASC";
    $revenueStmt = $dbh->prepare($revenueQuery);
    $revenueStmt->execute();
    $revenueDetails = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);

    $revenues = [];
    foreach ($revenueDetails as $row) {
        $categoryId = $row['category_id'];
        if (!isset($revenues[$categoryId])) {
            $revenues[$categoryId] = [
                'name' => $row['category_name'],
                'items' => []
            ];
        }
        if (!is_null($row['item_id'])) {
            $revenues[$categoryId]['items'][] = [
                'id' => $row['item_id'],
                'name' => $row['item_name'],
                'note' => $row['item_note']
            ];
        }
    }

    // --------------------------------------------------------
    // 2. 勘定科目と詳細 (noteも取得)
    // --------------------------------------------------------
    $accountsQuery =
        "SELECT 
        a.id AS account_id,
        a.name AS account_name,
        d.id AS detail_id,
        d.name AS detail_name,
        d.note AS detail_note
        FROM accounts a
        LEFT JOIN details d ON a.id = d.account_id
        ORDER BY a.id ASC, d.id ASC";
    $accountsStmt = $dbh->prepare($accountsQuery);
    $accountsStmt->execute();
    $accountsDetails = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

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
                'note' => $row['detail_note'] // noteも取得
            ];
        }
    }

    // 営業所リスト
    $stmt = $dbh->query("SELECT * FROM offices ORDER BY id ASC");
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $selectedOffice = $offices[0]['id'] ?? 0;
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
    $accounts = [];
    $revenues = [];
    $offices = [];
    $availableYears = [date('Y')];
    $cpStatusList = [];
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
                        $status = $cpStatusList[$month] ?? 'none';
                        $colorClass = $statusColors[$status] ?? 'secondary';
                    ?>
                        <button type="button" class="btn btn-<?= $colorClass ?> btn-sm me-1 mb-1" disabled><?= $month ?>月</button>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- info-box -->
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
                        <strong>収入合計：</strong>¥<span id="info-revenue-total">0</span><br>
                        <strong>労務費：</strong>¥<span id="info-labor-cost">0</span>
                    </div>
                    <div class="col-md-3">
                        <strong>経費合計：</strong>¥<span id="info-expense-total">0</span><br>
                        <strong>差引収益：</strong>¥<span id="info-gross-profit">0</span>
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

            <!-- 2段階アコーディオンテーブル (ID/Target/ParentをBootstrapの仕様に準拠) -->
            <div class="table-container mb-3" id="accordionRoot">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>項目</th>
                            <th style="width: 30%;">詳細</th>
                            <th style="width: 30%;">備考</th>
                            <th style="width: 150px;">CP</th>
                        </tr>
                    </thead>
                    <tbody>

                        <!-- L1: 収入の部 -->
                        <tr class="table-light">
                            <td>
                                <!-- L1 トグルボタン (Targetを .l1-revenue-group に) -->
                                <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon-l1" data-bs-toggle="collapse" data-bs-target=".l1-revenue-group" aria-expanded="false">
                                    <i class="bi bi-plus-lg icon-small"></i>
                                </button>
                                <strong class="ms-2">収入の部</strong>
                            </td>
                            <td></td>
                            <td></td>
                            <td class="text-end fw-bold" id="total-revenue">0</td>
                        </tr>

                        <!-- L2: 収入カテゴリ -->
                        <?php foreach ($revenues as $categoryId => $category): ?>
                            <!-- L2の行 (L1によって制御) -->
                            <!-- L1のクラスを data-bs-parent で指定 -->
                            <tr class="collapse l1-revenue-group" data-bs-parent="#accordionRoot" id="l2-rev-row-<?= $categoryId ?>">
                                <td class="ps-4">
                                    <!-- L2 トグルボタン (Targetを .l3-rev-items-CATEGORYID に) -->
                                    <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon-l2" data-bs-toggle="collapse" data-bs-target=".l3-rev-items-<?= $categoryId ?>" aria-expanded="false">
                                        <i class="bi bi-plus icon-small"></i>
                                    </button>
                                    <?= htmlspecialchars($category['name']) ?>
                                </td>
                                <td></td>
                                <td></td>
                                <td class="text-end fw-bold" id="total-revenue-category-<?= $categoryId ?>">0</td>
                            </tr>

                            <!-- L3: 収入項目 -->
                            <?php foreach ($category['items'] as $item): ?>
                                <!-- L3の行 (L2によって制御) -->
                                <!-- L2のIDを data-bs-parent に設定 -->
                                <tr class="collapse l3-rev-items-<?= $categoryId ?>" data-bs-parent="#l2-rev-row-<?= $categoryId ?>" id="l3-rev-item-<?= $item['id'] ?>">
                                    <td></td>
                                    <td class="ps-5">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($item['note']) ?></small>
                                    </td>
                                    <td class="details-cell">
                                        <input type="number" step="1"
                                            class="form-control form-control-sm text-end input-value revenue-input"
                                            data-category-id="<?= $categoryId ?>"
                                            name="revenues[<?= $item['id'] ?>]"
                                            placeholder="0">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>


                        <!-- L1: 経費の部 -->
                        <tr class="table-light">
                            <td>
                                <!-- L1 トグルボタン (Targetを .l1-expense-group に) -->
                                <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon-l1" data-bs-toggle="collapse" data-bs-target=".l1-expense-group" aria-expanded="false">
                                    <i class="bi bi-plus-lg icon-small"></i>
                                </button>
                                <strong class="ms-2">経費の部</strong>
                            </td>
                            <td></td>
                            <td></td>
                            <td class="text-end fw-bold" id="total-expense">0</td>
                        </tr>

                        <!-- L2: 勘定科目（親） -->
                        <?php foreach ($accounts as $accountId => $account): ?>
                            <!-- L2の行 (L1によって制御) -->
                            <!-- data-bs-parent="#accordionRoot" を L2 に追加 -->
                            <tr class="collapse l1-expense-group" data-bs-parent="#accordionRoot" id="l2-acc-row-<?= $accountId ?>">
                                <td class="ps-4">
                                    <!-- L2 トグルボタン (Targetを .l3-acc-items-ACCOUNTID に) -->
                                    <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon-l2" data-bs-toggle="collapse" data-bs-target=".l3-acc-items-<?= $accountId ?>" aria-expanded="false">
                                        <i class="bi bi-plus icon-small"></i>
                                    </button>
                                    <?= htmlspecialchars($account['name']) ?>
                                </td>
                                <td></td>
                                <td></td>
                                <td class="text-end fw-bold" id="total-account-<?= $accountId ?>">0</td>
                            </tr>

                            <!-- L3: 詳細（子） -->
                            <?php foreach ($account['details'] as $detail): ?>
                                <!-- L3の行 (L2によって制御) -->
                                <!-- L2のIDを data-bs-parent に設定 -->
                                <tr class="collapse l3-acc-items-<?= $accountId ?>" data-bs-parent="#l2-acc-row-<?= $accountId ?>" id="l3-acc-item-<?= $detail['id'] ?>">
                                    <td></td>
                                    <td class="ps-5">
                                        <?= htmlspecialchars($detail['name']) ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($detail['note']) ?></small>
                                    </td>
                                    <td class="details-cell">
                                        <input type="number" step="1"
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

    <!-- (Bootstrap JSの読み込み) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- グローバル変数 ---
            const form = document.getElementById('cpForm');
            // 営業所データは時間と人数のみ保持（賃率は別で管理）
            const officeTimeData = {};
            const officeSelect = document.getElementById('officeSelect');
            const hourlyRateInput = document.getElementById('hourlyRate');
            const timeInputs = document.querySelectorAll('.time-input'); // 時間・人数
            const revenueInputs = document.querySelectorAll('.revenue-input'); // 収入
            const expenseInputs = document.querySelectorAll('.detail-input'); // 経費

            // --- メイン計算関数 ---
            function calculate() {
                let totalStandard = 0,
                    totalOvertime = 0,
                    totalTransferred = 0;

                // 1. 労務費の計算
                const currentOfficeId = officeSelect.value;
                if (!officeTimeData[currentOfficeId]) officeTimeData[currentOfficeId] = {};

                // 時間・人数の入力値のみをローカル変数に保存
                timeInputs.forEach(input => {
                    const field = input.dataset.field;
                    officeTimeData[currentOfficeId][field] = input.value;
                });

                // 賃率はDOMの共通入力欄からのみ取得
                const hourlyRate = parseFloat(hourlyRateInput.value) || 0;

                // 全営業所の総時間を計算
                for (const officeId in officeTimeData) {
                    const data = officeTimeData[officeId];
                    totalStandard += parseFloat(data.standard_hours || 0);
                    totalOvertime += parseFloat(data.overtime_hours || 0);
                    totalTransferred += parseFloat(data.transferred_hours || 0);
                }

                const totalHours = totalStandard + totalOvertime + totalTransferred;
                // 労務費 (全営業所の総時間 * 共通賃率)
                const laborCost = Math.round(totalHours * hourlyRate);
                document.getElementById('info-labor-cost').textContent = laborCost.toLocaleString();

                // 2. 収入合計の計算 (L1, L2)
                let revenueTotal = 0;
                const revenueCategoryTotals = {};
                revenueInputs.forEach(input => {
                    const val = parseFloat(input.value) || 0;
                    const categoryId = input.getAttribute('data-category-id');
                    revenueTotal += val;
                    if (!revenueCategoryTotals[categoryId]) revenueCategoryTotals[categoryId] = 0;
                    revenueCategoryTotals[categoryId] += val;
                });

                document.getElementById('total-revenue').textContent = Math.round(revenueTotal).toLocaleString();
                document.getElementById('info-revenue-total').textContent = Math.round(revenueTotal).toLocaleString(); // info-box

                Object.keys(revenueCategoryTotals).forEach(categoryId => {
                    const elem = document.getElementById(`total-revenue-category-${categoryId}`);
                    if (elem) elem.textContent = Math.round(revenueCategoryTotals[categoryId]).toLocaleString();
                });

                // 3. 経費合計の計算 (L1, L2)
                let expenseTotal = 0;
                const accountTotals = {};

                expenseInputs.forEach(input => {
                    const val = parseFloat(input.value) || 0;
                    const accountId = input.getAttribute('data-account-id');
                    expenseTotal += val;
                    if (!accountTotals[accountId]) accountTotals[accountId] = 0;
                    accountTotals[accountId] += val;
                });

                document.getElementById('total-expense').textContent = Math.round(expenseTotal).toLocaleString();
                document.getElementById('info-expense-total').textContent = Math.round(expenseTotal).toLocaleString(); // info-box

                Object.keys(accountTotals).forEach(accountId => {
                    const elem = document.getElementById(`total-account-${accountId}`);
                    if (elem) elem.textContent = Math.round(accountTotals[accountId]).toLocaleString();
                });

                // 4. 差引収益の計算 (収入 - 経費)
                const grossProfit = revenueTotal - expenseTotal;
                document.getElementById('info-gross-profit').textContent = Math.round(grossProfit).toLocaleString();
            }

            // --- イベントリスナー (入力) ---
            // フォーム全体の 'input' イベントを監視 (イベントデリゲーション)
            form.addEventListener('input', function(event) {
                // イベントが発生した要素（input）が計算対象かチェック
                if (event.target.classList.contains('time-input') ||
                    event.target.id === 'hourlyRate' ||
                    event.target.classList.contains('revenue-input') ||
                    event.target.classList.contains('detail-input')) {
                    calculate();
                }
            });

            // --- イベントリスナー (UI) ---

            // 営業所切替 (賃率を変更しない)
            window.onOfficeChange = function() {
                const officeId = officeSelect.value;
                const data = officeTimeData[officeId] || {};

                // 時間・人数のみを切り替え
                timeInputs.forEach(input => {
                    const field = input.dataset.field;
                    input.value = data[field] || '';
                });

                // 合計を再計算
                calculate();
            }

            // 年度変更時 (ページリロード)
            window.onYearChange = function() {
                const year = document.getElementById('yearSelect').value;
                const url = new URL(window.location.href);
                url.searchParams.set('year', year);
                window.location.href = url.toString();
            };

            // フォーム送信時
            form.addEventListener("submit", function() {
                // 賃率はDOMから取得 (共通値)
                const rate = hourlyRateInput.value;
                const currentOfficeId = officeSelect.value;

                // 現在のデータを保存 (送信直前)
                if (!officeTimeData[currentOfficeId]) officeTimeData[currentOfficeId] = {};
                timeInputs.forEach(input => {
                    const field = input.dataset.field;
                    officeTimeData[currentOfficeId][field] = input.value;
                });

                // 全営業所のデータに（共通の）賃率を反映
                // まず、現在選択中の営業所データがなければ初期化
                if (!officeTimeData[currentOfficeId]) {
                    officeTimeData[currentOfficeId] = {};
                }
                // 現在の営業所に賃率を設定
                officeTimeData[currentOfficeId]['hourly_rate'] = rate;

                // 他の営業所データにも同じ賃率を設定
                for (const officeId in officeTimeData) {
                    if (!officeTimeData[officeId]) officeTimeData[officeId] = {};
                    officeTimeData[officeId]['hourly_rate'] = rate;
                }

                document.getElementById("officeTimeData").value = JSON.stringify(officeTimeData);
            });

            // L1 (収入の部 / 経費の部) のボタン
            document.querySelectorAll('.toggle-icon-l1').forEach(function(l1Button) {
                const iconElementL1 = l1Button.querySelector('i');
                const l2TargetSelector = l1Button.getAttribute('data-bs-target');
                const l2Rows = document.querySelectorAll(l2TargetSelector);

                l2Rows.forEach(l2Row => {
                    const l2CollapseInstance = bootstrap.Collapse.getOrCreateInstance(l2Row, {
                        toggle: false
                    });

                    l2Row.addEventListener('show.bs.collapse', () => {
                        iconElementL1.classList.remove('bi-plus-lg');
                        iconElementL1.classList.add('bi-dash-lg');
                    });

                    l2Row.addEventListener('hide.bs.collapse', () => {
                        const otherL2s = Array.from(l2Rows).filter(el => el !== l2Row && el.classList.contains('show'));
                        if (otherL2s.length === 0) {
                            iconElementL1.classList.remove('bi-dash-lg');
                            iconElementL1.classList.add('bi-plus-lg');
                        }

                        // L1(親)が閉じる時、L3(孫)も強制的に閉じる
                        const l2Button = l2Row.querySelector('.toggle-icon-l2');
                        if (l2Button) {
                            const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                            const l3Rows = document.querySelectorAll(l3TargetSelector);
                            l3Rows.forEach(l3Row => {
                                const l3CollapseInstance = bootstrap.Collapse.getInstance(l3Row);
                                if (l3CollapseInstance) {
                                    l3CollapseInstance.hide(); // L3を閉じる
                                }
                            });
                            // L2アイコンも強制的に '+' に戻す
                            const iconElementL2 = l2Button.querySelector('i');
                            if (iconElementL2) {
                                iconElementL2.classList.remove('bi-dash-lg');
                                iconElementL2.classList.add('bi-plus-lg');
                            }
                        }
                    });
                });
            });

            // L2 (勘定科目 / カテゴリ) のボタン
            document.querySelectorAll('.toggle-icon-l2').forEach(function(l2Button) {
                const iconElementL2 = l2Button.querySelector('i');
                const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                const l3Rows = document.querySelectorAll(l3TargetSelector);

                l3Rows.forEach(l3Row => {
                    const l3CollapseInstance = bootstrap.Collapse.getOrCreateInstance(l3Row, {
                        toggle: false
                    });

                    l3Row.addEventListener('show.bs.collapse', () => {
                        iconElementL2.classList.remove('bi-plus-lg');
                        iconElementL2.classList.add('bi-dash-lg');
                    });
                    l3Row.addEventListener('hide.bs.collapse', () => {
                        const otherL3s = Array.from(l3Rows).filter(el => el !== l3Row && el.classList.contains('show'));
                        if (otherL3s.length === 0) {
                            iconElementL2.classList.remove('bi-dash-lg');
                            iconElementL2.classList.add('bi-plus-lg');
                        }
                    });
                });
            });


            // --- アラート処理 ---
            const errorAlertElem = document.getElementById('errorAlert');
            if (errorAlertElem) {
                errorAlertElem.addEventListener('close.bs.alert', function() {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('error');
                    window.history.replaceState({}, document.title, url.pathname + url.search);
                });
            }
            const successAlertElem = document.getElementById('successAlert');
            if (successAlertElem) {
                successAlertElem.addEventListener('close.bs.alert', function() {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('success');
                    url.searchParams.delete('month');
                    window.history.replaceState({}, document.title, url.pathname + url.search);
                });
            }

            // 初期化処理
            onOfficeChange();
            calculate();
        });
    </script>
</body>

</html>