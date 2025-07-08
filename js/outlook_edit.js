document.addEventListener('DOMContentLoaded', function () {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const standardHoursInput = document.getElementById('standardHours');
    const overtimeHoursInput = document.getElementById('overtimeHours');
    const transferredHoursInput = document.getElementById('transferredHours');
    const hourlyRateInput = document.getElementById('hourlyRate');
    const totalHoursSpan = document.getElementById('totalHours');
    const laborCostSpan = document.getElementById('laborCost');
    const expenseTotalSpan = document.getElementById('expenseTotal');
    const grandTotalSpan = document.getElementById('grandTotal');

    const detailInputs = document.querySelectorAll('.detail-input');

    // 年が選ばれたときの月セレクト更新
    yearSelect.addEventListener('change', function () {
        const selectedYear = this.value;
        const months = yearMonthData[selectedYear] || [];
        monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
        months.forEach(function (month) {
            const option = document.createElement('option');
            option.value = month;
            option.textContent = month + '月';
            monthSelect.appendChild(option);
        });
        monthSelect.disabled = false;
    });

    // 月選択時にデータを取得してフォームに反映
    monthSelect.addEventListener('change', function () {
        const year = yearSelect.value;
        const month = this.value;

        fetch(`outlook_edit_load.php?year=${year}&month=${month}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('outlookId').value = data.outlook_id;
                standardHoursInput.value = data.standard_hours;
                overtimeHoursInput.value = data.overtime_hours;
                transferredHoursInput.value = data.transferred_hours;
                hourlyRateInput.value = data.hourly_rate;

                // 明細金額
                detailInputs.forEach(function (input) {
                    const detailId = input.getAttribute('data-detail-id');
                    input.value = data.details[detailId] ?? '';
                });

                calculateAndDisplay();
            })
            .catch(error => {
                alert("読み込みエラー: " + error.message);
            });
    });

    // 各入力欄で値が変わったときの再計算
    [standardHoursInput, overtimeHoursInput, transferredHoursInput, hourlyRateInput, ...detailInputs].forEach(function (el) {
        el.addEventListener('input', calculateAndDisplay);
    });

    function calculateAndDisplay() {
        const standard = parseFloat(standardHoursInput.value) || 0;
        const overtime = parseFloat(overtimeHoursInput.value) || 0;
        const transferred = parseFloat(transferredHoursInput.value) || 0;
        const rate = parseFloat(hourlyRateInput.value) || 0;

        const totalHours = standard + overtime + transferred;
        const laborCost = Math.round(totalHours * rate);

        let expenseTotal = 0;
        const accountTotals = {};

        detailInputs.forEach(function (input) {
            const val = parseFloat(input.value) || 0;
            const accountId = input.getAttribute('data-account-id');

            accountTotals[accountId] = (accountTotals[accountId] || 0) + val;
            expenseTotal += val;
        });

        totalHoursSpan.textContent = totalHours.toFixed(2) + " 時間";
        laborCostSpan.textContent = laborCost.toLocaleString();
        expenseTotalSpan.textContent = expenseTotal.toLocaleString();
        grandTotalSpan.textContent = (laborCost + expenseTotal).toLocaleString();

        // 各勘定科目の合計を表示
        for (const accountId in accountTotals) {
            const amount = accountTotals[accountId];
            const cell = document.getElementById(`total-account-${accountId}`);
            const hidden = document.querySelector(`input[name='total_account[${accountId}]']`);
            if (cell) cell.textContent = amount.toLocaleString();
            if (hidden) hidden.value = amount;
        }
    }

    // 確定モーダルで hidden フィールド切り替え
    const fixBtn = document.getElementById('outlookFixConfirmBtn');
    if (fixBtn) {
        fixBtn.addEventListener('click', function () {
            document.getElementById('outlookMode').value = 'fixed';
            document.getElementById('mainForm').submit();
        });
    }
});
