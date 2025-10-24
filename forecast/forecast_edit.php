<?php
require_once '../includes/database.php';
require_once '../includes/forecast_ui_functions.php';

// データベース接続
$dbh = getDb();

try {
    // 月次 forecast 登録済みの年と月を取得
    $query = "SELECT DISTINCT year, month FROM monthly_forecast ORDER BY year DESC, month DESC";
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

    // 勘定科目、詳細情報の取得
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
    // エラーハンドリング
    $accounts = [];
}

// 営業所リスト
$stmt = $dbh->query("SELECT * FROM offices ORDER BY id ASC");
$offices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedOffice = $offices[0]['id'] ?? 0;
?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>見通し 編集</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/edit.css">
    <script>
        window.yearMonthData = <?= json_encode($years) ?>;
    </script>
    <script src="../js/forecast_edit_head.js" defer></script>
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
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">見通し
                        </a>
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
        <form id="mainForm" action="forecast_update.php" method="POST">
            <div class="row mb-3">
                <div class="col-md-2">
                    <h4 class="mb-2" id="editTitle">見通し 編集</h4>
                </div>
                <div class="col-md-6">
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
                            <button type="button" id="monthBtn<?= $month ?>" class="btn btn-<?= $colorClass ?> btn-sm me-1 mb-1" disabled>
                                <?= $month ?>月
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="col-md-2 mt-4">
                    <a href="#" id="excelExportBtn1" class="btn btn-outline-primary btn-sm" data-export-type="summary">Excel出力（集計）</a>
                </div>
                <div class="col-md-2 mt-4">
                    <a href="#" id="excelExportBtn2" class="btn btn-outline-primary btn-sm" data-export-type="details">Excel出力（明細）</a>
                </div>
            </div>

            <!-- 上部の入力フォーム -->
            <div class="info-box">
                <div class="row align-items-end mb-2">
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
                    <div class="col-md-2">
                        <label>営業所</label>
                        <select id="officeSelect" class="form-select form-select-sm">
                            <?php foreach ($offices as $office): ?>
                                <option value="<?= $office['id'] ?>" <?= $office['id'] == $selectedOffice ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($office['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <strong>総時間：</strong><span id="totalHours">0.00 時間</span><br>
                        <strong>労務費：</strong>¥<span id="laborCost">0</span>
                    </div>
                    <div class="col-md-3">
                        <strong>経費合計：</strong>¥<span id="expenseTotal">0</span><br>
                        <strong>総合計：</strong>¥<span id="grandTotal">0</span>
                    </div>
                </div>
                <div class="row align-items-end mb-2">
                    <input type="hidden" id="monthlyForecastId" name="monthly_forecast_id">
                    <div class="col-md-2">
                        <label>賃率</label>
                        <input type="number" step="1" id="hourlyRate" name="hourly_rate" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>定時間</label>
                        <input type="number" step="0.01" id="standardHours" class="form-control form-control-sm" data-field="standard_hours" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>残業時間</label>
                        <input type="number" step="0.01" id="overtimeHours" class="form-control form-control-sm" data-field="overtime_hours" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label>時間移動</label>
                        <input type="number" step="0.01" id="transferredHours" class="form-control form-control-sm" data-field="transferred_hours" placeholder="0">
                    </div>
                    <div class="col-md-4"></div>
                </div>

                <div class="row align-items-end mb-2">
                    <div class="col-md-2">
                        <label>正社員</label>
                        <input type="number" id="fulltimeCount" class="form-control form-control-sm" data-field="fulltime_count" min="0">
                    </div>
                    <div class="col-md-2">
                        <label>契約社員</label>
                        <input type="number" id="contractCount" class="form-control form-control-sm" data-field="contract_count" min="0">
                    </div>
                    <div class="col-md-2">
                        <label>派遣社員</label>
                        <input type="number" id="dispatchCount" class="form-control form-control-sm" data-field="dispatch_count" min="0">
                    </div>
                    <div class="col-md-6"></div>
                </div>
                <input type="hidden" name="officeTimeData" id="officeTimeData">
                <button type="button" class="btn btn-outline-danger btn-sm register-button1" data-bs-toggle="modal" data-bs-target="#confirmModal">修正</button>
                <button type="button" class="btn btn-outline-success btn-sm register-button2" data-bs-toggle="modal" data-bs-target="#fixModal">確定</button>
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
            <input type="hidden" name="action_type" id="forecastMode" value="update">
        </form>
        <!-- 修正モーダル -->
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
        <!-- 確定モーダル -->
        <div class="modal fade" id="fixModal" tabindex="-1" aria-labelledby="fixModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="fixModalLabel">確定の確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        本当に確定してよろしいですか？
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="button" class="btn btn-success" id="forecastFixConfirmBtn">はい、確定する</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ----- URL パラメータのクリーンアップ処理 -----
            // エラーまたは成功メッセージのパラメータが存在する場合、それをURLから削除して履歴を書き換える
            const cleanUrl = function() {
                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    let shouldReplace = false;

                    // success, error, year, month パラメータを全て削除
                    if (url.searchParams.has('error')) {
                        url.searchParams.delete('error');
                        shouldReplace = true;
                    }
                    if (url.searchParams.has('success')) {
                        url.searchParams.delete('success');
                        shouldReplace = true;
                    }

                    // ★ 修正後の追加ロジック: yearとmonthが残っていた場合も削除
                    if (url.searchParams.has('year')) {
                        url.searchParams.delete('year');
                        shouldReplace = true;
                    }
                    if (url.searchParams.has('month')) {
                        url.searchParams.delete('month');
                        shouldReplace = true;
                    }

                    if (shouldReplace) {
                        // URLをパスのみに書き換え
                        window.history.replaceState({}, document.title, url.pathname);

                        // アドレスバーをクリーンにした後、ドロップダウンの表示も初期状態に戻す
                        const yearSelect = document.getElementById('yearSelect');
                        const monthSelect = document.getElementById('monthSelect');

                        if (yearSelect) {
                            yearSelect.value = ''; // 年度をリセット

                            // 月のドロップダウンもリセットして無効化する
                            if (monthSelect) {
                                monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
                                monthSelect.disabled = true;
                            }
                        }
                    }
                }
            };

            // ページロード時
            // success/error パラメータを即座に削除し、URLをクリーンに保つ
            cleanUrl();

            // Bootstrap Alertが閉じられたとき（ユーザーが×ボタンを押したとき）
            const successAlert = document.getElementById('successAlert');
            const errorAlert = document.getElementById('errorAlert');

            // DOMにAlertが存在すれば、閉じられたイベントをリッスン
            if (successAlert) {
                // Alertが閉じた後にURLをクリーンにする
                successAlert.addEventListener('closed.bs.alert', cleanUrl);
            }
            if (errorAlert) {
                errorAlert.addEventListener('closed.bs.alert', cleanUrl);
            }
        });
    </script>
    <script src="../js/forecast_edit_body.js"></script>
</body>

</html>