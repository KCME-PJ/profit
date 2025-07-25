<?php
require_once '../includes/database.php';

// データベース接続
$dbh = getDb();

try {
    // 月次 CP 登録済みの年と月を取得
    $query = "SELECT DISTINCT year, month FROM monthly_cp";
    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $registeredDates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // データを年ごとにグループ化
    $years = [];
    foreach ($registeredDates as $date) {
        $years[$date['year']][] = $date['month'];
    }
    foreach ($years as &$months) {
        sort($months); // 昇順に並べ替え
    }
    unset($months); // 参照解除

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
    <title>CP 編集</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/edit.css">
    <script>
        window.yearMonthData = <?= json_encode($years) ?>;
    </script>
    <script src="../js/cp_edit_head.js" defer></script>
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
                            <li><a class="dropdown-item" href="./cp.php">CP計画</a></li>
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
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_GET['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>

        <form id="mainForm" action="./cp_update.php" method="POST">
            <h4 class="mb-2" id="editTitle">CP 編集</h4>

            <div class="mb-3">
                <label class="form-label mb-1">
                    各月の状況：<span class="text-secondary">未登録</span>、<span class="text-primary">登録済</span>、<span class="text-success">確定済</span>
                </label><br>

                <div id="monthButtonsContainer">
                    <?php
                    $startMonth = 4;
                    for ($i = 0; $i < 12; $i++):
                        $month = ($startMonth + $i - 1) % 12 + 1;
                        $colorClass = 'secondary';
                    ?>
                        <button type="button" class="btn btn-<?= $colorClass ?> btn-sm me-1 mb-1" disabled>
                            <?= $month ?>月
                        </button>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- 上部の入力フォーム -->
            <div class="info-box">
                <div class="row">
                    <?php
                    $currentYear = isset($_GET['year']) ? (int)$_GET['year'] : null;
                    ?>
                    <!-- 年度と月の選択 -->
                    <div class="col-md-2">
                        <label>年度</label>
                        <select id="yearSelect" name="year" class="form-select form-select-sm">
                            <option value="" disabled <?= is_null($currentYear) ? 'selected' : '' ?>>年度を選択</option>
                            <?php foreach (array_keys($years) as $year): ?>
                                <option value="<?= $year ?>" <?= (int)$year === $currentYear ? 'selected' : '' ?>>
                                    <?= $year ?>年度
                                </option>
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
                    <input type="hidden" id="monthlyCpId" name="monthly_cp_id">
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
                            <input type="number" id="fulltimeCount" name="fulltime_count" class="form-control form-control-sm" min="0">
                        </div>
                        <div class="col-md-2">
                            <label>契約社員</label>
                            <input type="number" id="contractCount" name="contract_count" class="form-control form-control-sm" min="0">
                        </div>
                        <div class="col-md-2">
                            <label>派遣社員</label>
                            <input type="number" id="dispatchCount" name="dispatch_count" class="form-control form-control-sm" min="0">
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
                <button type="button" class="btn btn-outline-danger btn-sm register-button1" data-bs-toggle="modal" data-bs-target="#confirmModal">修正</button>
                <button type="button" class="btn btn-outline-success btn-sm register-button2" data-bs-toggle="modal" data-bs-target="#cpFixModal">確定</button>
                <a href="#" id="excelExportBtn" class="btn btn-outline-primary btn-sm register-button3">Excel出力</a>
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
            <!-- 処理モード用の hidden input -->
            <input type="hidden" name="action_type" id="cpMode" value="update">
        </form>
        <!-- CP修正モーダル -->
        <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmModalLabel">修正の確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        本当に修正してもよろしいですか？
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="button" class="btn btn-danger" id="confirmSubmit">はい、修正する</button>
                    </div>
                </div>
            </div>
        </div>
        <!-- CP確定モーダル -->
        <div class="modal fade" id="cpFixModal" tabindex="-1" aria-labelledby="cpFixModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="cpFixModalLabel">CP確定</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        この内容でCPを確定して、見通しへ反映します。よろしいですか？
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="button" class="btn btn-primary" id="cpFixConfirmBtn">はい、確定</button>
                    </div>
                </div>
            </div>
        </div>


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
        // CP修正モーダルの「はい」ボタンを押したとき
        document.getElementById('confirmSubmit').addEventListener('click', function() {
            document.getElementById('cpMode').value = 'update'; // 修正モード
            document.getElementById('mainForm').submit();
        });
    </script>
    <script>
        // CP確定モーダルの「はい」ボタンを押したとき
        document.getElementById('cpFixConfirmBtn').addEventListener('click', function() {
            document.getElementById('cpMode').value = 'fixed'; // 確定モード
            document.getElementById('mainForm').submit();
        });
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
    <script src="../js/cp_edit_body.js"></script>
</body>

</html>