document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');

    // 修正ボタン
    document.getElementById('confirmSubmit').addEventListener('click', function () {
        if (!monthSelect.value) {
            alert('月を選択してください。');
            return;
        }
        document.getElementById('cpMode').value = 'update';
        form.submit();
    });

    // 確定ボタン
    document.getElementById('cpFixConfirmBtn').addEventListener('click', function () {
        if (!monthSelect.value) {
            alert('月を選択してください。');
            return;
        }
        document.getElementById('cpMode').value = 'fixed';
        form.submit();
    });

    // アイコン切り替え（＋／−）
    document.querySelectorAll('.toggle-icon').forEach(function (btn) {
        const targetId = btn.getAttribute('data-bs-target');
        const targetElement = document.querySelector(targetId);
        const iconElement = btn.querySelector('i');

        if (!targetElement || !iconElement) return;

        // 初期状態に応じてアイコン設定（optional）
        if (targetElement.classList.contains('show')) {
            iconElement.classList.remove('bi-plus-lg');
            iconElement.classList.add('bi-dash-lg');
        } else {
            iconElement.classList.remove('bi-dash-lg');
            iconElement.classList.add('bi-plus-lg');
        }

        // Collapseイベントに応じて切り替え
        targetElement.addEventListener('shown.bs.collapse', function () {
            iconElement.classList.remove('bi-plus-lg');
            iconElement.classList.add('bi-dash-lg');
        });

        targetElement.addEventListener('hidden.bs.collapse', function () {
            iconElement.classList.remove('bi-dash-lg');
            iconElement.classList.add('bi-plus-lg');
        });
    });

    // 月が選ばれたらデータ読み込み
    monthSelect.addEventListener('change', function () {
        const year = yearSelect.value;
        const month = monthSelect.value;

        if (!year || !month) return;

        fetch(`cp_edit_load.php?year=${year}&month=${month}`)
            .then(response => response.json())
            .then(data => {
                // 時間・賃率・ID
                document.getElementById('monthlyCpId').value = data.monthly_cp_id ?? '';
                document.getElementById('standardHours').value = data.standard_hours ?? 0;
                document.getElementById('overtimeHours').value = data.overtime_hours ?? 0;
                document.getElementById('transferredHours').value = data.transferred_hours ?? 0;
                document.getElementById('hourlyRate').value = data.hourly_rate ?? 0;
                document.getElementById('fulltimeCount').value = data.fulltime_count ?? 0;
                document.getElementById('contractCount').value = data.contract_count ?? 0;
                document.getElementById('dispatchCount').value = data.dispatch_count ?? 0;

                // 各明細の金額
                if (data.details) {
                    for (const [detailId, amount] of Object.entries(data.details)) {
                        const input = document.querySelector(`input[data-detail-id="${detailId}"]`);
                        if (input) {
                            input.value = amount;
                        }
                    }
                }

                // 合計など再計算
                updateTotals();
            })
            .catch(error => {
                console.error('データ読み込みエラー:', error);
                alert('データの読み込みに失敗しました。');
            });
    });

    // 合計再計算
    function updateTotals() {
        let totalHours = 0;
        let laborCost = 0;

        const standard = parseFloat(document.getElementById('standardHours').value) || 0;
        const overtime = parseFloat(document.getElementById('overtimeHours').value) || 0;
        const transferred = parseFloat(document.getElementById('transferredHours').value) || 0;
        const hourlyRate = parseFloat(document.getElementById('hourlyRate').value) || 0;

        totalHours = standard + overtime + transferred;
        laborCost = Math.round(totalHours * hourlyRate);

        document.getElementById('totalHours').textContent = totalHours.toFixed(2) + ' 時間';
        document.getElementById('laborCost').textContent = laborCost.toLocaleString();

        // 経費合計と勘定科目ごとの集計
        let expenseTotal = 0;
        const accountTotals = {};

        document.querySelectorAll('.detail-input').forEach(input => {
            const val = parseFloat(input.value) || 0;
            const accountId = input.dataset.accountId;

            expenseTotal += val;

            if (!accountTotals[accountId]) {
                accountTotals[accountId] = 0;
            }
            accountTotals[accountId] += val;
        });

        // 各勘定科目の合計欄を更新
        for (const [accountId, sum] of Object.entries(accountTotals)) {
            const target = document.getElementById(`total-account-${accountId}`);
            if (target) {
                target.textContent = sum.toLocaleString();
                const hidden = target.querySelector('input[type="hidden"]');
                if (hidden) hidden.value = sum;
            }
        }

        const grandTotal = expenseTotal + laborCost;
        document.getElementById('expenseTotal').textContent = expenseTotal.toLocaleString();
        document.getElementById('grandTotal').textContent = grandTotal.toLocaleString();
    }

    // 入力変更時に合計を更新
    document.querySelectorAll('.detail-input, #standardHours, #overtimeHours, #transferredHours, #hourlyRate')
        .forEach(input => {
            input.addEventListener('input', updateTotals);
        });
});
