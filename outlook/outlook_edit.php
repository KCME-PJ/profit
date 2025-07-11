<?php
require_once '../includes/database.php';

// データベース接続
$dbh = getDb();

try {
    // 月次 outlook 登録済みの年と月を取得
    $query = "SELECT DISTINCT year, month FROM monthly_outlook ORDER BY year DESC, month DESC";
    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $registeredDates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 年度ごとにグループ化
    $years = [];
    foreach ($registeredDates as $date) {
        $years[$date['year']][] = $date['month'];
    }
    foreach ($years as &$months) {
        sort($months);
    }
    unset($months);

    // 勘定科目と詳細の取得
    $accountsQuery = "SELECT a.id AS account_id, a.name AS account_name, d.id AS detail_id, d.name AS detail_name
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
                'name' => $row['detail_name']
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
    <title>月末見込み 修正</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/edit.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-primary p-0" data-bs-theme="dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.html">採算表</a>
            <div class="collapse navbar-collapse">
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
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">月末見込み
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">概算
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
        </div>
    </nav>

    <div class="container mt-4">
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_GET['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
            </div>
        <?php endif; ?>
        <form id="mainForm" action="outlook_update.php" method="POST">
            <h4 class="mb-4">月末見込み 編集</h4>

            <div class="info-box">
                <div class="row">
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
                    <input type="hidden" id="outlookId" name="outlook_id">
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
                <button type="button" class="btn btn-outline-danger btn-sm register-button1 mt-3" data-bs-toggle="modal" data-bs-target="#confirmModal">修正</button>
                <button type="button" class="btn btn-outline-success btn-sm register-button2 mt-3" data-bs-toggle="modal" data-bs-target="#fixModal">確定</button>
            </div>

            <div class="table-container">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>勘定科目/詳細</th>
                            <th style="width: 150px;">金額</th>
                        </tr>
                    </thead>
                    <tbody>
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
                            <?php foreach ($account['details'] as $detail): ?>
                                <tr class="collapse" id="child-<?= $accountId ?>">
                                    <td class="ps-4"><?= htmlspecialchars($detail['name']) ?></td>
                                    <td class="details-cell">
                                        <input type="hidden" name="detail_ids[]" value="<?= $detail['id'] ?>">
                                        <input type="number" step="1" class="form-control form-control-sm text-end input-value detail-input"
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
            <input type="hidden" name="action_type" id="outlookMode" value="update">
        </form>

        <!-- 修正モーダル -->
        <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">修正の確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">本当に修正してもよろしいですか？</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="button" class="btn btn-danger" id="confirmSubmit">はい、修正する</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 確定モーダル -->
        <div class="modal fade" id="fixModal" tabindex="-1" aria-labelledby="cpFixModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">確定の確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">本当に確定してよろしいですか？</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="button" class="btn btn-success" id="outlookFixConfirmBtn">はい、確定する</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.toggle-icon').forEach(function(icon) {
                icon.addEventListener('click', function() {
                    const iconElement = icon.querySelector('i');
                    iconElement.classList.toggle('bi-plus-lg');
                    iconElement.classList.toggle('bi-dash-lg');
                });
            });

            document.getElementById('confirmSubmit').addEventListener('click', function() {
                document.getElementById('outlookMode').value = 'update';
                document.getElementById('mainForm').submit();
            });
        });

        const yearMonthData = <?= json_encode($years) ?>;
    </script>
    <script>
        document.getElementById('confirmSubmit').addEventListener('click', function() {
            document.getElementById('outlookMode').value = 'update';
            document.getElementById('mainForm').submit();
        });
        document.getElementById('outlookFixConfirmBtn').addEventListener('click', function() {
            document.getElementById('outlookMode').value = 'fixed';
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
    <script src="../js/outlook_edit.js"></script>
</body>

</html>