document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    // --- 新規追加/修正要素 ---
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
    let currentOfficeId = officeSelect.value;

    // 1. 初期ロード（PHPから渡された全営業所のJSONをパース）
    try {
        officeTimeDataLocal = JSON.parse(hiddenTime.value || "{}");
    } catch (e) {
        console.error('初期データのパースに失敗:', e);
    }

    // 2. 現在の DOM の値をローカル変数に保存（入力や営業所切り替え時に実行）
    function captureCurrentOfficeTime(oid) {
        if (!oid) return;
        const data = officeTimeDataLocal[oid] || {};

        // 時間・人数のキャプチャ
        for (const key in timeFields) {
            const input = timeFields[key];
            data[key] = input.value === '' ? '' : (key.includes('hours') ? parseFloat(input.value) || 0 : parseInt(input.value) || 0);
        }
        // 賃率のキャプチャ（全国共通値がDOMにあるため、ここで保存）
        data.hourly_rate = hourlyRateInput.value === '' ? '' : parseFloat(hourlyRateInput.value) || 0;

        // ★ 修正: CPでは賃率は営業所ごとに持つが、JSロジック上は共通賃率として全営業所に反映
        const currentRate = data.hourly_rate;
        for (const id in officeTimeDataLocal) {
            if (!officeTimeDataLocal[id]) officeTimeDataLocal[id] = {};
            officeTimeDataLocal[id].hourly_rate = currentRate;
        }

        officeTimeDataLocal[oid] = data;
    }

    // 3. ローカル変数の値を DOM に復元して表示（営業所切り替え時や初期表示時に実行）
    function renderOfficeToDom(oid) {
        const data = officeTimeDataLocal[oid] || {};

        // 時間・人数の表示
        for (const key in timeFields) {
            const val = data[key];
            timeFields[key].value = (val !== undefined && val !== null && val !== '') ? val : '';
        }

        // 賃率の表示 (全国共通のため、現在の営業所の値をDOMに反映)
        const rateVal = data.hourly_rate;
        hourlyRateInput.value = (rateVal !== undefined && rateVal !== null && rateVal !== '') ? rateVal : '';

        // 合計計算を実行
        updateTotals();
    }

    // 4. フォーム送信前処理（修正・確定ボタンクリック時に実行）
    function prepareAndSubmitForm(action) {
        if (!monthSelect.value) {
            alert('年度と月を選択してください。');
            return;
        }
        captureCurrentOfficeTime(currentOfficeId);
        hiddenTime.value = JSON.stringify(officeTimeDataLocal);
        document.getElementById('cpMode').value = action;
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

        // 勘定科目明細 (金額) をクリア
        document.querySelectorAll('.detail-input').forEach(input => {
            input.value = '';
        });

        // ローカルデータもリセット
        officeTimeDataLocal = {};

        // IDをリセット
        const idInput = document.getElementById('monthlyCpId');
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

    document.getElementById('cpFixConfirmBtn').addEventListener('click', function () {
        prepareAndSubmitForm('fixed');
    });

    // ---------------------------------------------
    // --- 営業所切り替え処理 ---
    // ---------------------------------------------
    officeSelect.addEventListener('change', () => {
        captureCurrentOfficeTime(currentOfficeId); // 変更前の営業所データを保存
        currentOfficeId = officeSelect.value; // 現在の営業所IDを更新
        renderOfficeToDom(currentOfficeId); // 新しい営業所データを表示
    });

    // ---------------------------------------------
    // --- アイコン切り替え（既存ロジック） ---
    // ---------------------------------------------
    document.querySelectorAll('.toggle-icon').forEach(function (btn) {
        // (変更なし)
        const targetId = btn.getAttribute('data-bs-target');
        const targetElement = document.querySelector(targetId);
        const iconElement = btn.querySelector('i');

        if (!targetElement || !iconElement) return;

        if (targetElement.classList.contains('show')) {
            iconElement.classList.remove('bi-plus-lg');
            iconElement.classList.add('bi-dash-lg');
        } else {
            iconElement.classList.remove('bi-dash-lg');
            iconElement.classList.add('bi-plus-lg');
        }

        targetElement.addEventListener('shown.bs.collapse', function () {
            iconElement.classList.remove('bi-plus-lg');
            iconElement.classList.add('bi-dash-lg');
        });

        targetElement.addEventListener('hidden.bs.collapse', function () {
            iconElement.classList.remove('bi-dash-lg');
            iconElement.classList.add('bi-plus-lg');
        });
    });

    // ---------------------------------------------
    // --- 月選択時のデータ読み込み処理 ---
    // ---------------------------------------------
    monthSelect.addEventListener('change', function () {
        const year = yearSelect.value;
        const month = monthSelect.value;

        if (!year || !month) return;

        // データロードの直前に、まずフォームをリセットする
        resetInputFields();
        // 営業所セレクタの現在値を再取得（リセット後に必要）
        currentOfficeId = officeSelect ? officeSelect.value : null;

        fetch(`cp_edit_load.php?year=${year}&month=${month}`)
            .then(response => response.json())
            .then(data => {
                // 1. 全営業所データをローカル変数にセット（このデータは cp_edit_load.php から取得）
                officeTimeDataLocal = data.offices || {};

                // 2. monthly_cp_id を更新
                document.getElementById('monthlyCpId').value = data.monthly_cp_id ?? '';

                // 3. 現在選択されている営業所のデータをDOMに表示 (currentOfficeId を使用)
                renderOfficeToDom(currentOfficeId);

                // 4. 各明細の金額
                if (data.details) {
                    for (const [detailId, amount] of Object.entries(data.details)) {
                        const input = document.querySelector(`input[data-detail-id="${detailId}"]`);
                        if (input) {
                            input.value = amount;
                        }
                    }
                }
                // 合計など再計算
                updateTotals();
            })
            .catch(error => {
                console.error('データ読み込みエラー:', error);
                alert('データの読み込みに失敗しました。');
                resetInputFields(); // エラー時もリセット
            });
    });

    // ---------------------------------------------
    // --- 合計再計算（最終ロジック） ---
    // ---------------------------------------------
    function updateTotals() {
        let totalHours = 0;
        let totalLaborCost = 0;

        const hourlyRate = parseFloat(document.getElementById('hourlyRate').value) || 0;

        // ★ 修正: 賃率変更時に全営業所のローカルデータを更新する
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

                const standard = parseFloat(data.standard_hours) || 0;
                const overtime = parseFloat(data.overtime_hours) || 0;
                const transferred = parseFloat(data.transferred_hours) || 0;

                totalHours += (standard + overtime + transferred);
            }
        }

        totalLaborCost = Math.round(totalHours * hourlyRate);

        document.getElementById('totalHours').textContent = totalHours.toFixed(2) + ' 時間';
        document.getElementById('laborCost').textContent = totalLaborCost.toLocaleString();

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

        for (const [accountId, sum] of Object.entries(accountTotals)) {
            const target = document.getElementById(`total-account-${accountId}`);
            if (target) {
                target.textContent = sum.toLocaleString();
                const hidden = target.querySelector('input[type="hidden"]');
                if (hidden) hidden.value = sum;
            }
        }

        const grandTotal = expenseTotal + totalLaborCost;
        document.getElementById('expenseTotal').textContent = expenseTotal.toLocaleString();
        document.getElementById('grandTotal').textContent = grandTotal.toLocaleString();
    }

    // ---------------------------------------------
    // --- 入力変更時の更新処理 ---
    // ---------------------------------------------
    document.querySelectorAll('.detail-input, #standardHours, #overtimeHours, #transferredHours, #hourlyRate, #fulltimeCount, #contractCount, #dispatchCount')
        .forEach(input => {
            input.addEventListener('input', function () {
                // (変更なし)
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
        });

    // ---------------------------------------------
    // --- 初期表示処理 ---
    // ---------------------------------------------
    renderOfficeToDom(currentOfficeId);
});