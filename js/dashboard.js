document.addEventListener('DOMContentLoaded', function () {
    // --- グローバル変数 ---
    const mainCtx = document.getElementById('mainChart').getContext('2d');
    const subCtx = document.getElementById('subChart').getContext('2d');
    const revenueSubCtx = document.getElementById('revenueSubChart').getContext('2d');
    // 左上のグラフ用 Canvas (IDは同じ)
    const expenseBreakdownCtx = document.getElementById('expenseBreakdownChart').getContext('2d');


    let mainChart;
    let subChart;
    let revenueSubChart;
    let expenseBreakdownChart; // expense'Breakdown'Chart のまま使用

    const yearSelect = document.getElementById('year-select');
    const officeSelect = document.getElementById('office-select');
    const updateButton = document.getElementById('update-button');
    const loadingOverlay = document.getElementById('loading-overlay');
    const periodSelect = document.getElementById('period-type');

    // --- チャートの初期化 (メイン: 差引収益用) ---
    function initMainChart() {
        if (mainChart) mainChart.destroy();
        mainChart = new Chart(mainCtx, {
            type: 'bar', // 複合グラフのデフォルト
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('ja-JP', { notation: 'compact' }).format(value)
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${context.parsed.y.toLocaleString()} 円`
                        }
                    }
                }
            }
        });
    }

    // 経費推移グラフの初期化 (積み上げ棒+折れ線)
    function initExpenseTrendChart() {
        if (expenseBreakdownChart) expenseBreakdownChart.destroy();
        expenseBreakdownChart = new Chart(expenseBreakdownCtx, {
            type: 'bar', // 複合グラフのデフォルト
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // 積み上げグラフ用の設定
                scales: {
                    x: {
                        stacked: true, // X軸を積み上げ
                    },
                    y: {
                        stacked: true, // Y軸を積み上げ
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('ja-JP', { notation: 'compact' }).format(value)
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${context.parsed.y.toLocaleString()} 円`
                        }
                    }
                }
            }
        });
    }


    // --- チャートの初期化 (サブチャート: ドーナツ汎用) ---
    function initSubChart(ctx, label) {
        const colors = [
            '#0d6efd', '#6c757d', '#dc3545', '#ffc107',
            '#198754', '#6f42c1', '#fd7e14', '#20c997'
        ];

        return new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    label: label,
                    data: [],
                    backgroundColor: colors,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.label}: ${context.parsed.toLocaleString()} 円`
                        }
                    }
                }
            }
        });
    }

    // --- グラフ更新 ---
    async function updateDashboard() {
        const year = yearSelect.value;
        const baseType = document.getElementById('base-data').value;
        const compareType = document.getElementById('compare-data').value;
        const officeId = officeSelect.value;
        const periodType = periodSelect.value;

        if (!year) return;

        loadingOverlay.classList.remove('d-none');
        loadingOverlay.classList.add('d-flex');

        try {
            const response = await fetch(`./statistics/get_statistics.php?year=${year}&base=${baseType}&compare=${compareType}&office=${officeId}&period=${periodType}`);

            if (!response.ok) {
                let errorMsg = `サーバーエラー: ${response.status} ${response.statusText}`;
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.error || errorMsg;
                } catch (e) {
                    const textError = await response.text();
                    console.error("Non-JSON Error Response:", textError);
                    throw new Error(errorMsg + "\n" + textError.substring(0, 200));
                }
                throw new Error(errorMsg);
            }

            const responseText = await response.text();
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error("JSON Parse Error:", e.message);
                console.error("Received Text:", responseText);
                throw new Error(`JSONの解析に失敗しました: ${e.message}. \n受信データ: ${responseText.substring(0, 200)}...`);
            }


            if (data.error) {
                throw new Error(data.error);
            }

            // 1. KPI
            updateKpiCards(data.kpi, data.filters);

            // 2. 左下: 差引収益 推移
            updateMainChart(data.mainChart, data.filters);

            // 3. 左上: 経費 推移
            updateExpenseTrendChart(data.expenseTrendChart, data.filters);


            // 4. 右上: 勘定科目別 経費 (ドーナツ)
            const expenseTitle = `勘定科目別 経費合計 (${data.filters.officeName} / 通期)`;
            updateSubChart(subChart, 'sub-chart-title', data.expenseSubChart, expenseTitle, '経費合計');

            // 5. 右下: 収入項目別 (ドーナツ)
            const revenueTitle = `収入項目別 合計 (${data.filters.officeName} / 通期)`;
            updateSubChart(revenueSubChart, 'revenue-sub-chart-title', data.revenueSubChart, revenueTitle, '収入合計');

        } catch (error) {
            console.error('ダッシュボードの更新に失敗しました:', error, error.stack);
            alert('データの取得に失敗しました: ' + error.message);
        } finally {
            loadingOverlay.classList.remove('d-flex');
            loadingOverlay.classList.add('d-none');
        }
    }

    // --- DOM更新関数 (KPI) ---
    function updateKpiCards(kpiData, filters) {
        const baseDataSelect = document.getElementById('base-data');
        const selectedOption = baseDataSelect.options[baseDataSelect.selectedIndex];
        const baseText = selectedOption.text.split('(')[0].trim();
        const kpiTitle = `通期${baseText}`;

        document.getElementById('kpi-title-1').textContent = `差引収益（${kpiTitle}）`;
        document.getElementById('kpi-gross-profit').textContent = `${kpiData.grossProfit.value.toLocaleString()} 円`;
        updateDiffText('kpi-gross-profit-diff', kpiData.grossProfit.diff, filters.compareName);

        document.getElementById('kpi-title-revenue').textContent = `収入合計（${kpiTitle}）`;
        document.getElementById('kpi-revenue-total').textContent = `${kpiData.revenueTotal.value.toLocaleString()} 円`;
        updateDiffText('kpi-revenue-total-diff', kpiData.revenueTotal.diff, filters.compareName);

        // kpi.expenseTotal は「労務費以外の経費」
        document.getElementById('kpi-title-2').textContent = `経費合計（${kpiTitle}）`;
        document.getElementById('kpi-expense-total').textContent = `${kpiData.expenseTotal.value.toLocaleString()} 円`;
        updateDiffText('kpi-expense-total-diff', kpiData.expenseTotal.diff, filters.compareName);

        document.getElementById('kpi-title-3').textContent = `労務費合計（${kpiTitle}）`;
        document.getElementById('kpi-labor-cost').textContent = `${kpiData.laborCost.value.toLocaleString()} 円`;
        updateDiffText('kpi-labor-cost-diff', kpiData.laborCost.diff, filters.compareName);

        document.getElementById('kpi-title-4').textContent = `総時間（${kpiTitle}）`;
        document.getElementById('kpi-total-hours').textContent = `${kpiData.totalHours.value.toLocaleString()} 時間`;
        updateDiffText('kpi-total-hours-diff', kpiData.totalHours.diff, filters.compareName);
    }

    // --- 差分表示 ---
    function updateDiffText(elementId, diff, compareName) {
        const baseText = (compareName !== 'none') ? `vs ${compareName}` : '';
        const el = document.getElementById(elementId);
        el.classList.remove('text-success', 'text-danger', 'text-muted');

        if (diff === null || compareName === 'none') {
            el.textContent = (compareName !== 'none') ? `${baseText} (-)` : '';
            el.classList.add('text-muted');
            return;
        }
        const percent = (diff * 100).toFixed(1);
        if (diff > 0) {
            el.textContent = `+${percent}% (${baseText})`;
            el.classList.add('text-success');
        } else if (diff < 0) {
            el.textContent = `${percent}% (${baseText})`;
            el.classList.add('text-danger');
        } else {
            el.textContent = `0.0% (${baseText})`;
            el.classList.add('text-muted');
        }
    }

    // --- DOM更新関数 (Main Chart: 差引収益) ---
    function updateMainChart(chartData, filters) {
        document.getElementById('main-chart-title').textContent = `差引収益 推移 (${filters.officeName} / ${filters.periodName})`;
        mainChart.data.labels = chartData.labels;

        mainChart.data.datasets = [
            {
                type: 'bar',
                label: `差引収益 (${chartData.baseLabel})`,
                data: chartData.baseData,
                backgroundColor: 'rgba(13, 110, 253, 0.7)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 1,
                order: 2
            }
        ];

        if (document.getElementById('compare-data').value !== 'none' && chartData.compareData) {
            mainChart.data.datasets.push({
                type: 'line',
                label: `差引収益 (${chartData.compareLabel})`,
                data: chartData.compareData,
                borderColor: 'rgba(220, 53, 69, 1)',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: false,
                tension: 0.1,
                order: 1
            });
        }
        mainChart.update();
    }

    // 経費推移グラフの更新関数
    function updateExpenseTrendChart(chartData, filters) {
        document.getElementById('expense-breakdown-chart-title').textContent = `経費 推移 (${filters.officeName} / ${filters.periodName})`;
        expenseBreakdownChart.data.labels = chartData.labels;

        expenseBreakdownChart.data.datasets = [
            {
                type: 'bar',
                label: `労務費 (${chartData.baseLabel})`,
                data: chartData.baseLaborData,
                backgroundColor: 'rgba(255, 193, 7, 0.7)', // Yellow
                borderColor: 'rgba(255, 193, 7, 1)',
                borderWidth: 1,
                stack: 'base' // 積み上げグループ 'base'
            },
            {
                type: 'bar',
                label: `その他経費 (${chartData.baseLabel})`,
                data: chartData.baseExpenseData,
                backgroundColor: 'rgba(108, 117, 125, 0.7)', // Gray
                borderColor: 'rgba(108, 117, 125, 1)',
                borderWidth: 1,
                stack: 'base' // 積み上げグループ 'base'
            }
        ];

        // 比較データ（折れ線グラフ）
        if (document.getElementById('compare-data').value !== 'none' && chartData.compareTotalData) {
            expenseBreakdownChart.data.datasets.push({
                type: 'line',
                label: `総経費 (${chartData.compareLabel})`,
                data: chartData.compareTotalData,
                borderColor: 'rgba(220, 53, 69, 1)', // Red
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: false, // 見やすさのため面グラフ(fill:true)にしない
                tension: 0.1
            });
        }
        expenseBreakdownChart.update();
    }


    // --- DOM更新関数 (Sub Chart: ドーナツ) ---
    function updateSubChart(chartInstance, titleElementId, chartData, titleText, dataTypeLabel) {
        if (!chartInstance || !chartData) {
            if (titleElementId) {
                document.getElementById(titleElementId).textContent = titleText || 'データなし';
            }
            if (chartInstance) {
                chartInstance.data.labels = [];
                chartInstance.data.datasets[0].data = [];
                chartInstance.update();
            }
            return;
        }

        document.getElementById(titleElementId).textContent = titleText;
        chartInstance.data.labels = chartData.labels;
        chartInstance.data.datasets[0].data = chartData.data;
        chartInstance.data.datasets[0].label = dataTypeLabel;
        chartInstance.update();
    }


    // --- フィルター読み込み ---
    async function loadFilterOptions() {
        try {
            const response = await fetch('./statistics/get_filter_data.php');
            const data = await response.json();

            if (data.error) throw new Error(data.error);

            yearSelect.innerHTML = '';
            if (data.years && data.years.length > 0) {
                data.years.forEach(year => {
                    const option = document.createElement('option');
                    option.value = year;
                    option.textContent = `${year}年度`;
                    yearSelect.appendChild(option);
                });
                // 2024年度をデフォルト選択
                const currentFiscalYear = "2024";
                if (data.years.includes(currentFiscalYear)) {
                    yearSelect.value = currentFiscalYear;
                }
            } else {
                yearSelect.innerHTML = '<option value="">データなし</option>';
                yearSelect.disabled = true;
            }

            officeSelect.innerHTML = '<option value="all">全社合計</option>';
            if (data.offices) {
                data.offices.forEach(office => {
                    const option = document.createElement('option');
                    option.value = office.id;
                    option.textContent = office.name;
                    officeSelect.appendChild(option);
                });
            }

            updateDashboard();

        } catch (error) {
            console.error('フィルターデータの読み込みに失敗しました:', error);
            alert('初期データの読み込みに失敗しました。');
        }
    }

    // --- 初期化 ---
    initMainChart(); // 左下: 差引収益
    // 左上のグラフ初期化
    initExpenseTrendChart(); // 左上: 経費推移

    // ドーナツグラフの初期化
    subChart = initSubChart(subCtx, '経費合計'); // 右上
    revenueSubChart = initSubChart(revenueSubCtx, '収入合計'); // 右下


    // --- イベントリスナー ---
    updateButton.addEventListener('click', updateDashboard);

    // ページロード
    loadFilterOptions();
});