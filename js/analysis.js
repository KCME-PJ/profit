document.addEventListener('DOMContentLoaded', async () => {
    const monthListContainer = document.getElementById('month-list-container');
    const officeSelect = document.getElementById('office-select');
    const summaryTbody = document.querySelector('#summary-table tbody');
    const detailTbody = document.querySelector('#detail-table tbody');
    const detailTitle = document.getElementById('detail-title');
    const loadingOverlay = document.getElementById('loading-overlay');

    let analysisData = null;

    // --- 初期化 ---
    await loadFilterOptions();

    // --- 集計実行 ---
    document.getElementById('btn-update').addEventListener('click', async () => {
        const selectedMonths = Array.from(monthListContainer.querySelectorAll('input[type="checkbox"]:checked'))
            .map(cb => cb.value);
        const officeId = officeSelect.value;

        if (selectedMonths.length === 0) {
            alert('対象年月度を少なくとも1つ選択してください。');
            return;
        }

        await fetchData(selectedMonths, officeId);
    });

    // --- データ取得 ---
    async function fetchData(months, officeId) {
        loadingOverlay.classList.remove('d-none');
        loadingOverlay.classList.add('d-flex');

        try {
            const params = new URLSearchParams();
            months.forEach(m => params.append('months[]', m));
            params.append('office', officeId);

            const res = await fetch(`./statistics/get_analysis_data.php?${params.toString()}`);
            if (!res.ok) throw new Error(`API Error: ${res.status}`);

            const text = await res.text();
            let json;
            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error('サーバーエラーが発生しました: ' + text.substring(0, 100));
            }

            if (json.error) throw new Error(json.error);
            analysisData = json;

            renderSummaryTable(analysisData.summary);

            // 詳細テーブルリセット
            detailTbody.innerHTML = '<tr><td colspan="9" class="text-center py-5 text-muted">左の表から行をクリックして詳細を表示</td></tr>';
            detailTitle.textContent = '科目を選択してください';

        } catch (e) {
            alert('データ取得に失敗しました: ' + e.message);
            console.error(e);
        } finally {
            loadingOverlay.classList.add('d-none');
            loadingOverlay.classList.remove('d-flex');
        }
    }

    // --- 左表：集計サマリ描画 ---
    function renderSummaryTable(data) {
        summaryTbody.innerHTML = '';

        data.forEach((row, index) => {
            const tr = document.createElement('tr');
            tr.dataset.rowId = row.id;

            tr.addEventListener('click', () => {
                document.querySelectorAll('#summary-table tr').forEach(r => r.classList.remove('table-active-custom'));
                tr.classList.add('table-active-custom');
                renderDetailTable(row.id, row.name);
            });

            tr.innerHTML = `
                        <td class="fw-medium">${row.name}</td>
                        <td class="text-end">${formatNum(row.cp)}</td>
                        <td class="text-end">${formatNum(row.plan)}</td>
                        <td class="text-end">${formatNum(row.result)}</td>
                        <td class="text-end ${getDiffClass(row.plan_diff)}">${formatNum(row.plan_diff)}</td>
                        <td class="text-end">${formatRatio(row.plan_ratio)}</td>
                        <td class="text-end ${getDiffClass(row.cp_diff)}">${formatNum(row.cp_diff)}</td>
                        <td class="text-end">${formatRatio(row.cp_ratio)}</td>
                    `;
            summaryTbody.appendChild(tr);
        });
    }

    // --- 右表：詳細内訳描画 ---
    function renderDetailTable(rowId, rowName) {
        detailTitle.textContent = `${rowName} の詳細`;
        detailTbody.innerHTML = '';

        const details = analysisData.details[rowId];

        if (!details || details.length === 0) {
            detailTbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">詳細データはありません</td></tr>';
            return;
        }

        details.forEach(d => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                        <td>${d.detail_name}</td> <td>${d.month_name}</td>  <td class="text-end">${formatNum(d.cp)}</td>
                        <td class="text-end">${formatNum(d.plan)}</td>
                        <td class="text-end">${formatNum(d.result)}</td>
                        <td class="text-end ${getDiffClass(d.plan_diff)}">${formatNum(d.plan_diff)}</td>
                        <td class="text-end">${formatRatio(d.plan_ratio)}</td>
                        <td class="text-end ${getDiffClass(d.cp_diff)}">${formatNum(d.cp_diff)}</td>
                        <td class="text-end">${formatRatio(d.cp_ratio)}</td>
                    `;
            detailTbody.appendChild(tr);
        });
    }

    function formatNum(num) {
        if (num === null || num === undefined) return '-';
        return Number(num).toLocaleString();
    }

    function formatRatio(num) {
        if (num === null || num === undefined) return '-';
        return Number(num).toFixed(1) + '%';
    }

    function getDiffClass(num) {
        if (num > 0) return 'diff-plus';
        if (num < 0) return 'diff-minus';
        return '';
    }

    // --- フィルタ読み込み ---
    async function loadFilterOptions() {
        try {
            const resMonths = await fetch('./statistics/get_available_months.php');
            const dataMonths = await resMonths.json();

            monthListContainer.innerHTML = '';
            if (dataMonths.months && dataMonths.months.length > 0) {
                dataMonths.months.forEach((m, index) => {
                    const div = document.createElement('div');
                    div.className = 'form-check';

                    const input = document.createElement('input');
                    input.className = 'form-check-input';
                    input.type = 'checkbox';
                    input.value = m.value;
                    input.id = 'month-' + index;

                    const label = document.createElement('label');
                    label.className = 'form-check-label';
                    label.htmlFor = 'month-' + index;
                    label.textContent = m.text;

                    div.appendChild(input);
                    div.appendChild(label);
                    monthListContainer.appendChild(div);
                });
            } else {
                monthListContainer.innerHTML = '<div class="text-muted small text-center">データなし</div>';
            }

            const resOffice = await fetch('./statistics/get_filter_data.php');
            const dataOffice = await resOffice.json();
            if (dataOffice.offices) {
                dataOffice.offices.forEach(office => {
                    const opt = document.createElement('option');
                    opt.value = office.id;
                    opt.textContent = office.name;
                    officeSelect.appendChild(opt);
                });
            }

        } catch (e) {
            console.error('フィルタ読み込みエラー:', e);
            alert('初期設定の読み込みに失敗しました');
        }
    }
});