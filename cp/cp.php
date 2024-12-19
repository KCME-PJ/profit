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
        }

        .table th,
        .table td {
            vertical-align: middle;
        }

        /* 上部入力フォームのレイアウト */
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

        /* ツリー構造の「+、-」アイコン装飾 */
        .toggle-icon {
            font-size: 0.7rem;
            line-height: 1;
            text-align: center;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 0.2rem;
            width: 1rem;
            height: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        /* 数値入力部分のスピナーを非表示 */
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            appearance: none;
            margin: 0;
        }

        input[type="number"] {
            appearance: none;
        }

        /* 詳細の入力部分の幅を調整 */
        .detail-input {
            width: 150px;
        }

        .details-cell {
            text-align: right;
        }

        .details-cell input {
            text-align: right;
            /* 入力欄内のテキストを右寄せ */
            width: 150px;
            /* 入力欄の幅を設定 */
        }

        /* tableのタイトル行を固定 */
        .table-container {
            max-height: 400px;
            /* 必要に応じて高さを調整 */
            overflow-y: auto;
            /* 縦スクロールを有効化 */
            border: 1px solid #ddd;
            /* テーブルの境界を視覚化 */
        }

        .table th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            /* ヘッダーの背景色を設定 */
            z-index: 2;
            /* 他の要素より上に表示されるようにする */
        }
    </style>
</head>

<body>
    <div class="container mt-4">
        <h3 class="mb-4">CP 計画入力</h3>

        <!-- 上部の入力フォーム -->
        <div class="info-box">
            <div class="row">
                <div class="col-md-2">
                    <label>定時間 (時間)</label>
                    <input type="number" step="0.01" id="standardHours" class="form-control" placeholder="0">
                </div>
                <div class="col-md-2">
                    <label>残業時間 (時間)</label>
                    <input type="number" step="0.01" id="overtimeHours" class="form-control" placeholder="0">
                </div>
                <div class="col-md-2">
                    <label>時間移動 (時間)</label>
                    <input type="number" step="0.01" id="transferredHours" class="form-control" placeholder="0">
                </div>
                <div class="col-md-2">
                    <label>賃率 (¥)</label>
                    <input type="number" step="1" id="hourlyRate" class="form-control" placeholder="0">
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
                        <!-- 勘定科目（親） -->
                        <tr>
                            <td>
                                <button class="btn btn-sm btn-light btn-icon toggle-icon" data-bs-toggle="collapse"
                                    data-bs-target="#child-<?= $accountId ?>" aria-expanded="true">
                                    <i class="bi bi-plus icon-small"></i>
                                </button>
                                <?= htmlspecialchars($account['name']) ?>
                            </td>
                            <td class="text-end fw-bold" id="total-account-<?= $accountId ?>">
                                <?= $account['total'] ?>
                            </td>
                        </tr>
                        <!-- 詳細（子） -->
                        <?php foreach ($account['details'] as $detail): ?>
                            <tr class="collapse" id="child-<?= $accountId ?>">
                                <td class="ps-4"><?= htmlspecialchars($detail['name']) ?></td>
                                <td class="details-cell">
                                    <input type="number" step="1"
                                        class="form-control form-control-sm text-end input-value detail-input"
                                        data-parent="account-<?= $accountId ?>"
                                        data-account-id="<?= $accountId ?>"
                                        placeholder="<?= $detail['amount'] ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
            document.getElementById('laborCost').innerText = Math.round(laborCost);

            // 経費合計計算
            let expenseTotal = 0;
            document.querySelectorAll('.input-value').forEach(input => {
                expenseTotal += parseFloat(input.value) || 0;
            });
            document.getElementById('expenseTotal').innerText = Math.round(expenseTotal);

            // 総合計計算
            const grandTotal = laborCost + expenseTotal;
            document.getElementById('grandTotal').innerText = Math.round(grandTotal);

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
</body>

</html>