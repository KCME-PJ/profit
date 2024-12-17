<?php
require_once '../includes/database.php';
$dbh = getDb();

// データ取得
$query = "SELECT a.id AS account_id, a.name AS account_name, 
                 d.id AS detail_id, d.name AS detail_name, 
                 IFNULL(SUM(m.amount), 0) AS total_amount
          FROM accounts a
          LEFT JOIN details d ON a.id = d.account_id
          LEFT JOIN monthly_cp m ON d.id = m.detail_id
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
    <style>
        a {
            text-decoration: none;
            /* アンダーラインを削除 */
        }

        .table th,
        .table td {
            vertical-align: middle;
        }

        .info-box {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .info-box input {
            width: 100px;
            text-align: right;
        }
    </style>
</head>

<body>
    <div class="container mt-4">
        <h1 class="mb-4">CP 計画入力</h1>

        <!-- 上部の入力フォーム -->
        <div class="info-box">
            <div class="row">
                <div class="col-md-2">
                    <label>定時間 (時間)</label>
                    <input type="number" step="0.01" id="standardHours" class="form-control" value="0">
                </div>
                <div class="col-md-2">
                    <label>残業時間 (時間)</label>
                    <input type="number" step="0.01" id="overtimeHours" class="form-control" value="0">
                </div>
                <div class="col-md-2">
                    <label>時間移動 (時間)</label>
                    <input type="number" step="0.01" id="transferredHours" class="form-control" value="0">
                </div>
                <div class="col-md-2">
                    <label>賃率 (¥)</label>
                    <input type="number" step="1" id="hourlyRate" class="form-control" value="0">
                </div>
                <div class="col-md-2">
                    <strong>総時間：</strong> <span id="totalHours">0.00 時間</span><br>
                    <strong>経費合計：</strong> ¥<span id="expenseTotal">0</span>
                </div>
                <div class="col-md-2">
                    <strong>労務費：</strong> ¥<span id="laborCost">0</span><br>
                    <strong>総合計：</strong> ¥<span id="grandTotal">0</span>
                </div>
            </div>
        </div>

        <!-- 勘定科目と詳細の入力フォーム -->
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>勘定科目/詳細</th>
                    <th style="width: 150px;">CP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $accountId => $account): ?>
                    <!-- 勘定科目（親） -->
                    <tr>
                        <td>
                            <a href="#account-<?= $accountId ?>" data-bs-toggle="collapse" class="text-dark">
                                <i class="bi bi-plus-lg me-2"></i><?= htmlspecialchars($account['name']) ?>
                            </a>
                        </td>
                        <td class="text-end fw-bold" id="account-total-<?= $accountId ?>">
                            <?= $account['total'] ?>
                        </td>
                    </tr>
                    <!-- 詳細（子） -->
                    <tr>
                        <td colspan="2" class="p-0">
                            <div class="collapse" id="account-<?= $accountId ?>">
                                <table class="table mb-0">
                                    <?php foreach ($account['details'] as $detail): ?>
                                        <tr>
                                            <td class="ps-4"><?= htmlspecialchars($detail['name']) ?></td>
                                            <td>
                                                <input type="number" step="1" class="form-control text-end detail-input"
                                                    data-account-id="<?= $accountId ?>" value="<?= $detail['amount'] ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function calculate() {
            const standardHours = parseFloat(document.getElementById('standardHours').value) || 0;
            const overtimeHours = parseFloat(document.getElementById('overtimeHours').value) || 0;
            const transferredHours = parseFloat(document.getElementById('transferredHours').value) || 0;
            const hourlyRate = parseFloat(document.getElementById('hourlyRate').value) || 0;

            // 労務費と総時間
            const totalHours = standardHours + overtimeHours + transferredHours;
            const laborCost = totalHours * hourlyRate;

            document.getElementById('totalHours').innerText = totalHours.toFixed(2);
            document.getElementById('laborCost').innerText = Math.round(laborCost);

            // 経費合計の計算
            let expenseTotal = 0;
            document.querySelectorAll('.detail-input').forEach(input => {
                expenseTotal += parseInt(input.value) || 0;
            });

            document.getElementById('expenseTotal').innerText = expenseTotal;

            // 各勘定科目の合計更新
            let grandTotal = laborCost + expenseTotal;
            document.getElementById('grandTotal').innerText = Math.round(grandTotal);

            // 勘定科目の詳細合計を更新
            document.querySelectorAll('.detail-input').forEach(function(input) {
                const accountId = input.getAttribute('data-account-id');
                let accountTotal = 0;

                // その勘定科目の合計を再計算
                document.querySelectorAll(`.detail-input[data-account-id="${accountId}"]`).forEach(function(accountInput) {
                    accountTotal += parseInt(accountInput.value) || 0;
                });

                // 勘定科目の合計を表示
                document.getElementById(`account-total-${accountId}`).innerText = accountTotal;
            });
        }

        // イベントリスナーを設定
        document.querySelectorAll('.info-box input, .detail-input').forEach(input => {
            input.addEventListener('input', calculate);
        });

        calculate(); // 初期計算
    </script>
</body>

</html>