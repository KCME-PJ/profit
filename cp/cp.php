<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';
require_once '../includes/cp_ui_functions.php';

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
?>
    <!DOCTYPE html>
    <html lang="ja">

    <head>
        <meta charset="UTF-8">
        <title>リダイレクト中</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>

    <body>
        <div class="modal fade" id="adminRedirectModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title" id="staticBackdropLabel"><i class="bi bi-exclamation-triangle-fill"></i> アクセス制限</h5>
                    </div>
                    <div class="modal-body">
                        <p><strong>管理者権限（Admin）では、この画面からの新規登録は行えません。</strong></p>
                        <p>新規作成は各拠点の担当者に一任されています。<br>状況確認や修正は「CP編集画面」へ移動してください。</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" id="redirectBtn">CP編集画面へ移動</button>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var myModal = new bootstrap.Modal(document.getElementById('adminRedirectModal'));
                myModal.show();
                document.getElementById('redirectBtn').addEventListener('click', function() {
                    window.location.href = 'cp_edit.php';
                });
            });
        </script>
    </body>

    </html>
<?php
    exit;
}

// DB接続
$dbh = getDb();

try {
    // 年度選択
    $availableYears = getAvailableCpYears($dbh);
    $selectedYear = (int)($_GET['year'] ?? date('Y'));
    $selectedMonth = (int)($_GET['month'] ?? 4);
    $currentYear = $selectedYear;

    // 営業所リストの取得
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'manager' && !empty($_SESSION['office_id'])) {
        $stmt = $dbh->prepare("SELECT * FROM offices WHERE id = ?");
        $stmt->execute([$_SESSION['office_id']]);
    } else {
        $stmt = $dbh->query("SELECT * FROM offices ORDER BY identifier ASC");
    }
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 初期選択IDの決定
    $selectedOffice = 0;
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'manager') {
        $selectedOffice = (int)$_SESSION['office_id'];
    } elseif (!empty($offices)) {
        $selectedOffice = (int)$offices[0]['id'];
    }

    // 各月のステータス
    $cpStatusList = getCpStatusByYear($currentYear, $dbh, $selectedOffice);
    $statusColors = ['fixed' => 'success', 'draft' => 'primary', 'none' => 'secondary'];

    // --------------------------------------------------------
    // 1. 収入カテゴリと収入項目を取得 (ソート)
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
    // 2. 勘定科目と詳細 (ソート順)
    // --------------------------------------------------------
    // accounts.sort_order, details.sort_order を優先
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
                        ORDER BY a.sort_order ASC, a.id ASC, d.sort_order ASC, d.id ASC";
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
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">CP</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="./cp_edit.php">CP編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">見通し</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../forecast/forecast_edit.php">見通し編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">予定</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../plan/plan_edit.php">予定編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">月末見込み</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../outlook/outlook_edit.php">月末見込み編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">概算</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../result/result_edit.php">概算実績編集</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">勘定科目設定</a>
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
                            <li><a class="dropdown-item" href="../users/">ユーザー管理</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
            <div class="navbar-nav ms-auto">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
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
                    <h4 class="mb-2">CP 計画入力</h4>
                </div>
                <div class="col-md-10">
                    <label class="form-label mb-1">
                        各月の状況：<span class="text-secondary">未登録</span>、<span class="text-primary">登録済</span>、<span class="text-success">確定済</span>
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

            <div class="info-box">
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
                    <div class="col-md-2">
                        <label>総時間</label>
                        <input type="number" step="0.01" id="totalHours" class="form-control form-control-sm" readonly placeholder="0" style="background-color: #e9ecef; font-weight: bold;">
                    </div>
                    <div class="col-md-2"></div>
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
                                        <?= htmlspecialchars($detail['note']) ?>
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
        // PHPのデータをグローバル変数として定義し、外部JSファイルから参照できるようにする
        window.cpStatusMap = <?= json_encode($cpStatusList) ?>;
    </script>
    <script src="../js/cp.js"></script>

</body>

</html>