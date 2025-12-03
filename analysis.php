<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集計詳細分析</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }

        .table-hover tbody tr:hover {
            background-color: #e9ecef;
            cursor: pointer;
        }

        .table-responsive {
            max-height: 70vh;
            overflow-y: auto;
        }

        .table thead th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            z-index: 1;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .table td {
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .text-end {
            text-align: right;
        }

        .table-active-custom {
            background-color: #cfe2ff !important;
        }

        .diff-plus {
            color: #198754;
        }

        .diff-minus {
            color: #dc3545;
        }

        .checkbox-list-container {
            height: 120px;
            overflow-y: auto;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.5rem;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-primary p-0" data-bs-theme="dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="./index.html">採算表</a>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link active" href="./analysis.php">詳細集計</a></li>
                    <li class="nav-item"><a class="nav-link" href="./index.html">ダッシュボードへ戻る</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid p-4">

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-3">
                <form id="filter-form" class="row g-3 align-items-start">

                    <div class="col-md-5">
                        <label class="form-label small fw-bold mb-1">対象年月度 (クリックで複数選択可)</label>
                        <div id="month-list-container" class="checkbox-list-container">
                            <div class="text-center text-muted small mt-4">読み込み中...</div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label for="office-select" class="form-label small fw-bold mb-1">営業所</label>
                        <select id="office-select" class="form-select form-select-sm">
                            <option value="all">全社合計</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="office-select" class="form-label small fw-bold mb-1">&nbsp;</label>
                        <button type="button" id="btn-update" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-table me-1"></i> 集計実行
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3">

            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-columns me-2"></i>集計サマリ</h6>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-bordered table-hover mb-0" id="summary-table">
                            <thead>
                                <tr>
                                    <th>科目</th>
                                    <th class="text-end">CP</th>
                                    <th class="text-end">予定</th>
                                    <th class="text-end">実績</th>
                                    <th class="text-end">予定差<br><span style="font-size:0.7em">(予-実)</span></th>
                                    <th class="text-end">予定比</th>
                                    <th class="text-end">CP差<br><span style="font-size:0.7em">(CP-実)</span></th>
                                    <th class="text-end">CP比</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">対象年月を選択して「集計実行」を押してください</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-check me-2"></i>詳細内訳</h6>
                        <span id="detail-title" class="badge bg-light text-dark border">科目を選択してください</span>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-bordered table-striped mb-0" id="detail-table">
                            <thead>
                                <tr>
                                    <th>詳細項目</th>
                                    <th>対象月</th>
                                    <th class="text-end">CP</th>
                                    <th class="text-end">予定</th>
                                    <th class="text-end">実績</th>
                                    <th class="text-end">予定差</th>
                                    <th class="text-end">予定比</th>
                                    <th class="text-end">CP差</th>
                                    <th class="text-end">CP比</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">左の表から行をクリックして詳細を表示</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div id="loading-overlay" class="position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 d-none justify-content-center align-items-center" style="z-index: 2000;">
        <div class="spinner-border text-light" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
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
    </script>
</body>

</html>