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
    // 1. 収入カテゴリと収入項目を取得 (営業所情報も結合)
    // --------------------------------------------------------
    $revenueQuery = "SELECT 
                        c.id AS category_id, 
                        c.name AS category_name, 
                        i.id AS item_id, 
                        i.name AS item_name, 
                        i.note AS item_note,
                        i.office_id AS item_office_id,
                        o.name AS item_office_name
                      FROM revenue_categories c
                      LEFT JOIN revenue_items i ON c.id = i.revenue_category_id
                      LEFT JOIN offices o ON i.office_id = o.id
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
                'note' => $row['item_note'],
                'office_id' => $row['item_office_id'],
                'office_name' => $row['item_office_name']
            ];
        }
    }

    // --------------------------------------------------------
    // 2. 勘定科目と詳細 (営業所情報も結合)
    // --------------------------------------------------------
    $accountsQuery = "SELECT 
                        a.id AS account_id,
                        a.name AS account_name,
                        d.id AS detail_id,
                        d.name AS detail_name,
                        d.note AS detail_note,
                        d.office_id AS detail_office_id,
                        o.name AS detail_office_name
                        FROM accounts a
                        LEFT JOIN details d ON a.id = d.account_id
                        LEFT JOIN offices o ON d.office_id = o.id
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
                'note' => $row['detail_note'],
                'office_id' => $row['detail_office_id'],
                'office_name' => $row['detail_office_name']
            ];
        }
    }

    // 営業所リスト
    $stmt = $dbh->query("SELECT * FROM offices ORDER BY identifier ASC");
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // 初期選択は最初の営業所
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
    <style>
        .icon-small {
            font-size: 0.8rem;
        }

        .details-cell {
            width: 150px;
        }

        /* 全社選択時の無効化スタイル調整 */
        .form-control:disabled {
            background-color: #f8f9fa;
            color: #6c757d;
        }
    </style>
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
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown"
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
                            <i class="bi bi-person-fill"></i>&nbsp; user name さん
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
                        <select id="officeSelect" class="form-select form-select-sm">
                            <option value="all">全社 (集計のみ)</option>
                            <?php foreach ($offices as $office): ?>
                                <option value="<?= $office['id'] ?>" <?= $office['id'] == $selectedOffice ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($office['identifier'] . ' : ' . $office['name']) ?>
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
                        <input type="number" step="1" id="hourlyRate" name="hourly_rate" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['hourly_rate'] ?? '') ?>" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>定時間</label>
                        <input type="number" step="0.01" class="form-control form-control-sm time-input" id="standardHours" data-field="standard_hours" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>残業時間</label>
                        <input type="number" step="0.01" class="form-control form-control-sm time-input" id="overtimeHours" data-field="overtime_hours" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>時間移動</label>
                        <input type="number" step="0.01" class="form-control form-control-sm time-input" id="transferredHours" data-field="transferred_hours" placeholder="0">
                    </div>
                    <div class="col-md-4"></div>
                </div>
                <div class="row align-items-end mb-2">
                    <div class="col-md-2">
                        <label>正社員</label>
                        <input type="number" step="1" class="form-control form-control-sm time-input" id="fulltimeCount" data-field="fulltime_count" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>契約社員</label>
                        <input type="number" step="1" class="form-control form-control-sm time-input" id="contractCount" data-field="contract_count" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>派遣社員</label>
                        <input type="number" step="1" class="form-control form-control-sm time-input" id="dispatchCount" data-field="dispatch_count" placeholder="0">
                    </div>
                    <div class="col-md-6"></div>
                </div>

                <input type="hidden" name="officeTimeData" id="officeTimeData">
                <input type="hidden" name="bulkJsonData" id="bulkJsonData">

                <button type="submit" class="btn btn-outline-danger btn-sm register-button">登録</button>
            </div>

            <div class="table-container mb-3" id="accordionRoot">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>項目</th>
                            <th style="width: 15%;">営業所</th>
                            <th style="width: 30%;">詳細</th>
                            <th style="width: 30%;">備考</th>
                            <th style="width: 150px;">CP</th>
                        </tr>
                    </thead>
                    <tbody>

                        <tr class="table-light">
                            <td colspan="5">
                                <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon toggle-icon-l1" data-bs-toggle="collapse" data-bs-target=".l1-revenue-group" aria-expanded="false">
                                    <i class="bi bi-plus-lg icon-small"></i>
                                </button>
                                <strong class="ms-2">収入の部</strong>
                                <span class="float-end fw-bold" id="total-revenue">0</span>
                            </td>
                        </tr>

                        <?php foreach ($revenues as $categoryId => $category): ?>
                            <tr class="collapse l1-revenue-group" id="l2-rev-row-<?= $categoryId ?>">
                                <td class="ps-4" colspan="4">
                                    <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon toggle-icon-l2" data-bs-toggle="collapse" data-bs-target="#child-rev-<?= $categoryId ?>" aria-expanded="false">
                                        <i class="bi bi-plus icon-small"></i>
                                    </button>
                                    <?= htmlspecialchars($category['name']) ?>
                                </td>
                                <td class="text-end fw-bold" id="total-revenue-category-<?= $categoryId ?>">0</td>
                            </tr>

                            <?php foreach ($category['items'] as $item): ?>
                                <?php
                                $revOfficeId = empty($item['office_id']) ? 'common' : $item['office_id'];
                                $revOfficeName = empty($item['office_name']) ? '共通' : $item['office_name'];
                                ?>
                                <tr class="collapse detail-row" id="child-rev-<?= $categoryId ?>" data-office-id="<?= htmlspecialchars($revOfficeId) ?>">
                                    <td></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($revOfficeName) ?></span>
                                    </td>
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
                                            data-id="<?= $item['id'] ?>"
                                            name="revenues[<?= $item['id'] ?>]"
                                            placeholder="0">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>


                        <tr class="table-light">
                            <td colspan="5">
                                <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon toggle-icon-l1" data-bs-toggle="collapse" data-bs-target=".l1-expense-group" aria-expanded="false">
                                    <i class="bi bi-plus-lg icon-small"></i>
                                </button>
                                <strong class="ms-2">経費の部</strong>
                                <span class="float-end fw-bold" id="total-expense">0</span>
                            </td>
                        </tr>

                        <?php foreach ($accounts as $accountId => $account): ?>
                            <tr class="collapse l1-expense-group" id="l2-acc-row-<?= $accountId ?>">
                                <td class="ps-4" colspan="4">
                                    <button type="button" class="btn btn-sm btn-light btn-icon toggle-icon toggle-icon-l2" data-bs-toggle="collapse" data-bs-target="#child-acc-<?= $accountId ?>" aria-expanded="false">
                                        <i class="bi bi-plus icon-small"></i>
                                    </button>
                                    <?= htmlspecialchars($account['name']) ?>
                                </td>
                                <td class="text-end fw-bold" id="total-account-<?= $accountId ?>">0</td>
                            </tr>

                            <?php foreach ($account['details'] as $detail): ?>
                                <?php
                                $officeIdStr = empty($detail['office_id']) ? 'common' : $detail['office_id'];
                                $officeNameDisplay = empty($detail['office_name']) ? '全社共通' : $detail['office_name'];
                                ?>
                                <tr class="collapse detail-row" id="child-acc-<?= $accountId ?>" data-office-id="<?= htmlspecialchars($officeIdStr) ?>">
                                    <td></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($officeNameDisplay) ?></span>
                                    </td>
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
                                            data-id="<?= $detail['id'] ?>"
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
        document.addEventListener('DOMContentLoaded', function() {

            // --- グローバル変数 ---
            const form = document.getElementById('cpForm');
            // 時間データ保持用オブジェクト
            const officeTimeData = {};

            const officeSelect = document.getElementById('officeSelect');
            const hourlyRateInput = document.getElementById('hourlyRate');
            const registerButton = document.querySelector('.register-button');

            // 時間・人数入力フィールド
            const timeFields = {
                standard_hours: document.getElementById('standardHours'),
                overtime_hours: document.getElementById('overtimeHours'),
                transferred_hours: document.getElementById('transferredHours'),
                fulltime_count: document.getElementById('fulltimeCount'),
                contract_count: document.getElementById('contractCount'),
                dispatch_count: document.getElementById('dispatchCount')
            };

            const revenueInputs = document.querySelectorAll('.revenue-input');
            const expenseInputs = document.querySelectorAll('.detail-input');

            let currentOfficeId = officeSelect.value;

            // --- 営業所切り替え時のデータ退避 ---
            function captureCurrentOfficeTime(oid) {
                // 「全社」モードのときはデータを保存しない（集計値なので）
                if (!oid || oid === 'all') return;

                const data = officeTimeData[oid] || {};

                // 時間・人数を保存
                for (const key in timeFields) {
                    const input = timeFields[key];
                    if (input) {
                        data[key] = input.value;
                    }
                }
                officeTimeData[oid] = data;
            }

            // --- 営業所切り替え時のデータ復元 (集計ロジック追加) ---
            function renderOfficeTime(oid) {
                // まず入力を有効化・クリア
                for (const key in timeFields) {
                    const input = timeFields[key];
                    if (input) {
                        input.disabled = false;
                        input.value = '';
                    }
                }

                // ★修正: 「全社」選択時の集計処理
                if (oid === 'all') {
                    // 全営業所のデータを合計
                    const totals = {
                        standard_hours: 0,
                        overtime_hours: 0,
                        transferred_hours: 0,
                        fulltime_count: 0,
                        contract_count: 0,
                        dispatch_count: 0
                    };

                    // officeTimeData に保存されている全データを走査
                    for (const id in officeTimeData) {
                        const data = officeTimeData[id];
                        totals.standard_hours += parseFloat(data.standard_hours) || 0;
                        totals.overtime_hours += parseFloat(data.overtime_hours) || 0;
                        totals.transferred_hours += parseFloat(data.transferred_hours) || 0;
                        totals.fulltime_count += parseInt(data.fulltime_count) || 0;
                        totals.contract_count += parseInt(data.contract_count) || 0;
                        totals.dispatch_count += parseInt(data.dispatch_count) || 0;
                    }

                    // 合計値をセットし、入力を無効化
                    for (const key in totals) {
                        const input = timeFields[key];
                        if (input) {
                            input.value = totals[key];
                            input.disabled = true; // 全社モードでは編集不可
                        }
                    }
                    return;
                }

                // 通常の営業所選択時
                if (oid && officeTimeData[oid]) {
                    const data = officeTimeData[oid];
                    for (const key in timeFields) {
                        const input = timeFields[key];
                        if (input) {
                            input.value = data[key] !== undefined ? data[key] : '';
                        }
                    }
                }
            }

            // --- 行のフィルタリング (営業所による表示制御) ---
            function filterDetailsByOffice() {
                const selectedOfficeId = officeSelect.value;
                const detailRows = document.querySelectorAll('.detail-row');

                detailRows.forEach(row => {
                    const rowOfficeId = row.getAttribute('data-office-id');
                    const inputs = row.querySelectorAll('input');

                    // ★修正: 「全社」選択時はすべて表示
                    if (selectedOfficeId === 'all') {
                        row.style.display = '';
                        // 全社モードのときは明細入力も無効化する（誤入力防止）
                        inputs.forEach(input => input.disabled = true);
                    }
                    // 通常モード：共通(common) または 選択された営業所 と一致する場合に表示
                    else if (rowOfficeId === 'common' || rowOfficeId === selectedOfficeId) {
                        row.style.display = '';
                        inputs.forEach(input => input.disabled = false);
                    } else {
                        row.style.display = 'none';
                        // サーバーへは bulkJsonData で送るため、ここでは計算対象外にするためだけの制御で良い
                        // UI上は隠れているので操作不可
                    }
                });

                calculate();

                // 全社モードなら登録ボタンを無効化（あるいは警告付きにする）
                if (selectedOfficeId === 'all') {
                    registerButton.disabled = true;
                    registerButton.textContent = '全社モードでは登録できません';
                } else {
                    registerButton.disabled = false;
                    registerButton.textContent = '登録';
                }
            }

            // --- メイン計算関数 ---
            function calculate() {
                let currentHours = 0;
                // 現在のDOM上の入力値を取得（全社モードなら合計値が入っている）
                const std = parseFloat(timeFields.standard_hours.value) || 0;
                const ovt = parseFloat(timeFields.overtime_hours.value) || 0;
                const trn = parseFloat(timeFields.transferred_hours.value) || 0;
                currentHours = std + ovt + trn;

                const hourlyRate = parseFloat(hourlyRateInput.value) || 0;
                const laborCost = Math.round(currentHours * hourlyRate);

                document.getElementById('info-labor-cost').textContent = laborCost.toLocaleString();

                // 2. 収入合計 (表示されている行のみ集計)
                let revenueTotal = 0;
                const revenueCategoryTotals = {};

                revenueInputs.forEach(input => {
                    const row = input.closest('tr');
                    // 非表示行は計算対象外
                    if (row && row.style.display === 'none') return;

                    const val = parseFloat(input.value) || 0;
                    const categoryId = input.getAttribute('data-category-id');
                    revenueTotal += val;
                    if (!revenueCategoryTotals[categoryId]) revenueCategoryTotals[categoryId] = 0;
                    revenueCategoryTotals[categoryId] += val;
                });

                document.getElementById('total-revenue').textContent = Math.round(revenueTotal).toLocaleString();
                document.getElementById('info-revenue-total').textContent = Math.round(revenueTotal).toLocaleString();

                Object.keys(revenueCategoryTotals).forEach(categoryId => {
                    const elem = document.getElementById(`total-revenue-category-${categoryId}`);
                    if (elem) elem.textContent = Math.round(revenueCategoryTotals[categoryId]).toLocaleString();
                });

                // 3. 経費合計 (表示されている行のみ集計)
                let expenseTotal = 0;
                const accountTotals = {};

                expenseInputs.forEach(input => {
                    const row = input.closest('tr');
                    if (row && row.style.display === 'none') return;

                    const val = parseFloat(input.value) || 0;
                    const accountId = input.getAttribute('data-account-id');
                    expenseTotal += val;
                    if (!accountTotals[accountId]) accountTotals[accountId] = 0;
                    accountTotals[accountId] += val;
                });

                document.getElementById('total-expense').textContent = Math.round(expenseTotal).toLocaleString();
                document.getElementById('info-expense-total').textContent = Math.round(expenseTotal).toLocaleString();

                Object.keys(accountTotals).forEach(accountId => {
                    const elem = document.getElementById(`total-account-${accountId}`);
                    if (elem) elem.textContent = Math.round(accountTotals[accountId]).toLocaleString();
                });

                // 4. 差引収益 (収入 - 経費)
                const grossProfit = revenueTotal - expenseTotal;
                document.getElementById('info-gross-profit').textContent = Math.round(grossProfit).toLocaleString();
            }

            // --- イベントリスナー ---

            // 入力イベント
            form.addEventListener('input', function(event) {
                if (event.target.classList.contains('time-input') ||
                    event.target.id === 'hourlyRate' ||
                    event.target.classList.contains('revenue-input') ||
                    event.target.classList.contains('detail-input')) {
                    calculate();
                }
            });

            // 営業所変更
            officeSelect.addEventListener('change', function() {
                // 前の営業所のデータを保存 (全社モード以外の場合)
                captureCurrentOfficeTime(currentOfficeId);

                // 新しい営業所IDを取得
                currentOfficeId = officeSelect.value;

                // 新しい営業所のデータを復元 (全社なら集計)
                renderOfficeTime(currentOfficeId);

                // 行の表示切り替え
                filterDetailsByOffice();
            });

            // 年度変更
            window.onYearChange = function() {
                const year = document.getElementById('yearSelect').value;
                const url = new URL(window.location.href);
                url.searchParams.set('year', year);
                window.location.href = url.toString();
            };

            // --- 送信時処理 (修正版) ---
            form.addEventListener("submit", function(e) {
                // 送信前に現在の営業所データを保存
                captureCurrentOfficeTime(currentOfficeId);

                // 「全社」が選択されている状態での送信チェック
                if (currentOfficeId === 'all') {
                    e.preventDefault();
                    alert('「全社」モードは閲覧用です。各営業所を選択して登録してください。');
                    return;
                }

                // 全営業所のデータに共通の賃率をセット
                const rate = hourlyRateInput.value;
                for (const officeId in officeTimeData) {
                    if (officeTimeData[officeId]) {
                        officeTimeData[officeId]['hourly_rate'] = rate;
                    }
                }
                document.getElementById("officeTimeData").value = JSON.stringify(officeTimeData);

                // ---------------------------------------------------------
                // ★追加: 収入・経費データをJSONにまとめて hidden にセット
                // ---------------------------------------------------------
                const bulkData = {
                    revenues: {},
                    accounts: {}
                };

                // 収入データの収集 (表示・非表示に関わらず全ての .revenue-input を取得)
                document.querySelectorAll('.revenue-input').forEach(input => {
                    const id = input.getAttribute('data-id'); // HTMLに追加した data-id
                    const val = input.value;
                    if (id) {
                        bulkData.revenues[id] = val;
                    }
                });

                // 経費データの収集 (表示・非表示に関わらず全ての .detail-input を取得)
                document.querySelectorAll('.detail-input').forEach(input => {
                    const id = input.getAttribute('data-id'); // HTMLに追加した data-id
                    const val = input.value;
                    if (id) {
                        bulkData.accounts[id] = val;
                    }
                });

                // JSON化して hidden input にセット
                document.getElementById('bulkJsonData').value = JSON.stringify(bulkData);
            });

            // --- アコーディオン制御 ---
            const l1Toggles = [{
                    btn: document.querySelector('[data-bs-target=".l1-revenue-group"]'),
                    targetClass: '.l1-revenue-group'
                },
                {
                    btn: document.querySelector('[data-bs-target=".l1-expense-group"]'),
                    targetClass: '.l1-expense-group'
                }
            ];

            l1Toggles.forEach(l1 => {
                if (!l1.btn) return;
                const iconElementL1 = l1.btn.querySelector('i');
                const l2Rows = document.querySelectorAll(l1.targetClass);

                l2Rows.forEach(l2Row => {
                    l2Row.addEventListener('show.bs.collapse', () => {
                        if (iconElementL1) {
                            iconElementL1.classList.remove('bi-plus-lg');
                            iconElementL1.classList.add('bi-dash-lg');
                        }
                    });
                    l2Row.addEventListener('hide.bs.collapse', () => {
                        setTimeout(() => {
                            const anyOpen = Array.from(l2Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                            if (!anyOpen && iconElementL1) {
                                iconElementL1.classList.remove('bi-dash-lg');
                                iconElementL1.classList.add('bi-plus-lg');
                            }
                        }, 350);

                        const l2Button = l2Row.querySelector('.toggle-icon-l2');
                        if (l2Button) {
                            const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                            if (l3TargetSelector) {
                                document.querySelectorAll(l3TargetSelector).forEach(el => {
                                    const inst = bootstrap.Collapse.getInstance(el);
                                    if (inst) inst.hide();
                                });
                                const iconL2 = l2Button.querySelector('i');
                                if (iconL2) {
                                    iconL2.classList.remove('bi-dash');
                                    iconL2.classList.add('bi-plus');
                                }
                            }
                        }
                    });
                });
            });

            // L2 ボタン (toggle-icon-l2)
            document.querySelectorAll('.toggle-icon-l2').forEach(function(l2Button) {
                const iconElementL2 = l2Button.querySelector('i');
                const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                if (!l3TargetSelector) return;
                const l3Rows = document.querySelectorAll(l3TargetSelector);

                l3Rows.forEach(l3Row => {
                    l3Row.addEventListener('show.bs.collapse', () => {
                        if (iconElementL2) {
                            iconElementL2.classList.remove('bi-plus');
                            iconElementL2.classList.add('bi-dash');
                        }
                    });
                    l3Row.addEventListener('hide.bs.collapse', () => {
                        setTimeout(() => {
                            const anyOpen = Array.from(l3Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                            if (!anyOpen && iconElementL2) {
                                iconElementL2.classList.remove('bi-dash');
                                iconElementL2.classList.add('bi-plus');
                            }
                        }, 350);
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

            // 初期化
            // 初回が「全社」でない場合は通常の描画、「全社」の場合は集計描画
            renderOfficeTime(currentOfficeId);
            filterDetailsByOffice();
            calculate();
        });
    </script>
</body>

</html>