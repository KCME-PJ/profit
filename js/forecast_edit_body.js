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

    // 1. 初期ロード（PHPから渡された全営業所のJSONをパース）
    try {
        const initialJson = hiddenTime ? hiddenTime.value : "{}";
        officeTimeDataLocal = JSON.parse(initialJson || "{}");
    } catch (e) {
        console.error('初期データのパースに失敗:', e);
    }

    // 2. 現在の DOM の値をローカル変数に保存（入力や営業所切り替え時に実行）
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

        officeTimeDataLocal[oid] = data;
    }

    // 3. ローカル変数の値を DOM に復元して表示（営業所切り替え時や初期表示時に実行）
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

    // 4. フォーム送信前処理（修正・確定ボタンクリック時に実行）
    function prepareAndSubmitForm(action) {
        if (!monthSelect.value) {
            console.error('年度と月が選択されていません。');
            return;
        }
        captureCurrentOfficeTime(currentOfficeId);
        if (hiddenTime) {
            hiddenTime.value = JSON.stringify(officeTimeDataLocal);
        }
        if (form) {
            form.querySelectorAll('[name="standard_hours"], [name="overtime_hours"], [name="transferred_hours"], [name="fulltime_count"], [name="contract_count"], [name="dispatch_count"]').forEach(input => {
                input.value = 0;
            });
        }
        document.getElementById('forecastMode').value = action;
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
        const idInput = document.getElementById('monthlyForecastId');
        if (idInput) idInput.value = '';

        // 合計値をリセット
        updateTotals();
    }


    // ---------------------------------------------
    // --- イベントハンドラ（モーダル内のボタンに適用） ---
    // ---------------------------------------------

    // 修正ボタン（モーダル内の「はい、修正する」）
    document.getElementById('confirmSubmit').addEventListener('click', function () {
        prepareAndSubmitForm('update');
    });

    // 確定ボタン（モーダル内の「はい、確定」）
    document.getElementById('forecastFixConfirmBtn').addEventListener('click', function () {
        prepareAndSubmitForm('fixed');
    });

    // ---------------------------------------------
    // --- 営業所切り替え処理 ---
    // ---------------------------------------------
    if (officeSelect) {
        officeSelect.addEventListener('change', () => {
            captureCurrentOfficeTime(currentOfficeId); // 変更前の営業所データを保存
            currentOfficeId = officeSelect.value; // 現在の営業所IDを更新
            renderOfficeToDom(currentOfficeId); // 新しい営業所データを表示
        });
    }


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

        // データロード開始
        fetch(`forecast_edit_load.php?year=${year}&month=${month}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.error) throw new Error(data.error);

                // 1. 全営業所データをローカル変数にセット
                officeTimeDataLocal = data.offices || {};

                // 賃率を全営業所データに統一（共通賃率の原則）
                const commonRate = data.common_hourly_rate ?? 0;
                // document.getElementById('hourlyRate').value = commonRate; // renderOfficeToDom で行われる

                // 賃率をローカルデータにも反映させる
                for (const oid in officeTimeDataLocal) {
                    if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
                    officeTimeDataLocal[oid].hourly_rate = commonRate;
                }

                // 2. monthly_forecast_id を更新
                document.getElementById('monthlyForecastId').value = data.monthly_forecast_id ?? '';

                // 3. 現在選択されている営業所のデータをDOMに表示
                renderOfficeToDom(currentOfficeId);

                // 4. 各明細の金額 (リセット済みのため、新規セットのみ)
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

                // URLクリーンアップの実行
                cleanUrlParams();
            })
            .catch(error => {
                console.error('データ読み込みエラー:', error);
                resetInputFields(); // エラー時もリセット
            });
    });

    // ---------------------------------------------
    // --- 合計再計算（全営業所対応ロジック） ---
    // ---------------------------------------------
    function updateTotals() {
        let totalHours = 0;
        let totalLaborCost = 0;

        // 1. 全営業所の時間データを集計 (賃率はDOMから取得される共通値)
        const hourlyRate = hourlyRateInput ? (parseFloat(hourlyRateInput.value) || 0) : 0;

        // 最後に編集した営業所のデータをローカル変数に反映（DOMの内容を確実にキャプチャ）
        if (currentOfficeId) {
            captureCurrentOfficeTime(currentOfficeId);
        }

        for (const officeId in officeTimeDataLocal) {
            if (officeTimeDataLocal.hasOwnProperty(officeId)) {
                const data = officeTimeDataLocal[officeId];

                // データが空でないか確認
                if (data && (data.standard_hours || data.overtime_hours || data.transferred_hours)) {
                    // 文字列として格納されている可能性があるため、parseFloatで数値化
                    const standard = parseFloat(data.standard_hours) || 0;
                    const overtime = parseFloat(data.overtime_hours) || 0;
                    const transferred = parseFloat(data.transferred_hours) || 0;

                    // 全営業所の総時間に加算
                    totalHours += (standard + overtime + transferred);
                }
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
    // --- URL クリーンアップ関数 ---
    // ---------------------------------------------
    function cleanUrlParams() {
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            let shouldReplace = false;

            // success, error パラメータを削除
            if (url.searchParams.has('success') || url.searchParams.has('error')) {
                url.searchParams.delete('success');
                url.searchParams.delete('error');
                shouldReplace = true;
            }

            // year, month パラメータを削除し、完全にクリーンな状態にする
            if (url.searchParams.has('year') || url.searchParams.has('month')) {
                url.searchParams.delete('year');
                url.searchParams.delete('month');
                shouldReplace = true;
            }

            if (shouldReplace) {
                // URLをパスのみに書き換え
                window.history.replaceState({}, document.title, url.pathname);
            }
        }
    }


    // ---------------------------------------------
    // --- 入力変更時の更新処理 ---
    // ---------------------------------------------
    document.querySelectorAll('.detail-input, #standardHours, #overtimeHours, #transferredHours, #hourlyRate, #fulltimeCount, #contractCount, #dispatchCount')
        .forEach(input => {
            input.addEventListener('input', function () {
                // DOMの値をローカル変数に保存し、合計を更新
                // 賃率の更新は captureCurrentOfficeTime 内で全営業所に対して行われる
                captureCurrentOfficeTime(currentOfficeId);
                updateTotals();
            });
        });

    // ---------------------------------------------
    // --- 初期表示処理 ---
    // ---------------------------------------------
    const urlParams = new URLSearchParams(window.location.search);
    const initialMonth = urlParams.get('month');

    // URLパラメータから値を取得し、ドロップダウンに設定
    if (urlParams.get('year') && initialMonth) {
        yearSelect.value = urlParams.get('year');
        monthSelect.value = initialMonth;
        monthSelect.dispatchEvent(new Event('change'));
    } else {
        // 月が未選択の場合は、初期表示を行う
        renderOfficeToDom(currentOfficeId);
    }
});