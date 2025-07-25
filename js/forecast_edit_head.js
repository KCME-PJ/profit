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

// 月ボタンの色更新
function updateForecastStatusButtons(year) {
    fetch(`get_forecast_status.php?year=${year}`)
        .then(response => response.json())
        .then(statuses => {
            for (let month = 1; month <= 12; month++) {
                const btn = document.getElementById(`monthBtn${month}`);
                if (!btn) continue;

                const status = statuses[month] || 'none';
                btn.className = 'btn btn-sm'; // 初期化

                if (status === 'fixed') {
                    btn.classList.add('btn-success');
                } else if (status === 'draft') {
                    btn.classList.add('btn-primary');
                } else {
                    btn.classList.add('btn-secondary');
                }
            }
        })
        .catch(error => {
            console.error('ステータス取得エラー:', error);
        });
}

// サーバーからデータを読み込む関数
function loadForecastData() {
    const year = document.getElementById('yearSelect').value;
    const month = document.getElementById('monthSelect').value;

    fetch(`forecast_edit_load.php?year=${year}&month=${month}`)
        .then(response => response.json())
        .then(data => {
            console.log("サーバーからのレスポンス:", data);

            document.getElementById('forecastId').value = data.forecast_id || 0;
            document.getElementById('standardHours').value = data.standard_hours || 0;
            document.getElementById('overtimeHours').value = data.overtime_hours || 0;
            document.getElementById('transferredHours').value = data.transferred_hours || 0;
            document.getElementById('hourlyRate').value = data.hourly_rate || 0;
            document.getElementById('fulltimeCount').value = data.fulltime_count ?? 0;
            document.getElementById('contractCount').value = data.contract_count ?? 0;
            document.getElementById('dispatchCount').value = data.dispatch_count ?? 0;

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

// DOM読み込み後にイベントバインド
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('yearSelect').addEventListener('change', () => {
        const selectedYear = document.getElementById('yearSelect').value;
        updateMonths();
        updateForecastStatusButtons(selectedYear);
        loadForecastData();
    });

    document.getElementById('monthSelect').addEventListener('change', loadForecastData);

    // エクセル出力ボタンの処理
    const exportBtn = document.getElementById('excelExportBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function (e) {
            e.preventDefault();

            const year = yearSelect.value;
            const month = monthSelect.value;

            if (!year || !month) {
                alert('年度と月を選択してください。');
                return;
            }

            const url = `forecast_export_excel.php?year=${year}&month=${month}`;
            window.location.href = url;
        });
    }
});
