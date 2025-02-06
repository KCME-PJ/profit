function updateMonths() {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const selectedYear = yearSelect.value;

    // 月セレクトボックスを初期化
    monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
    monthSelect.disabled = true;

    // 選択された年度の対応月を表示
    if (selectedYear && yearMonthData[selectedYear]) {
        yearMonthData[selectedYear].forEach(month => {
            const option = document.createElement('option');
            option.value = month;
            option.textContent = `${month}月`;
            monthSelect.appendChild(option);
        });
        monthSelect.disabled = false;
    }
}

function loadData() {
    const year = document.getElementById('yearSelect').value;
    const month = document.getElementById('monthSelect').value;

    if (!year || !month) {
        alert('月を選択してください。');
        return;
    }

    fetch(`cp_edit_load.php?year=${year}&month=${month}`)
        .then(response => response.json())
        .then(data => {
            console.log("サーバーからのレスポンス:", data);

            // フォームフィールドにデータを反映
            document.getElementById('standardHours').value = data.standard_hours || 0;
            document.getElementById('overtimeHours').value = data.overtime_hours || 0;
            document.getElementById('transferredHours').value = data.transferred_hours || 0;
            document.getElementById('hourlyRate').value = data.hourly_rate || 0;

            // 勘定科目詳細を反映
            const details = data.details || {};
            for (const detailId in details) {
                const input = document.querySelector(`input[name="amounts[${detailId}]"]`);
                if (input) {
                    input.value = details[detailId];
                }
            }

            // 合計を計算
            updateTotals();
            updateGrandTotal();
        })
        .catch(error => {
            console.error('データ取得エラー:', error);
        });
}

// 合計を計算する関数
function updateTotals() {
    const accountTotals = {};
    let totalExpense = 0; // 経費合計

    // 各勘定科目（詳細）の入力値を取得し、親勘定科目ごとに合計
    document.querySelectorAll('.detail-input').forEach(input => {
        const value = parseFloat(input.value) || 0;
        const accountId = input.getAttribute('data-account-id'); // 親勘定科目IDを取得

        if (!accountTotals[accountId]) {
            accountTotals[accountId] = 0;
        }
        accountTotals[accountId] += value;
        totalExpense += value; // 経費合計を計算
    });

    // 計算した合計を親勘定科目のセルに反映
    for (const accountId in accountTotals) {
        const totalCell = document.getElementById(`total-account-${accountId}`);
        if (totalCell) {
            totalCell.textContent = accountTotals[accountId].toLocaleString();
        }

        const hiddenInput = document.querySelector(`input[name="total_account[${accountId}]"]`);
        if (hiddenInput) {
            hiddenInput.value = accountTotals[accountId];
        }
    }

    // **経費合計を更新**
    document.getElementById('expenseTotal').textContent = totalExpense.toLocaleString();

    // **総合計を更新**
    updateGrandTotal();
}

// **総合計（労務費 + 経費合計）を計算する関数**
function updateGrandTotal() {
    const standardHours = parseFloat(document.getElementById('standardHours').value) || 0;
    const overtimeHours = parseFloat(document.getElementById('overtimeHours').value) || 0;
    const transferredHours = parseFloat(document.getElementById('transferredHours').value) || 0;
    const hourlyRate = parseFloat(document.getElementById('hourlyRate').value) || 0;

    const totalHours = standardHours + overtimeHours + transferredHours;
    const laborCost = Math.round(totalHours * hourlyRate);

    // 労務費の更新
    document.getElementById('totalHours').textContent = totalHours.toFixed(2) + " 時間";
    document.getElementById('laborCost').textContent = laborCost.toLocaleString();

    // 総合計の更新（労務費 + 経費合計）
    const expenseTotal = parseFloat(document.getElementById('expenseTotal').textContent.replace(/,/g, '')) || 0;
    const grandTotal = laborCost + expenseTotal;
    document.getElementById('grandTotal').textContent = grandTotal.toLocaleString();
}

// **イベントリスナー**
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('yearSelect').addEventListener('change', () => {
        updateMonths();
        loadData();
    });
    document.getElementById('monthSelect').addEventListener('change', loadData);

    // **金額変更時に経費合計 & 総合計を更新**
    document.addEventListener('input', function (event) {
        if (event.target.matches('.detail-input')) {
            updateTotals();
        }
        if (event.target.matches('#standardHours, #overtimeHours, #transferredHours, #hourlyRate')) {
            updateGrandTotal();
        }
    });
});