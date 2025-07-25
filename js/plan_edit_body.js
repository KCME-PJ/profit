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

// 入力変更時の集計更新
document.addEventListener('input', function (event) {
    if (event.target.matches('.detail-input')) {
        updateTotals();
    }
    if (event.target.matches('#standardHours, #overtimeHours, #transferredHours, #hourlyRate')) {
        updateGrandTotal();
    }
});
