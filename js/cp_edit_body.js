document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');

    // --- 営業所別入力要素 ---
    const officeSelect = document.getElementById('officeSelect');
    const hiddenTime = document.getElementById('officeTimeData');
    const hourlyRateInput = document.getElementById('hourlyRate');

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

    // 1. 初期ロード
    try {
        officeTimeDataLocal = JSON.parse(hiddenTime.value || "{}");
    } catch (e) {
        console.error('初期データのパースに失敗:', e);
    }

    // 2. 現在の DOM の値をローカル変数に保存
    function captureCurrentOfficeTime(oid) {
        if (!oid) return;
        const data = officeTimeDataLocal[oid] || {};

        for (const key in timeFields) {
            const input = timeFields[key];
            if (input) {
                data[key] = input.value === '' ? '' : (key.includes('hours') ? parseFloat(input.value) || 0 : parseInt(input.value) || 0);
            }
        }

        if (hourlyRateInput) {
            data.hourly_rate = hourlyRateInput.value === '' ? '' : parseFloat(hourlyRateInput.value) || 0;
        }

        // 入力された賃率を全営業所データに同期する
        const currentRate = data.hourly_rate;
        for (const id in officeTimeDataLocal) {
            if (!officeTimeDataLocal[id]) officeTimeDataLocal[id] = {};
            officeTimeDataLocal[id].hourly_rate = currentRate;
        }

        officeTimeDataLocal[oid] = data;
    }

    // 3. ローカル変数の値を DOM に復元
    function renderOfficeToDom(oid) {
        if (!oid) return;
        const data = officeTimeDataLocal[oid] || {};

        for (const key in timeFields) {
            const val = data[key];
            if (timeFields[key]) {
                timeFields[key].value = (val !== undefined && val !== null && val !== '') ? val : '';
            }
        }

        if (hourlyRateInput) {
            const rateVal = data.hourly_rate;
            hourlyRateInput.value = (rateVal !== undefined && rateVal !== null && rateVal !== '') ? rateVal : '';
        }

        updateTotals();
    }

    // 4. フォーム送信前処理
    function prepareAndSubmitForm(action) {
        if (!monthSelect.value) {
            alert('年度と月を選択してください。');
            return;
        }
        captureCurrentOfficeTime(currentOfficeId);
        if (hiddenTime) {
            hiddenTime.value = JSON.stringify(officeTimeDataLocal);
        }
        if (document.getElementById('cpMode')) {
            document.getElementById('cpMode').value = action;
        }
        form.submit();
    }

    // フォームリセット関数
    function resetInputFields() {
        if (hourlyRateInput) hourlyRateInput.value = '';
        for (const key in timeFields) {
            if (timeFields[key]) {
                timeFields[key].value = '';
            }
        }

        // 収入明細をクリア
        document.querySelectorAll('.revenue-input').forEach(input => {
            input.value = '';
        });

        // 経費明細をクリア
        document.querySelectorAll('.detail-input').forEach(input => {
            input.value = '';
        });

        officeTimeDataLocal = {};

        const idInput = document.getElementById('monthlyCpId');
        if (idInput) idInput.value = '';

        updateTotals();
    }


    // ---------------------------------------------
    // --- イベントハンドラ（モーダル） ---
    // ---------------------------------------------
    if (document.getElementById('confirmSubmit')) {
        document.getElementById('confirmSubmit').addEventListener('click', function () {
            prepareAndSubmitForm('update');
        });
    }

    if (document.getElementById('cpFixConfirmBtn')) {
        document.getElementById('cpFixConfirmBtn').addEventListener('click', function () {
            prepareAndSubmitForm('fixed');
        });
    }

    // ---------------------------------------------
    // --- 営業所切り替え処理 ---
    // ---------------------------------------------
    if (officeSelect) {
        officeSelect.addEventListener('change', () => {
            // 切り替え前の賃率を一時保存
            const currentRate = hourlyRateInput ? hourlyRateInput.value : '';

            // 現在のデータを保存
            captureCurrentOfficeTime(currentOfficeId);

            // ID切り替え
            currentOfficeId = officeSelect.value;

            // 新しいデータを描画（ここで賃率が上書きされる可能性がある）
            renderOfficeToDom(currentOfficeId);

            // ★修正: 保存しておいた賃率を強制的に戻す
            if (currentRate !== '' && hourlyRateInput) {
                hourlyRateInput.value = currentRate;

                // 切り替え先のデータにも即座に反映（念のため）
                if (!officeTimeDataLocal[currentOfficeId]) {
                    officeTimeDataLocal[currentOfficeId] = {};
                }
                officeTimeDataLocal[currentOfficeId].hourly_rate = parseFloat(currentRate);
            }

            // 再計算して表示を更新
            updateTotals();
        });
    }

    // ---------------------------------------------
    // --- アコーディオンの連動制御 ---
    // ---------------------------------------------

    // L1 (収入の部 / 経費の部) のボタン
    const l1Toggles = [
        { btn: document.querySelector('[data-bs-target=".l1-revenue-group"]'), targetClass: '.l1-revenue-group' },
        { btn: document.querySelector('[data-bs-target=".l1-expense-group"]'), targetClass: '.l1-expense-group' }
    ];

    l1Toggles.forEach(l1 => {
        if (!l1.btn) return;

        const iconElementL1 = l1.btn.querySelector('i'); // L1 Icon (bi-plus-lg)
        const l2Rows = document.querySelectorAll(l1.targetClass);

        l2Rows.forEach(l2Row => {
            const l2CollapseInstance = bootstrap.Collapse.getOrCreateInstance(l2Row, { toggle: false });

            l2Row.addEventListener('show.bs.collapse', () => {
                if (iconElementL1) {
                    iconElementL1.classList.remove('bi-plus-lg');
                    iconElementL1.classList.add('bi-dash-lg');
                }
            });

            l2Row.addEventListener('hide.bs.collapse', () => {
                const otherL2s = Array.from(l2Rows).filter(el => el !== l2Row && el.classList.contains('show'));
                if (otherL2s.length === 0 && iconElementL1) {
                    iconElementL1.classList.remove('bi-dash-lg');
                    iconElementL1.classList.add('bi-plus-lg');
                }

                // (L3) L1(親)が閉じる時、L3(孫)も強制的に閉じる
                const l2Button = l2Row.querySelector('.toggle-icon'); // L2行の中のL2ボタン
                if (l2Button) {
                    const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                    if (l3TargetSelector) {
                        const l3Rows = document.querySelectorAll(l3TargetSelector);

                        l3Rows.forEach(l3Row => {
                            const l3CollapseInstance = bootstrap.Collapse.getOrCreateInstance(l3Row, { toggle: false });
                            if (l3CollapseInstance) {
                                l3CollapseInstance.hide(); // L3を閉じる
                            }
                        });

                        const iconElementL2 = l2Button.querySelector('i');
                        if (iconElementL2) {
                            iconElementL2.classList.remove('bi-dash');
                            iconElementL2.classList.add('bi-plus');
                        }
                    }
                }
            });
        });
    });

    // L2 (カテゴリ / 勘定科目) のボタン
    document.querySelectorAll('.toggle-icon:not([data-bs-target^="."])').forEach(function (l2Button) {

        const iconElementL2 = l2Button.querySelector('i');
        const l3TargetSelector = l2Button.getAttribute('data-bs-target');
        if (!l3TargetSelector) return;

        const l3Rows = document.querySelectorAll(l3TargetSelector);

        l3Rows.forEach(l3Row => {
            const l3CollapseInstance = bootstrap.Collapse.getOrCreateInstance(l3Row, { toggle: false });

            l3Row.addEventListener('show.bs.collapse', () => {
                if (iconElementL2) {
                    iconElementL2.classList.remove('bi-plus');
                    iconElementL2.classList.add('bi-dash');
                }
            });

            l3Row.addEventListener('hide.bs.collapse', () => {
                const otherL3s = Array.from(l3Rows).filter(el => el !== l3Row && el.classList.contains('show'));
                if (otherL3s.length === 0 && iconElementL2) {
                    iconElementL2.classList.remove('bi-dash');
                    iconElementL2.classList.add('bi-plus');
                }
            });
        });
    });


    // ---------------------------------------------
    // --- 月選択時のデータ読み込み処理 ---
    // ---------------------------------------------
    if (monthSelect) {
        monthSelect.addEventListener('change', function () {
            const year = yearSelect.value;
            const month = monthSelect.value;

            if (!year || !month) return;

            resetInputFields();
            currentOfficeId = officeSelect ? officeSelect.value : null;

            fetch(`cp_edit_load.php?year=${year}&month=${month}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);

                    // 1. 全営業所データをローカル変数にセット
                    officeTimeDataLocal = data.offices || {};

                    // 2. monthly_cp_id を更新
                    if (document.getElementById('monthlyCpId')) {
                        document.getElementById('monthlyCpId').value = data.monthly_cp_id ?? '';
                    }

                    // 3. 現在選択されている営業所のデータをDOMに表示
                    renderOfficeToDom(currentOfficeId); // この中で updateTotals() が呼ばれる

                    // 4. 各明細の金額 (経費)
                    if (data.details) {
                        for (const [detailId, amount] of Object.entries(data.details)) {
                            const input = document.querySelector(`input[data-detail-id="${detailId}"]`);
                            if (input) {
                                input.value = (amount != 0) ? amount : '';
                            }
                        }
                    }

                    // 5. 収入の金額
                    if (data.revenues) {
                        for (const [itemId, amount] of Object.entries(data.revenues)) {
                            const input = document.querySelector(`input[data-revenue-item-id="${itemId}"]`);
                            if (input) {
                                input.value = (amount != 0) ? amount : '';
                            }
                        }
                    }

                    // 合計など再計算
                    updateTotals();

                })
                .catch(error => {
                    console.error('データ読み込みエラー:', error);
                    alert('データの読み込みに失敗しました。');
                    resetInputFields();
                });
        });
    }

    // ---------------------------------------------
    // --- 合計再計算 ---
    // ---------------------------------------------
    function updateTotals() {
        let totalHours = 0;
        let totalLaborCost = 0;

        const hourlyRate = (hourlyRateInput ? parseFloat(hourlyRateInput.value) : 0) || 0;

        // 賃率変更時に全営業所のローカルデータを更新
        for (const oid in officeTimeDataLocal) {
            if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
            officeTimeDataLocal[oid].hourly_rate = hourlyRate;
        }

        // 最後に編集した営業所のデータをローカル変数に反映
        if (currentOfficeId) {
            captureCurrentOfficeTime(currentOfficeId);
        }

        for (const officeId in officeTimeDataLocal) {
            if (officeTimeDataLocal.hasOwnProperty(officeId)) {
                const data = officeTimeDataLocal[officeId];
                if (data) {
                    const standard = parseFloat(data.standard_hours) || 0;
                    const overtime = parseFloat(data.overtime_hours) || 0;
                    const transferred = parseFloat(data.transferred_hours) || 0;
                    totalHours += (standard + overtime + transferred);
                }
            }
        }

        totalLaborCost = Math.round(totalHours * hourlyRate);

        if (document.getElementById('info-labor-cost')) {
            document.getElementById('info-labor-cost').textContent = totalLaborCost.toLocaleString();
        }

        // --- 経費合計 ---
        let expenseTotal = 0;
        const accountTotals = {};
        document.querySelectorAll('.detail-input').forEach(input => {
            const val = parseFloat(input.value) || 0;
            const accountId = input.dataset.accountId;
            expenseTotal += val;
            if (!accountTotals[accountId]) {
                accountTotals[accountId] = 0;
            }
            accountTotals[accountId] += val;
        });

        if (document.getElementById('total-expense')) {
            document.getElementById('total-expense').textContent = expenseTotal.toLocaleString();
        }
        if (document.getElementById('info-expense-total')) {
            document.getElementById('info-expense-total').textContent = expenseTotal.toLocaleString();
        }
        for (const [accountId, sum] of Object.entries(accountTotals)) {
            const target = document.getElementById(`total-account-${accountId}`);
            if (target) {
                target.textContent = sum.toLocaleString();
                const hidden = target.querySelector('input[type="hidden"]');
                if (hidden) hidden.value = sum;
            }
        }

        // --- 収入合計 ---
        let revenueTotal = 0;
        const revenueCategoryTotals = {};
        document.querySelectorAll('.revenue-input').forEach(input => {
            const val = parseFloat(input.value) || 0;
            const categoryId = input.dataset.categoryId;
            revenueTotal += val;
            if (!revenueCategoryTotals[categoryId]) {
                revenueCategoryTotals[categoryId] = 0;
            }
            revenueCategoryTotals[categoryId] += val;
        });

        if (document.getElementById('total-revenue')) {
            document.getElementById('total-revenue').textContent = revenueTotal.toLocaleString();
        }
        if (document.getElementById('info-revenue-total')) {
            document.getElementById('info-revenue-total').textContent = revenueTotal.toLocaleString();
        }
        for (const [categoryId, sum] of Object.entries(revenueCategoryTotals)) {
            const target = document.getElementById(`total-revenue-category-${categoryId}`);
            if (target) {
                target.textContent = sum.toLocaleString();
            }
        }

        // --- 差引収益 (収入 - 経費) ---
        const grossProfit = revenueTotal - expenseTotal;
        if (document.getElementById('info-gross-profit')) {
            document.getElementById('info-gross-profit').textContent = grossProfit.toLocaleString();
        }
    }

    // ---------------------------------------------
    // --- 入力変更時の更新処理 ---
    // ---------------------------------------------
    document.querySelectorAll('.detail-input, .revenue-input, #standardHours, #overtimeHours, #transferredHours, #hourlyRate, #fulltimeCount, #contractCount, #dispatchCount')
        .forEach(input => {
            if (input) {
                input.addEventListener('input', function () {
                    if (input === hourlyRateInput) {
                        const v = hourlyRateInput.value;
                        const rate = (v === '' ? '' : parseFloat(v));
                        for (const oid in officeTimeDataLocal) {
                            if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
                            officeTimeDataLocal[oid].hourly_rate = rate;
                        }
                    }
                    captureCurrentOfficeTime(currentOfficeId);
                    updateTotals();
                });
            }
        });

    // ---------------------------------------------
    // --- 初期表示処理 ---
    // ---------------------------------------------
    const urlParams = new URLSearchParams(window.location.search);
    const initialMonth = urlParams.get('month');

    if (urlParams.get('year') && initialMonth && yearSelect && monthSelect) {
        yearSelect.value = urlParams.get('year');

        if (yearSelect.dispatchEvent) {
            yearSelect.dispatchEvent(new Event('change'));
        }

        // (head.js が 月の <option> を非同期で生成するのを待つ)
        setTimeout(() => {
            monthSelect.value = initialMonth;
            if (monthSelect.dispatchEvent) {
                monthSelect.dispatchEvent(new Event('change'));
            }
        }, 100); // 100ms待機

    } else {
        renderOfficeToDom(currentOfficeId);
    }

    // アラートのURLクリーンアップ
    document.querySelectorAll('.alert .btn-close').forEach(btn => {
        btn.addEventListener('click', () => {
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('success');
                url.searchParams.delete('error');
                url.searchParams.delete('year');
                url.searchParams.delete('month');
                window.history.replaceState({}, document.title, url.pathname);
            }
        });
    });
});