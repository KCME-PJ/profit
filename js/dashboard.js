document.addEventListener('DOMContentLoaded', function () {
    // --- グローバル変数 ---
    const mainCtx = document.getElementById('mainChart').getContext('2d');
    const expenseCtx = document.getElementById('expenseChart').getContext('2d');
    const revenueStackCtx = document.getElementById('revenueStackChart').getContext('2d');
    const productivityCtx = document.getElementById('productivityChart').getContext('2d');

    let mainChart, expenseChart, revenueStackChart, productivityChart;

    let currentChartData = null;
    let currentFilters = null;

    const yearSelect = document.getElementById('year-select');
    const officeSelect = document.getElementById('office-select');
    const updateButton = document.getElementById('update-button');
    const loadingOverlay = document.getElementById('loading-overlay');
    const periodSelect = document.getElementById('period-type');

    // Toggles
    const mainChartToggles = document.getElementsByName('main-chart-mode');
    const expenseChartToggles = document.getElementsByName('expense-chart-mode');
    const productivityChartToggles = document.getElementsByName('productivity-chart-mode');

    // --- チャート初期化 ---

    // 1. 左上: 収益
    function initMainChart() {
        if (mainChart) mainChart.destroy();
        mainChart = new Chart(mainCtx, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { y: { ticks: { callback: v => new Intl.NumberFormat('ja-JP', { notation: 'compact' }).format(v) } } },
                plugins: { tooltip: { callbacks: { label: c => `${c.dataset.label}: ${c.parsed.y.toLocaleString()} 円` } } }
            }
        });
    }

    // 2. 左下: 経費
    function initExpenseChart() {
        if (expenseChart) expenseChart.destroy();
        expenseChart = new Chart(expenseCtx, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { y: { ticks: { callback: v => new Intl.NumberFormat('ja-JP', { notation: 'compact' }).format(v) } } },
                plugins: { tooltip: { callbacks: { label: c => `${c.dataset.label}: ${c.parsed.y.toLocaleString()} 円` } } }
            }
        });
    }

    // 3. 右上: 収入積み上げ (Stacked Bar) + 比較線
    function initRevenueStackChart() {
        if (revenueStackChart) revenueStackChart.destroy();
        revenueStackChart = new Chart(revenueStackCtx, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        ticks: { callback: v => new Intl.NumberFormat('ja-JP', { notation: 'compact' }).format(v) }
                    }
                },
                plugins: {
                    tooltip: {

                        // 折れ線グラフ（比較対象）を詳細リストから隠す
                        filter: function (tooltipItem) {
                            return tooltipItem.dataset.type !== 'line';
                        },
                        callbacks: {
                            label: c => `${c.dataset.label}: ${c.parsed.y.toLocaleString()} 円`,



                            // フッターで「合計」と「比較対象」を並べて表示する
                            footer: (tooltipItems) => {
                                let stackTotal = 0;
                                const index = tooltipItems[0].dataIndex;
                                const chart = tooltipItems[0].chart;

                                // 積み上げ合計の計算
                                chart.data.datasets.forEach((dataset, i) => {
                                    if (dataset.type === 'bar' && chart.isDatasetVisible(i)) {
                                        stackTotal += (dataset.data[index] || 0);
                                    }
                                });

                                let footerText = '合計: ' + stackTotal.toLocaleString() + ' 円';

                                // 比較対象（折れ線）のデータを取得して表示
                                chart.data.datasets.forEach((dataset) => {
                                    if (dataset.type === 'line') {
                                        const val = dataset.data[index] || 0;

                                        // ラベルを「比較対象 (XX)」に変換する処理
                                        let displayLabel = '比較対象';
                                        // "収入合計 (CP)" から "(CP)" を抽出
                                        const match = dataset.label.match(/\(.+\)/);
                                        if (match) {
                                            displayLabel += ' ' + match[0]; // -> "比較対象 (CP)"
                                        } else {
                                            displayLabel += ' (' + dataset.label + ')';
                                        }

                                        footerText += `\n${displayLabel}: ${val.toLocaleString()} 円`;
                                    }
                                });

                                return footerText;
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                }
            }
        });
    }

    // 4. 右下: 生産性 (総時間 vs 時間当たり)
    function initProductivityChart() {
        if (productivityChart) productivityChart.destroy();
        productivityChart = new Chart(productivityCtx, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } },
                plugins: {
                    tooltip: { callbacks: { label: c => `${c.dataset.label}: ${c.parsed.y.toLocaleString()}` } }
                }
            }
        });
    }

    // --- 更新プロセス ---
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
            const res = await fetch(`./statistics/get_statistics.php?year=${year}&base=${baseType}&compare=${compareType}&office=${officeId}&period=${periodType}`);
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            currentChartData = data;
            currentFilters = data.filters;

            updateKpiCards(data.kpi, data.filters);
            renderMainChart();
            renderExpenseChart();
            renderRevenueStackChart();
            renderProductivityChart();

        } catch (e) {
            alert('データ更新失敗: ' + e.message);
        } finally {
            loadingOverlay.classList.remove('d-flex');
            loadingOverlay.classList.add('d-none');
        }
    }

    // --- KPI ---
    function updateKpiCards(kpi, filters) {
        const periodLabel = document.getElementById('period-label');
        if (periodLabel) {
            if (kpi.target_month) {
                periodLabel.textContent = `（4月～${kpi.target_month}月）`;
            } else {
                periodLabel.textContent = `（集計期間）`;
            }
        }
        const setKpi = (id, text, diff) => {
            const elVal = document.getElementById(id);
            const elDiff = document.getElementById(id + '-diff');
            if (elVal) elVal.textContent = text;
            updateDiffText(elDiff, diff, filters.compareName);
        };

        // 数値を3桁区切りにするヘルパー関数
        const fmt = (num) => Number(num).toLocaleString();

        // 各カードの更新（fmt関数を通してカンマ区切りにする）
        setKpi('kpi-revenue-total', fmt(kpi.revenueTotal.value) + ' 円', kpi.revenueTotal.diff);
        setKpi('kpi-expense-total', fmt(kpi.expenseTotal.value) + ' 円', kpi.expenseTotal.diff);
        setKpi('kpi-gross-profit', fmt(kpi.grossProfit.value) + ' 円', kpi.grossProfit.diff);

        // 総時間は小数点第2位固定（既存の設定を維持）
        setKpi('kpi-total-hours', Number(kpi.totalHours.value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' 時間', kpi.totalHours.diff);

        setKpi('kpi-hourly-profit', fmt(Math.round(kpi.hourlyProfit.value)) + ' 円', kpi.hourlyProfit.diff);
        setKpi('kpi-pretax-profit', fmt(kpi.preTaxProfit.value) + ' 円', kpi.preTaxProfit.diff);
    }
    function updateDiffText(el, diff, cName) {
        if (!el) return;
        el.className = 'small text-muted mb-0';
        if (diff === null || cName === 'なし') {
            el.textContent = (cName !== 'なし') ? `vs ${cName} (-)` : '';
        } else {
            const p = (diff * 100).toFixed(1);
            el.textContent = (diff > 0 ? '+' : '') + p + `% (vs ${cName})`;
            if (diff > 0) el.classList.replace('text-muted', 'text-success');
            if (diff < 0) el.classList.replace('text-muted', 'text-danger');
        }
    }

    // --- 描画ロジック ---
    // 共通の変換マップ（ラベル定義）
    const labelMap = {
        'cp': 'CP',
        'forecast': '見通し',
        'plan': '予定',
        'outlook': '月末見込み',
        'result': '概算実績',
        'previous_year_result': '前年実績'
    };

    // 1. 左上: 収益
    function renderMainChart() {
        if (!currentChartData) return;
        const mode = document.querySelector('input[name="main-chart-mode"]:checked').value;
        const d = currentChartData.mainChart;
        const isPretax = (mode === 'pretax');
        const title = isPretax ? '税引前利益' : '差引収益';
        const baseD = isPretax ? d.baseDataPreTax : d.baseDataGross;
        const compD = isPretax ? d.compareDataPreTax : d.compareDataGross;
        // ラベル変換
        const baseLabelJP = labelMap[d.baseLabel] || d.baseLabel; // ラベルマップになければそのまま

        document.getElementById('main-chart-title').textContent = `${title} 推移 (${currentFilters.officeName})`;

        mainChart.data.labels = d.labels;
        mainChart.data.datasets = [{
            type: 'bar', label: `${title} (${baseLabelJP})`, data: baseD,
            backgroundColor: 'rgba(13, 110, 253, 0.7)', borderColor: 'rgba(13, 110, 253, 1)', borderWidth: 1, order: 2
        }];
        if (currentFilters.compareName !== 'なし' && compD) {
            mainChart.data.datasets.push({
                type: 'line', label: `${title} (${currentFilters.compareName})`, data: compD,
                borderColor: '#212529',
                borderWidth: 2,
                borderDash: [5, 5], // 破線にする設定
                backgroundColor: 'rgba(0, 0, 0, 0)', // 背景色なし
                fill: false,
                tension: 0.1,
                order: 1
            });
        }
        mainChart.update();
    }

    // 2. 左下: 経費
    function renderExpenseChart() {
        if (!currentChartData) return;
        const mode = document.querySelector('input[name="expense-chart-mode"]:checked').value;
        const d = currentChartData.expenseTrendChart;
        const isLabor = (mode === 'labor');
        const title = isLabor ? '労務費' : '経費(除労務費)';
        const baseD = isLabor ? d.baseLaborData : d.baseExpenseData;
        const compD = isLabor ? d.compareLaborData : d.compareExpenseData;
        const color = isLabor ? '73, 80, 87' : '108, 117, 125';
        // ラベル変換
        const baseLabelJP = labelMap[d.baseLabel] || d.baseLabel; // ラベルマップになければそのまま

        document.getElementById('expense-chart-title').textContent = `${title} 推移 (${currentFilters.officeName})`;

        expenseChart.data.labels = d.labels;
        expenseChart.data.datasets = [{
            type: 'bar', label: `${title} (${baseLabelJP})`, data: baseD,
            backgroundColor: `rgba(${color}, 0.7)`, borderColor: `rgba(${color}, 1)`, borderWidth: 1, order: 2
        }];
        if (currentFilters.compareName !== 'なし' && compD) {
            expenseChart.data.datasets.push({
                type: 'line', label: `${title} (${currentFilters.compareName})`, data: compD,
                borderColor: '#212529',
                borderWidth: 2,
                borderDash: [5, 5], // 破線にする設定
                backgroundColor: 'rgba(0, 0, 0, 0)', // 背景色なし
                fill: false,
                tension: 0.1,
                order: 1
            });
        }
        expenseChart.update();
    }

    // 3. 右上: 収入積み上げ
    function renderRevenueStackChart() {
        if (!currentChartData) return;
        const d = currentChartData.revenueStackChart;
        document.getElementById('revenue-stack-chart-title').textContent = `収入項目別 推移 (${currentFilters.officeName})`;

        revenueStackChart.data.labels = d.labels;

        const colorRGBs = [
            '13, 110, 253',   // 1. Primary blue (濃い青)
            '0, 153, 255',    // 2. Azure (青)
            '13, 202, 240',   // 3. Info cyan (水色)
            '0, 210, 190',    // 4. Turquoise (ターコイズ)
            '32, 201, 151',   // 5. Teal (青緑)
            '25, 135, 84',    // 6. Success green (緑)
            '116, 184, 22',   // 7. Lime green (黄緑)
            '250, 176, 5',    // 8. Yellow orange (差し色の黄)
        ];

        const datasets = d.datasets.map((ds, i) => {
            const rgb = colorRGBs[i % colorRGBs.length];
            return {
                type: 'bar',
                label: ds.label,
                data: ds.data,
                backgroundColor: `rgba(${rgb}, 0.7)`,
                borderColor: `rgba(${rgb}, 1)`,
                borderWidth: 1,
                stack: 'stack1',
                order: 2
            };
        });

        if (currentFilters.compareName !== 'なし' && d.compareTotalData) {
            datasets.push({
                type: 'line',
                label: `収入合計 (${currentFilters.compareName})`,
                data: d.compareTotalData,
                borderColor: '#212529',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                tension: 0.1,
                order: 1
            });
        }

        revenueStackChart.data.datasets = datasets;
        revenueStackChart.update();
    }

    // 4. 右下: 生産性
    function renderProductivityChart() {
        if (!currentChartData) return;
        const mode = document.querySelector('input[name="productivity-chart-mode"]:checked').value;
        const d = currentChartData.productivityChart;
        const isHourly = (mode === 'hourly');
        const title = isHourly ? '時間当たり採算' : '総時間';
        const baseD = isHourly ? d.baseHourlyData : d.baseHoursData;
        const compD = isHourly ? d.compareHourlyData : d.compareHoursData;
        const unit = isHourly ? ' 円' : ' 時間';
        // ラベル変換
        const baseLabelJP = labelMap[d.baseLabel] || d.baseLabel; // ラベルマップになければそのまま

        document.getElementById('productivity-chart-title').textContent = `${title} 推移 (${currentFilters.officeName})`;

        productivityChart.data.labels = d.labels;
        productivityChart.data.datasets = [{
            type: 'bar', label: `${title} (${baseLabelJP})`, data: baseD,
            backgroundColor: 'rgba(25, 135, 84, 0.7)', borderColor: 'rgba(25, 135, 84, 1)', borderWidth: 1, order: 2
        }];
        if (currentFilters.compareName !== 'なし' && compD) {
            productivityChart.data.datasets.push({
                type: 'line', label: `${title} (${currentFilters.compareName})`, data: compD,
                borderColor: '#212529',
                borderWidth: 2,
                borderDash: [5, 5], // 破線にする設定
                backgroundColor: 'rgba(0, 0, 0, 0)', // 背景色なし
                fill: false,
                tension: 0.1,
                order: 1
            });
        }

        // 総時間の時はツールチップでも小数点第2位まで強制表示
        productivityChart.options.plugins.tooltip.callbacks.label = (c) => {
            const formatOpts = isHourly ? {} : { minimumFractionDigits: 2, maximumFractionDigits: 2 };
            return `${c.dataset.label}: ${c.parsed.y.toLocaleString(undefined, formatOpts)}${unit}`;
        };

        productivityChart.update();
    }

    // --- 初期ロード ---
    async function loadFilterOptions() {
        try {
            const res = await fetch('./statistics/get_filter_data.php');
            const data = await res.json();

            yearSelect.innerHTML = '';
            if (data.years && data.years.length > 0) {
                data.years.forEach(y => {
                    const opt = document.createElement('option');
                    opt.value = y; opt.textContent = `${y}年度`; yearSelect.appendChild(opt);
                });
                const today = new Date();
                let ty = today.getMonth() < 3 ? today.getFullYear() - 1 : today.getFullYear();
                if (data.years.map(String).includes(String(ty))) yearSelect.value = String(ty);
                else yearSelect.value = data.years[0];
            }
            officeSelect.innerHTML = '<option value="all">全社合計</option>';
            if (data.offices) data.offices.forEach(o => {
                const opt = document.createElement('option'); opt.value = o.id; opt.textContent = o.name; officeSelect.appendChild(opt);
            });
            updateDashboard();
        } catch (e) { console.error(e); }
    }

    updateButton.addEventListener('click', updateDashboard);
    mainChartToggles.forEach(r => r.addEventListener('change', renderMainChart));
    expenseChartToggles.forEach(r => r.addEventListener('change', renderExpenseChart));
    productivityChartToggles.forEach(r => r.addEventListener('change', renderProductivityChart));

    initMainChart();
    initExpenseChart();
    initRevenueStackChart();
    initProductivityChart();
    loadFilterOptions();
});