// 年度に応じて月セレクトを更新する関数
function updateMonths() {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const selectedYear = yearSelect.value;

    monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
    monthSelect.disabled = true;

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

// サーバーからデータを読み込む関数
function loadForecastData() {
    const year = document.getElementById('yearSelect').value;
    const month = document.getElementById('monthSelect').value;

    if (!year || !month) {
        alert('月を選択してください。');
        return;
    }

    fetch(`forecast_edit_load.php?year=${year}&month=${month}`)
        .then(response => response.json())
        .then(data => {
            console.log("サーバーからのレスポンス:", data);

            // フォームにデータをセット
            document.getElementById('forecastId').value = data.forecast_id || 0;
            document.getElementById('standardHours').value = data.standard_hours || 0;
            document.getElementById('overtimeHours').value = data.overtime_hours || 0;
            document.getElementById('transferredHours').value = data.transferred_hours || 0;
            document.getElementById('hourlyRate').value = data.hourly_rate || 0;

            const details = data.details || {};
            for (const detailId in details) {
                const input = document.querySelector(`input[name="amounts[${detailId}]"]`);
                if (input) {
                    input.value = details[detailId];
                }
            }

            updateTotals();
            updateGrandTotal();
        })
        .catch(error => {
            console.error('データ取得エラー:', error);
        });
}

// 各勘定科目の小計と経費合計を更新
function updateTotals() {
    const accountTotals = {};
    let totalExpense = 0;

    document.querySelectorAll('.detail-input').forEach(input => {
        const value = parseFloat(input.value) || 0;
        const accountId = input.getAttribute('data-account-id');

        if (!accountTotals[accountId]) {
            accountTotals[accountId] = 0;
        }
        accountTotals[accountId] += value;
        totalExpense += value;
    });

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

    document.getElementById('expenseTotal').textContent = totalExpense.toLocaleString();
    updateGrandTotal();
}

// 総時間・労務費・総合計の更新
function updateGrandTotal() {
    const standardHours = parseFloat(document.getElementById('standardHours').value) || 0;
    const overtimeHours = parseFloat(document.getElementById('overtimeHours').value) || 0;
    const transferredHours = parseFloat(document.getElementById('transferredHours').value) || 0;
    const hourlyRate = parseFloat(document.getElementById('hourlyRate').value) || 0;

    const totalHours = standardHours + overtimeHours + transferredHours;
    const laborCost = Math.round(totalHours * hourlyRate);
    const expenseTotal = parseFloat(document.getElementById('expenseTotal').textContent.replace(/,/g, '')) || 0;
    const grandTotal = laborCost + expenseTotal;

    document.getElementById('totalHours').textContent = totalHours.toFixed(2) + " 時間";
    document.getElementById('laborCost').textContent = laborCost.toLocaleString();
    document.getElementById('grandTotal').textContent = grandTotal.toLocaleString();
}

// イベントバインド
document.addEventListener('DOMContentLoaded', function () {
    // 年月選択のイベント
    document.getElementById('yearSelect').addEventListener('change', () => {
        updateMonths();
        loadForecastData();
    });

    document.getElementById('monthSelect').addEventListener('change', loadForecastData);

    // 入力変更時の集計更新
    document.addEventListener('input', function (event) {
        if (event.target.matches('.detail-input')) {
            updateTotals();
        }
        if (event.target.matches('#standardHours, #overtimeHours, #transferredHours, #hourlyRate')) {
            updateGrandTotal();
        }
    });
});
