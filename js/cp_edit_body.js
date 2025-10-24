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

        // 最後に編集していた営業所のデータを必ずローカルに保存
        captureCurrentOfficeTime(currentOfficeId);

        // 全営業所のデータを hidden input にJSON文字列として格納
        hiddenTime.value = JSON.stringify(officeTimeDataLocal);

        // アクションを設定して送信
        document.getElementById('cpMode').value = action;
        form.submit();
    }

    // ---------------------------------------------
    // --- イベントハンドラ（モーダル内のボタンに適用） ---
    // ---------------------------------------------

    // 修正ボタン（モーダル内の「はい、修正する」）
    document.getElementById('confirmSubmit').addEventListener('click', function () {
        prepareAndSubmitForm('update');
    });

    // 確定ボタン（モーダル内の「はい、確定」）
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
        const targetId = btn.getAttribute('data-bs-target');
        const targetElement = document.querySelector(targetId);
        const iconElement = btn.querySelector('i');

        if (!targetElement || !iconElement) return;

        // 初期状態に応じてアイコン設定（optional）
        if (targetElement.classList.contains('show')) {
            iconElement.classList.remove('bi-plus-lg');
            iconElement.classList.add('bi-dash-lg');
        } else {
            iconElement.classList.remove('bi-dash-lg');
            iconElement.classList.add('bi-plus-lg');
        }

        // Collapseイベントに応じて切り替え
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
    // --- 月選択時のデータ読み込み処理（修正） ---
    // ---------------------------------------------
    monthSelect.addEventListener('change', function () {
        const year = yearSelect.value;
        const month = monthSelect.value;

        if (!year || !month) return;

        fetch(`cp_edit_load.php?year=${year}&month=${month}`)
            .then(response => response.json())
            .then(data => {
                // 1. 全営業所データをローカル変数にセット（このデータは cp_edit_load.php から取得）
                officeTimeDataLocal = data.offices || {};

                // 2. monthly_cp_id を更新
                document.getElementById('monthlyCpId').value = data.monthly_cp_id ?? '';

                // 3. 現在選択されている営業所のデータをDOMに表示
                renderOfficeToDom(officeSelect.value);

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
            });
    });

    // ---------------------------------------------
    // --- 合計再計算（最終ロジック） ---
    // ---------------------------------------------
    function updateTotals() {
        let totalHours = 0;
        let totalLaborCost = 0;

        // 1. 全営業所の時間データを集計
        // 賃率はDOMから取得されるため、現在の画面の賃率が適用される
        const hourlyRate = parseFloat(document.getElementById('hourlyRate').value) || 0;

        for (const officeId in officeTimeDataLocal) {
            if (officeTimeDataLocal.hasOwnProperty(officeId)) {
                const data = officeTimeDataLocal[officeId];

                const standard = parseFloat(data.standard_hours) || 0;
                const overtime = parseFloat(data.overtime_hours) || 0;
                const transferred = parseFloat(data.transferred_hours) || 0;

                // 全営業所の総時間に加算
                totalHours += (standard + overtime + transferred);
            }
        }

        // 労務費の計算: 総時間 × 賃率
        totalLaborCost = Math.round(totalHours * hourlyRate);

        // 2. 総時間と労務費の結果をDOMに反映
        document.getElementById('totalHours').textContent = totalHours.toFixed(2) + ' 時間';
        document.getElementById('laborCost').textContent = totalLaborCost.toLocaleString();

        // 3. 経費合計と勘定科目ごとの集計（表示中の営業所のみ）
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

        // 各勘定科目の合計欄を更新
        for (const [accountId, sum] of Object.entries(accountTotals)) {
            const target = document.getElementById(`total-account-${accountId}`);
            if (target) {
                target.textContent = sum.toLocaleString();
                const hidden = target.querySelector('input[type="hidden"]');
                if (hidden) hidden.value = sum;
            }
        }

        // 4. 総合計の計算: 経費合計 + 労務費
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
                // 賃率入力の場合
                if (input === hourlyRateInput) {
                    const v = hourlyRateInput.value;
                    const rate = (v === '' ? '' : parseFloat(v));

                    // 全国共通賃率として、全営業所の hourly_rate を更新
                    for (const oid in officeTimeDataLocal) {
                        if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
                        officeTimeDataLocal[oid].hourly_rate = rate;
                    }
                }

                // 現在の営業所の全データをローカル変数に保存
                captureCurrentOfficeTime(currentOfficeId);

                // 合計を更新
                updateTotals();
            });
        });

    // ---------------------------------------------
    // --- 初期表示処理 ---
    // ---------------------------------------------
    renderOfficeToDom(currentOfficeId);
});