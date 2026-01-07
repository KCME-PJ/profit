document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');

    // --- 営業所別入力要素 ---
    const officeSelect = document.getElementById('officeSelect');
    const hiddenTime = document.getElementById('officeTimeData');
    const hourlyRateInput = document.getElementById('hourlyRate');

    // 総時間表示用エレメント & 賃率隠しフィールド
    const totalHoursInput = document.getElementById('totalHours');
    const hiddenHourlyRateInput = document.getElementById('hiddenHourlyRate'); // 追加

    // ボタン
    const submitBtnUpdate = document.querySelector('.register-button1'); // 修正ボタン
    const submitBtnFixed = document.querySelector('.register-button2');  // 確定ボタン

    const timeFields = {
        standard_hours: document.getElementById('standardHours'),
        overtime_hours: document.getElementById('overtimeHours'),
        transferred_hours: document.getElementById('transferredHours'),
        fulltime_count: document.getElementById('fulltimeCount'),
        contract_count: document.getElementById('contractCount'),
        dispatch_count: document.getElementById('dispatchCount')
    };

    let officeTimeDataLocal = {};
    let currentOfficeId = officeSelect ? officeSelect.value : null;
    let initialFormData = ""; // 変更検知用

    // --- ヘルパー関数: 小数点誤差を解消する (小数点第2位まで) ---
    function roundTo2(num) {
        if (num === '' || num === null || num === undefined) return '';
        const f = parseFloat(num);
        if (isNaN(f)) return '';
        // 100倍して四捨五入し、100で割ることで小数点第2位までに丸める
        return Math.round(f * 100) / 100;
    }

    // 1. 初期ロード
    try {
        officeTimeDataLocal = JSON.parse(hiddenTime.value || "{}");
    } catch (e) {
        console.error('初期データのパースに失敗:', e);
    }

    // ----------------------------------------------------------------
    // 変更検知 (Dirty Check) 用の関数
    // ----------------------------------------------------------------
    function getFormDataString() {
        if (currentOfficeId && currentOfficeId !== 'all') {
            captureCurrentOfficeTime(currentOfficeId);
        }

        const inputs = document.querySelectorAll('input, select, textarea');
        const data = {};

        // 比較から除外するID
        const ignoreIds = [
            'officeTimeData',
            'officeSelect',
            'hourlyRate',
            'hiddenHourlyRate', // 変更検知対象外
            'totalHours',       // 自動計算項目のため除外
            'standardHours',
            'overtimeHours',
            'transferred_hours',
            'fulltimeCount',
            'contractCount',
            'dispatchCount',
            'bulkJsonData', // 自動生成のため除外
            'planMode',     // actionタイプのため除外
            'plStatus'      // ステータス自体は比較対象外
        ];

        inputs.forEach(input => {
            if (ignoreIds.includes(input.id)) return;
            // hiddenフィールドのうち、手動で変更しないものは除外
            if (input.type === 'hidden' && !input.classList.contains('detail-input')) return;

            if (input.name) {
                data[input.name] = input.value;
            }
        });

        // 時間管理データ
        data['officeTimeDataLocal'] = officeTimeDataLocal;

        return JSON.stringify(data);
    }

    function updateButtonState() {
        if (!submitBtnUpdate || !submitBtnFixed) return;

        // 確定済(fixed)チェック (Plan用のID: plStatus)
        const statusInput = document.getElementById('plStatus');
        const currentStatus = statusInput ? statusInput.value : '';

        if (currentStatus === 'fixed') {
            submitBtnUpdate.disabled = true;
            submitBtnFixed.disabled = true;
            return;
        }

        const isAllSelected = (officeSelect && officeSelect.value === 'all');
        const currentFormData = getFormDataString();
        const isChanged = (initialFormData !== currentFormData);

        // 修正ボタン: 「全社」以外で、かつ「変更がある」場合のみ有効
        const canUpdate = !isAllSelected && isChanged;
        submitBtnUpdate.disabled = !canUpdate;

        // 確定ボタン: 「変更がない（保存済み）」であれば有効 (全社表示でもOK)
        const canFix = !isChanged;
        submitBtnFixed.disabled = !canFix;
    }

    function initDirtyCheck() {
        // 全営業所のデータをあらかじめ正規化（数値変換）しておく
        const floatKeys = ['standard_hours', 'overtime_hours', 'transferred_hours', 'hourly_rate'];
        const intKeys = ['fulltime_count', 'contract_count', 'dispatch_count'];

        for (const oid in officeTimeDataLocal) {
            if (!officeTimeDataLocal[oid]) continue;

            [...floatKeys, ...intKeys].forEach(key => {
                let val = officeTimeDataLocal[oid][key];

                if (val === undefined || val === null || val === '') {
                    officeTimeDataLocal[oid][key] = '';
                } else {
                    if (floatKeys.includes(key)) {
                        // 初期化時にも丸めておく
                        officeTimeDataLocal[oid][key] = roundTo2(val);
                    } else {
                        officeTimeDataLocal[oid][key] = parseInt(val) || 0;
                    }
                }
            });
        }

        if (currentOfficeId && currentOfficeId !== 'all') {
            captureCurrentOfficeTime(currentOfficeId);
        }

        // 初期値を保存
        initialFormData = getFormDataString();

        // ボタン状態を更新
        updateButtonState();
    }


    // ----------------------------------------------------------------
    // 営業所フィルタリング関数
    // ----------------------------------------------------------------
    function filterDetailsByOffice() {
        if (!officeSelect) return;

        const selectedOfficeId = officeSelect.value;
        const detailRows = document.querySelectorAll('.detail-row');

        detailRows.forEach(row => {
            const rowOfficeId = row.getAttribute('data-office-id');

            // 表示条件: 全社 or 共通 or 一致
            const isMatch = (selectedOfficeId === 'all' || rowOfficeId === 'common' || rowOfficeId === selectedOfficeId);

            if (isMatch) {
                row.style.display = '';

                // 表示されていても、「全社(all)」が選択されている場合は編集不可にする
                const shouldDisable = (selectedOfficeId === 'all');

                row.querySelectorAll('input').forEach(input => {
                    input.disabled = shouldDisable;
                });
            } else {
                row.style.display = 'none';
                // 非表示＝disabled化
                row.querySelectorAll('input').forEach(input => input.disabled = true);
            }
        });

        // 合計再計算
        updateTotals();
    }


    // ----------------------------------------------------------------
    // 時間管理データ制御
    // ----------------------------------------------------------------
    function captureCurrentOfficeTime(oid) {
        if (!oid || oid === 'all') return;
        const data = officeTimeDataLocal[oid] || {};

        for (const key in timeFields) {
            const input = timeFields[key];
            if (input) {
                if (input.value === '') {
                    data[key] = '';
                } else {
                    // 入力値を保存する際も丸めておく（計算誤差の蓄積防止）
                    let val = parseFloat(input.value) || 0;
                    if (key.includes('hours')) {
                        data[key] = roundTo2(val);
                    } else {
                        data[key] = parseInt(input.value) || 0;
                    }
                }
            }
        }

        if (hourlyRateInput) {
            if (hourlyRateInput.value === '') {
                data.hourly_rate = '';
            } else {
                data.hourly_rate = parseFloat(hourlyRateInput.value) || 0;
            }
        }

        const currentRate = data.hourly_rate;
        for (const id in officeTimeDataLocal) {
            if (!officeTimeDataLocal[id]) officeTimeDataLocal[id] = {};
            officeTimeDataLocal[id].hourly_rate = currentRate;
        }

        officeTimeDataLocal[oid] = data;
    }

    // ----------------------------------------------------------------
    // 時間管理データ描画
    // ----------------------------------------------------------------
    function renderOfficeToDom(oid) {
        if (!oid) return;

        // --- A. 全社 (all) が選択された場合 ---
        if (oid === 'all') {
            let totals = {
                standard_hours: 0,
                overtime_hours: 0,
                transferred_hours: 0,
                fulltime_count: 0,
                contract_count: 0,
                dispatch_count: 0
            };
            let commonRate = '';

            let rateFound = false;
            for (const id in officeTimeDataLocal) {
                const d = officeTimeDataLocal[id];
                if (!d) continue;

                totals.standard_hours += parseFloat(d.standard_hours) || 0;
                totals.overtime_hours += parseFloat(d.overtime_hours) || 0;
                totals.transferred_hours += parseFloat(d.transferred_hours) || 0;

                totals.fulltime_count += parseInt(d.fulltime_count) || 0;
                totals.contract_count += parseInt(d.contract_count) || 0;
                totals.dispatch_count += parseInt(d.dispatch_count) || 0;

                if (!rateFound && d.hourly_rate !== undefined && d.hourly_rate !== '') {
                    commonRate = d.hourly_rate;
                    rateFound = true;
                }
            }

            for (const key in timeFields) {
                if (timeFields[key]) {
                    // 時間系(hours)の場合は丸め処理を行う
                    if (key.includes('hours')) {
                        timeFields[key].value = roundTo2(totals[key]);
                    } else {
                        timeFields[key].value = totals[key];
                    }

                    timeFields[key].disabled = true;
                    timeFields[key].classList.add('bg-light');
                }
            }

            if (hourlyRateInput) {
                if (hourlyRateInput.value === '' && commonRate !== '') {
                    hourlyRateInput.value = commonRate;
                }
                hourlyRateInput.disabled = true;
                hourlyRateInput.classList.add('bg-light');

                // 同期
                if (hiddenHourlyRateInput) {
                    hiddenHourlyRateInput.value = hourlyRateInput.value;
                }
            }

            updateTotals();
            return;
        }

        // --- B. 個別の営業所が選択された場合 ---
        const data = officeTimeDataLocal[oid] || {};

        for (const key in timeFields) {
            const val = data[key];
            if (timeFields[key]) {
                // 値をセットする前に丸める
                const rawVal = (val !== undefined && val !== null && val !== '') ? val : '';
                // hoursが含まれるキーなら丸め処理、それ以外(人数など)はそのまま
                timeFields[key].value = key.includes('hours') ? roundTo2(rawVal) : rawVal;

                timeFields[key].disabled = false;
                timeFields[key].classList.remove('bg-light');
            }
        }

        if (hourlyRateInput) {
            const rateVal = data.hourly_rate;
            hourlyRateInput.value = (rateVal !== undefined && rateVal !== null && rateVal !== '') ? rateVal : '';
            hourlyRateInput.disabled = false;
            hourlyRateInput.classList.remove('bg-light');

            // 同期
            if (hiddenHourlyRateInput) {
                hiddenHourlyRateInput.value = hourlyRateInput.value;
            }
        }

        updateTotals();
    }


    // ----------------------------------------------------------------
    // フォーム送信・リセット
    // ----------------------------------------------------------------
    function prepareAndSubmitForm(action) {
        if (!monthSelect.value) {
            alert('年度と月を選択してください。');
            return;
        }

        captureCurrentOfficeTime(currentOfficeId);

        // 画面の賃率の値を、隠しフィールドにコピーする
        if (hourlyRateInput) {
            const hiddenRate = document.getElementById('hiddenHourlyRate');
            if (hiddenRate) {
                hiddenRate.value = hourlyRateInput.value;
            }
        }

        if (hiddenTime) {
            hiddenTime.value = JSON.stringify(officeTimeDataLocal);
        }

        // 1,000件問題対応
        const bulkData = {
            revenues: {},
            amounts: {}
        };
        document.querySelectorAll('.revenue-input').forEach(input => {
            const id = input.getAttribute('data-revenue-item-id');
            if (id) bulkData.revenues[id] = input.value;
        });
        document.querySelectorAll('.detail-input').forEach(input => {
            const id = input.getAttribute('data-detail-id');
            if (id) bulkData.amounts[id] = input.value;
        });
        const bulkInput = document.getElementById('bulkJsonData');
        if (bulkInput) {
            bulkInput.value = JSON.stringify(bulkData);
        }

        if (document.getElementById('planMode')) {
            document.getElementById('planMode').value = action;
        }
        form.submit();
    }

    function resetInputFields() {
        if (hourlyRateInput) hourlyRateInput.value = '';
        if (totalHoursInput) totalHoursInput.value = ''; // リセット
        if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = ''; // リセット

        for (const key in timeFields) {
            if (timeFields[key]) timeFields[key].value = '';
        }
        document.querySelectorAll('.revenue-input').forEach(input => input.value = '');
        document.querySelectorAll('.detail-input').forEach(input => input.value = '');
        officeTimeDataLocal = {};

        // ID: planId
        if (document.getElementById('planId')) document.getElementById('planId').value = '';

        // ステータスリセット (ID: plStatus)
        if (document.getElementById('plStatus')) document.getElementById('plStatus').value = '';

        updateTotals();
        initDirtyCheck();
    }

    // イベント: モーダルボタン
    if (document.getElementById('confirmSubmit')) {
        document.getElementById('confirmSubmit').addEventListener('click', function () {
            prepareAndSubmitForm('update');
        });
    }
    // ID: planFixConfirmBtn
    if (document.getElementById('planFixConfirmBtn')) {
        document.getElementById('planFixConfirmBtn').addEventListener('click', function () {
            prepareAndSubmitForm('fixed');
        });
    }


    // ----------------------------------------------------------------
    // 営業所切り替えイベント
    // ----------------------------------------------------------------
    if (officeSelect) {
        officeSelect.addEventListener('change', () => {
            const currentRate = hourlyRateInput ? hourlyRateInput.value : '';
            captureCurrentOfficeTime(currentOfficeId);
            currentOfficeId = officeSelect.value;
            renderOfficeToDom(currentOfficeId);
            if (currentOfficeId !== 'all' && currentRate !== '' && hourlyRateInput) {
                hourlyRateInput.value = currentRate;
                // 同期
                if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = currentRate;

                if (!officeTimeDataLocal[currentOfficeId]) {
                    officeTimeDataLocal[currentOfficeId] = {};
                }
                officeTimeDataLocal[currentOfficeId].hourly_rate = parseFloat(currentRate);
            }
            filterDetailsByOffice();
            updateButtonState();
        });
    }

    // ----------------------------------------------------------------
    // 合計計算 (表示中のものだけ計算)
    // ----------------------------------------------------------------
    function updateTotals() {
        let totalHours = 0;
        let totalLaborCost = 0;
        const hourlyRate = (hourlyRateInput ? parseFloat(hourlyRateInput.value) : 0) || 0;

        for (const oid in officeTimeDataLocal) {
            if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
            officeTimeDataLocal[oid].hourly_rate = hourlyRate;
        }

        if (currentOfficeId === 'all') {
            // 全社モード: 全データの合計
            for (const officeId in officeTimeDataLocal) {
                const data = officeTimeDataLocal[officeId];
                if (data) {
                    const standard = parseFloat(data.standard_hours) || 0;
                    const overtime = parseFloat(data.overtime_hours) || 0;
                    const transferred = parseFloat(data.transferred_hours) || 0;
                    totalHours += (standard + overtime + transferred);
                }
            }
        } else {
            // 個別モード: 現在の入力値 or データから計算
            if (currentOfficeId) {
                captureCurrentOfficeTime(currentOfficeId);
                const data = officeTimeDataLocal[currentOfficeId];
                if (data) {
                    const standard = parseFloat(data.standard_hours) || 0;
                    const overtime = parseFloat(data.overtime_hours) || 0;
                    const transferred = parseFloat(data.transferred_hours) || 0;
                    totalHours += (standard + overtime + transferred);
                }
            }
        }

        // 総時間の反映 (小数点2桁まで)
        if (totalHoursInput) {
            totalHoursInput.value = totalHours.toFixed(2);
        }

        totalLaborCost = Math.round(totalHours * hourlyRate);
        if (document.getElementById('info-labor-cost')) {
            document.getElementById('info-labor-cost').textContent = totalLaborCost.toLocaleString();
        }

        let expenseTotal = 0;
        const accountTotals = {};
        document.querySelectorAll('.detail-input').forEach(input => {
            const row = input.closest('tr');
            if (row && row.style.display === 'none') return;

            const val = parseFloat(input.value) || 0;
            const accountId = input.dataset.accountId;
            expenseTotal += val;
            if (!accountTotals[accountId]) {
                accountTotals[accountId] = 0;
            }
            accountTotals[accountId] += val;
        });

        if (document.getElementById('total-expense')) document.getElementById('total-expense').textContent = expenseTotal.toLocaleString();
        if (document.getElementById('info-expense-total')) document.getElementById('info-expense-total').textContent = expenseTotal.toLocaleString();
        for (const [accountId, sum] of Object.entries(accountTotals)) {
            const target = document.getElementById(`total-account-${accountId}`);
            if (target) {
                target.textContent = sum.toLocaleString();
                const hidden = target.querySelector('input[type="hidden"]');
                if (hidden) hidden.value = sum;
            }
        }

        let revenueTotal = 0;
        const revenueCategoryTotals = {};
        document.querySelectorAll('.revenue-input').forEach(input => {
            const row = input.closest('tr');
            if (row && row.style.display === 'none') return;

            const val = parseFloat(input.value) || 0;
            const categoryId = input.dataset.categoryId;
            revenueTotal += val;
            if (!revenueCategoryTotals[categoryId]) {
                revenueCategoryTotals[categoryId] = 0;
            }
            revenueCategoryTotals[categoryId] += val;
        });

        if (document.getElementById('total-revenue')) document.getElementById('total-revenue').textContent = revenueTotal.toLocaleString();
        if (document.getElementById('info-revenue-total')) document.getElementById('info-revenue-total').textContent = revenueTotal.toLocaleString();
        for (const [categoryId, sum] of Object.entries(revenueCategoryTotals)) {
            const target = document.getElementById(`total-revenue-category-${categoryId}`);
            if (target) target.textContent = sum.toLocaleString();
        }

        const grossProfit = revenueTotal - expenseTotal;
        if (document.getElementById('info-gross-profit')) {
            document.getElementById('info-gross-profit').textContent = grossProfit.toLocaleString();
        }
    }

    // ----------------------------------------------------------------
    // 入力イベント (合計更新 & DirtyCheck)
    // ----------------------------------------------------------------
    document.getElementById('mainForm').addEventListener('input', function (e) {
        if (e.target.matches('.detail-input, .revenue-input, #standardHours, #overtimeHours, #transferredHours, #hourlyRate, #fulltimeCount, #contractCount, #dispatchCount')) {
            if (e.target === hourlyRateInput) {
                // 入力時に隠しフィールドへ同期
                if (hiddenHourlyRateInput) {
                    hiddenHourlyRateInput.value = hourlyRateInput.value;
                }

                const v = hourlyRateInput.value;
                const rate = (v === '' ? '' : parseFloat(v));
                for (const oid in officeTimeDataLocal) {
                    if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
                    officeTimeDataLocal[oid].hourly_rate = rate;
                }
            }
            captureCurrentOfficeTime(currentOfficeId);
            updateTotals();
        }
        updateButtonState();
    });


    // ----------------------------------------------------------------
    // データロード (月選択時)
    // ----------------------------------------------------------------
    if (monthSelect) {
        monthSelect.addEventListener('change', function () {
            const year = yearSelect.value;
            const month = monthSelect.value;
            if (!year || !month) return;

            resetInputFields();
            currentOfficeId = officeSelect ? officeSelect.value : null;

            fetch(`plan_edit_load.php?year=${year}&month=${month}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);

                    officeTimeDataLocal = data.offices || {};
                    // ID: planId
                    if (document.getElementById('planId')) {
                        document.getElementById('planId').value = data.plan_id ?? '';
                    }

                    // ID: plStatus
                    if (document.getElementById('plStatus')) {
                        document.getElementById('plStatus').value = data.status ?? '';
                    }

                    // 共通賃率をロードしてinputとhiddenに即座にセットする
                    const loadedRate = data.common_hourly_rate || '';
                    if (hourlyRateInput) {
                        hourlyRateInput.value = (loadedRate !== 0 && loadedRate !== '') ? loadedRate : '';
                    }
                    if (hiddenHourlyRateInput) {
                        hiddenHourlyRateInput.value = hourlyRateInput.value;
                    }

                    // officeTimeDataLocalが空、あるいは賃率を持っていない場合にセットしておく
                    for (const oid in officeTimeDataLocal) {
                        if (officeTimeDataLocal[oid].hourly_rate === undefined || officeTimeDataLocal[oid].hourly_rate === '') {
                            officeTimeDataLocal[oid].hourly_rate = loadedRate ? parseFloat(loadedRate) : '';
                        }
                    }

                    renderOfficeToDom(currentOfficeId);

                    if (data.details) {
                        for (const [detailId, amount] of Object.entries(data.details)) {
                            const input = document.querySelector(`input[data-detail-id="${detailId}"]`);
                            if (input) input.value = (amount != 0) ? amount : '';
                        }
                    }
                    if (data.revenues) {
                        for (const [itemId, amount] of Object.entries(data.revenues)) {
                            const input = document.querySelector(`input[data-revenue-item-id="${itemId}"]`);
                            if (input) input.value = (amount != 0) ? amount : '';
                        }
                    }

                    initDirtyCheck();
                    filterDetailsByOffice();
                })
                .catch(error => {
                    console.error('データ読み込みエラー:', error);
                    alert('データの読み込みに失敗しました。');
                    resetInputFields();
                });
        });
    }

    // アコーディオン制御 (Collapse)
    const l1Toggles = [
        { btn: document.querySelector('[data-bs-target=".l1-revenue-group"]'), targetClass: '.l1-revenue-group' },
        { btn: document.querySelector('[data-bs-target=".l1-expense-group"]'), targetClass: '.l1-expense-group' }
    ];

    l1Toggles.forEach(l1 => {
        if (!l1.btn) return;
        const iconElementL1 = l1.btn.querySelector('i');
        const l2Rows = document.querySelectorAll(l1.targetClass);

        l2Rows.forEach(l2Row => {
            l2Row.addEventListener('show.bs.collapse', () => {
                if (iconElementL1) { iconElementL1.classList.remove('bi-plus-lg'); iconElementL1.classList.add('bi-dash-lg'); }
            });
            l2Row.addEventListener('hide.bs.collapse', () => {
                setTimeout(() => {
                    const anyOpen = Array.from(l2Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                    if (!anyOpen && iconElementL1) {
                        iconElementL1.classList.remove('bi-dash-lg'); iconElementL1.classList.add('bi-plus-lg');
                    }
                }, 350);

                const l2Button = l2Row.querySelector('.toggle-icon');
                if (l2Button) {
                    const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                    if (l3TargetSelector) {
                        const l3Rows = document.querySelectorAll(l3TargetSelector);
                        l3Rows.forEach(l3Row => {
                            const instance = bootstrap.Collapse.getInstance(l3Row);
                            if (instance) instance.hide();
                        });
                        const iconElementL2 = l2Button.querySelector('i');
                        if (iconElementL2) { iconElementL2.classList.remove('bi-dash'); iconElementL2.classList.add('bi-plus'); }
                    }
                }
            });
        });
    });

    document.querySelectorAll('.toggle-icon:not([data-bs-target^="."])').forEach(function (l2Button) {
        const iconElementL2 = l2Button.querySelector('i');
        const l3TargetSelector = l2Button.getAttribute('data-bs-target');
        if (!l3TargetSelector) return;
        const l3Rows = document.querySelectorAll(l3TargetSelector);

        l3Rows.forEach(l3Row => {
            l3Row.addEventListener('show.bs.collapse', () => {
                if (iconElementL2) { iconElementL2.classList.remove('bi-plus'); iconElementL2.classList.add('bi-dash'); }
            });
            l3Row.addEventListener('hide.bs.collapse', () => {
                setTimeout(() => {
                    const anyOpen = Array.from(l3Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                    if (!anyOpen && iconElementL2) {
                        iconElementL2.classList.remove('bi-dash'); iconElementL2.classList.add('bi-plus');
                    }
                }, 350);
            });
        });
    });

    // ----------------------------------------------------------------
    // 初期表示
    // ----------------------------------------------------------------
    const urlParams = new URLSearchParams(window.location.search);
    const initialMonth = urlParams.get('month');

    if (urlParams.get('year') && initialMonth && yearSelect && monthSelect) {
        yearSelect.value = urlParams.get('year');
        if (yearSelect.dispatchEvent) yearSelect.dispatchEvent(new Event('change'));
        setTimeout(() => {
            monthSelect.value = initialMonth;
            if (monthSelect.dispatchEvent) monthSelect.dispatchEvent(new Event('change'));
        }, 100);
    } else {
        renderOfficeToDom(currentOfficeId);
        filterDetailsByOffice();
        initDirtyCheck();
    }

    document.querySelectorAll('.alert .btn-close').forEach(btn => {
        btn.addEventListener('click', () => {
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('success'); url.searchParams.delete('error');
                url.searchParams.delete('year'); url.searchParams.delete('month');
                url.searchParams.delete('msg');
                window.history.replaceState({}, document.title, url.pathname);
            }
        });
    });
});