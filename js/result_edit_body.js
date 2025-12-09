document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');

    // --- 営業所別入力要素 ---
    const officeSelect = document.getElementById('officeSelect');
    const hiddenTime = document.getElementById('officeTimeData'); // 全営業所データ用隠しフィールド
    const hourlyRateInput = document.getElementById('hourlyRate');

    // 時間・人数などの入力フィールドを定義
    const timeFields = {
        standard_hours: document.getElementById('standardHours'),
        overtime_hours: document.getElementById('overtimeHours'),
        transferred_hours: document.getElementById('transferredHours'),
        fulltime_count: document.getElementById('fulltimeCount'),
        contract_count: document.getElementById('contractCount'),
        dispatch_count: document.getElementById('dispatchCount')
    };

    // --- ローカルデータ管理 ---
    let officeTimeDataLocal = {};
    let currentOfficeId = officeSelect ? officeSelect.value : null;

    // 初期ロード（PHPから渡された全営業所のJSONをパース）
    try {
        const initialJson = hiddenTime ? hiddenTime.value : "{}";
        officeTimeDataLocal = JSON.parse(initialJson || "{}");
    } catch (e) {
        console.error('初期データのパースに失敗:', e);
    }

    // 現在の DOM の値をローカル変数に保存（入力や営業所切り替え時に実行）
    function captureCurrentOfficeTime(oid) {
        if (!oid) return;

        const currentRate = hourlyRateInput ? parseFloat(hourlyRateInput.value) || 0 : 0;
        const data = officeTimeDataLocal[oid] || {};

        for (const key in timeFields) {
            const input = timeFields[key];
            if (input) {
                data[key] = input.value === '' ? '' : (key.includes('hours') ? parseFloat(input.value) || 0 : parseInt(input.value) || 0);
            }
        }

        for (const id in officeTimeDataLocal) {
            if (!officeTimeDataLocal[id]) officeTimeDataLocal[id] = {};
            officeTimeDataLocal[id].hourly_rate = currentRate;
        }

        // data.hourly_rate のセットを追加
        data.hourly_rate = currentRate;
        officeTimeDataLocal[oid] = data;
    }

    // ローカル変数の値を DOM に復元して表示（営業所切り替え時や初期表示時に実行）
    function renderOfficeToDom(oid) {
        if (!oid || !officeSelect) return;
        const data = officeTimeDataLocal[oid] || {};
        const rateVal = data.hourly_rate;

        if (hourlyRateInput) {
            hourlyRateInput.value = (rateVal !== undefined && rateVal !== null && rateVal !== '') ? rateVal : '';
        }

        for (const key in timeFields) {
            const val = data[key];
            if (timeFields[key]) {
                timeFields[key].value = (val !== undefined && val !== null && val !== '') ? val : '';
            }
        }

        updateTotals();
    }

    // フォーム送信前処理（確定ボタンクリック時に実行）
    function prepareAndSubmitForm(action) {
        if (!monthSelect.value) {
            alert('年度と月が選択されていません。');
            return;
        }
        captureCurrentOfficeTime(currentOfficeId);
        if (hiddenTime) {
            hiddenTime.value = JSON.stringify(officeTimeDataLocal);
        }

        document.getElementById('resultMode').value = action;
        form.submit();
    }

    // フォームリセット関数を定義
    /**
     * 時間・人数・賃率・勘定科目明細の入力欄をすべてクリア（リセット）する
     */
    function resetInputFields() {
        // 時間・人数・賃率をクリア
        if (hourlyRateInput) hourlyRateInput.value = '';
        for (const key in timeFields) {
            if (timeFields[key]) {
                timeFields[key].value = '';
            }
        }

        // 収入明細 (金額) をクリア
        document.querySelectorAll('.revenue-input').forEach(input => {
            input.value = '';
        });

        // 勘定科目明細 (金額) をクリア
        document.querySelectorAll('.detail-input').forEach(input => {
            input.value = '';
        });

        // ローカルデータもリセット
        officeTimeDataLocal = {};

        // IDをリセット
        const idInput = document.getElementById('resultId');
        if (idInput) idInput.value = '';

        // 合計値をリセット
        updateTotals();
    }


    // ---------------------------------------------
    // --- イベントハンドラ（モーダル内のボタンに適用） ---
    // ---------------------------------------------

    document.getElementById('confirmSubmit').addEventListener('click', function () {
        prepareAndSubmitForm('update');
    });

    document.getElementById('resultFixConfirmBtn').addEventListener('click', function () {
        prepareAndSubmitForm('fixed');
    });

    // ---------------------------------------------
    // --- アコーディオンの連動制御 ---
    // ---------------------------------------------
    const l1Toggles = [
        { btn: document.querySelector('[data-bs-target=".l1-revenue-group"]'), targetClass: '.l1-revenue-group' },
        { btn: document.querySelector('[data-bs-target=".l1-expense-group"]'), targetClass: '.l1-expense-group' }
    ];

    l1Toggles.forEach(l1 => {
        if (!l1.btn) return;

        const iconElementL1 = l1.btn.querySelector('i');
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

                const l2Button = l2Row.querySelector('.toggle-icon');
                if (l2Button) {
                    const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                    if (l3TargetSelector) {
                        const l3Rows = document.querySelectorAll(l3TargetSelector);
                        l3Rows.forEach(l3Row => {
                            const l3CollapseInstance = bootstrap.Collapse.getOrCreateInstance(l3Row, { toggle: false });
                            if (l3CollapseInstance) {
                                l3CollapseInstance.hide();
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
    // アコーディオンの連動制御ここまで
    // ---------------------------------------------


    // ---------------------------------------------
    // --- 月/営業所選択時のデータ読み込み処理 ---
    // ---------------------------------------------
    window.loadResultData = function (year, month) {
        if (!year || !month) return;

        // データロードの直前に、まずフォームをリセットする
        resetInputFields();
        // 営業所セレクタの現在値を再取得（リセット後に必要）
        currentOfficeId = officeSelect ? officeSelect.value : null;


        // データロード開始
        fetch(`result_edit_load.php?year=${year}&month=${month}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.error) throw new Error(data.error);

                // 全営業所データをローカル変数にセット
                officeTimeDataLocal = data.offices || {};

                // 賃率をDOMに設定（共通賃率の原則）
                const commonRate = data.common_hourly_rate ?? 0;

                // 賃率をローカルデータにも反映させる
                for (const oid in officeTimeDataLocal) {
                    if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
                    officeTimeDataLocal[oid].hourly_rate = commonRate;
                }

                // result_id を更新
                document.getElementById('resultId').value = data.result_id ?? '';

                // 現在選択されている営業所のデータをDOMに表示 (currentOfficeId を使用)
                renderOfficeToDom(currentOfficeId);

                // 各明細の金額 (経費)
                if (data.details) {
                    for (const [detailId, amount] of Object.entries(data.details)) {
                        const input = document.querySelector(`input[data-detail-id="${detailId}"]`);
                        if (input) {
                            // 0は空文字にする
                            input.value = (amount != 0) ? amount : '';
                        }
                    }
                }

                // 収入の金額
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
                resetInputFields(); // エラー時もリセット
            });
    }

    // ---------------------------------------------
    // --- 月選択時のデータ読み込み処理 ---
    // ---------------------------------------------
    monthSelect.addEventListener('change', function () {
        const year = yearSelect.value;
        const month = monthSelect.value;

        if (!year || !month) return;

        // loadResultDataを実行
        window.loadResultData(year, month);
    });

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
    // --- 合計再計算 ---
    // ---------------------------------------------
    function updateTotals() {
        let totalHours = 0;
        let totalLaborCost = 0;
        const hourlyRate = (hourlyRateInput ? parseFloat(hourlyRateInput.value) : 0) || 0;

        if (currentOfficeId) {
            captureCurrentOfficeTime(currentOfficeId);
        }

        for (const officeId in officeTimeDataLocal) {
            if (officeTimeDataLocal.hasOwnProperty(officeId)) {
                const data = officeTimeDataLocal[officeId];
                if (data) {
                    totalHours += (parseFloat(data.standard_hours) || 0) + (parseFloat(data.overtime_hours) || 0) + (parseFloat(data.transferred_hours) || 0);
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
            if (!accountTotals[accountId]) accountTotals[accountId] = 0;
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
            if (!revenueCategoryTotals[categoryId]) revenueCategoryTotals[categoryId] = 0;
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
    // (★ 合計処理ここまで)

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
                    updateTotals(); // リアルタイム計算
                });
            }
        });

    // ---------------------------------------------
    // --- URLクリーンアップ ---
    // ---------------------------------------------
    const cleanUrl = function () {
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            let shouldReplace = false;

            if (url.searchParams.has('error') || url.searchParams.has('success') || url.searchParams.has('year') || url.searchParams.has('month') || url.searchParams.has('msg')) {
                url.searchParams.delete('error');
                url.searchParams.delete('success');
                url.searchParams.delete('year');
                url.searchParams.delete('month');
                url.searchParams.delete('msg');
                shouldReplace = true;
            }

            if (shouldReplace) {
                window.history.replaceState({}, document.title, url.pathname);
            }
        }
    };

    // ページロード時（リダイレクト後）に実行
    cleanUrl();

    // アラートの「×」ボタンクリック時の動作
    document.querySelectorAll('.alert .btn-close').forEach(btn => {
        btn.addEventListener('click', () => {
            // 1. URLをクリーンにする
            cleanUrl();

            // 2. フォームの入力値をリセットする
            resetInputFields();

            // 3. 年月選択をリセットする
            if (yearSelect) {
                yearSelect.value = '';
            }
            if (monthSelect) {
                monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
                monthSelect.disabled = true;
            }
        });
    });
    // (URLクリーンアップここまで)
    // ---------------------------------------------

    // ---------------------------------------------
    // --- 初期表示処理 ---
    // ---------------------------------------------
    const urlParams = new URLSearchParams(window.location.search);
    const initialMonth = urlParams.get('month');

    if (urlParams.get('year') && initialMonth && yearSelect && monthSelect) {
        yearSelect.value = urlParams.get('year');

        if (yearSelect.dispatchEvent) {
            // yearSelect の 'change' を発火させて、monthSelect を更新
            yearSelect.dispatchEvent(new Event('change'));
        }

        setTimeout(() => {
            monthSelect.value = initialMonth;
            if (monthSelect.dispatchEvent) {
                // monthSelect の 'change' を発火させて、データをロード
                monthSelect.dispatchEvent(new Event('change'));
            }
        }, 100); // yearSelect の change 処理 (head.js) が完了するのを待つ

    } else {
        renderOfficeToDom(currentOfficeId);
    }
});