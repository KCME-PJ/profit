document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');

    const userRole = document.body.getAttribute('data-user-role') || 'viewer';
    const isAdmin = (userRole === 'admin');

    const officeSelect = document.getElementById('officeSelect');
    const hiddenTime = document.getElementById('officeTimeData');
    const hourlyRateInput = document.getElementById('hourlyRate');
    const totalHoursInput = document.getElementById('totalHours');
    const hiddenHourlyRateInput = document.getElementById('hiddenHourlyRate');

    const submitBtnUpdate = document.querySelector('.register-button1');
    const submitBtnFixed = document.querySelector('.register-button2');
    const btnReject = document.getElementById('btnReject');

    // Admin Controls
    const adminParentControls = document.getElementById('adminParentControls');
    const btnParentFix = document.getElementById('btnParentFix');
    const btnParentUnlock = document.getElementById('btnParentUnlock');
    const parentFixedLabel = document.getElementById('parentFixedLabel');
    const unfixedBadge = document.getElementById('unfixedBadge');
    const unfixedCountSpan = document.getElementById('unfixedCount');
    const unfixedOfficeListUl = document.getElementById('unfixedOfficeList');

    // ステータスデータ取得
    const cpStatusMap = window.cpStatusMap || {};

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
    let initialFormData = "";

    let isAllFixedGlobal = false;
    let unfixedOfficesGlobal = [];

    function roundTo2(num) {
        if (num === '' || num === null || num === undefined) return '';
        const f = parseFloat(num);
        if (isNaN(f)) return '';
        return Math.round(f * 100) / 100;
    }

    // エラー表示用の箱を安全に取得（なければ作る）する関数
    function getOrCreateErrorAlert() {
        let alertDiv = document.getElementById('errorAlert');
        if (!alertDiv) {
            // PHP側で出力されていない場合、JSでコンテナを作成
            alertDiv = document.createElement('div');
            alertDiv.id = 'errorAlert';
            // 表示位置は mainForm の直前（PHPの配置に合わせる）
            // コンテナ(container mt-2)内の mainForm を探す
            if (form && form.parentNode) {
                form.parentNode.insertBefore(alertDiv, form);
            }
        }
        return alertDiv;
    }

    try {
        officeTimeDataLocal = JSON.parse(hiddenTime.value || "{}");
    } catch (e) {
        console.error('初期データのパースに失敗:', e);
    }

    const BACKUP_KEY = 'cp_edit_backup_data';

    function backupFormData() {
        if (isAdmin) return;
        if (currentOfficeId && currentOfficeId !== 'all') {
            captureCurrentOfficeTime(currentOfficeId);
        }
        const currentData = {
            officeTimeData: officeTimeDataLocal,
            inputs: {},
            hourlyRate: hourlyRateInput ? hourlyRateInput.value : '',
            activeOfficeId: currentOfficeId
        };
        document.querySelectorAll('input, select, textarea').forEach(input => {
            const ignoreIds = ['monthlyCpId', 'cpStatus', 'updatedAt', 'bulkJsonData', 'officeTimeData', 'officeSelect', 'yearSelect', 'monthSelect', 'hourlyRate', 'hiddenHourlyRate', 'targetOfficeId'];
            const timeFieldIds = ['standardHours', 'overtimeHours', 'transferred_hours', 'fulltimeCount', 'contractCount', 'dispatchCount'];
            if (input.id && (ignoreIds.includes(input.id) || timeFieldIds.includes(input.id))) return;
            if (input.type === 'hidden' && !input.classList.contains('detail-input')) return;
            const key = input.name || input.id;
            if (key) currentData.inputs[key] = input.value;
        });
        const backupPackage = { current: currentData, initial: initialFormData };
        sessionStorage.setItem(BACKUP_KEY, JSON.stringify(backupPackage));
    }

    function restoreFormData() {
        if (isAdmin) return;
        const json = sessionStorage.getItem(BACKUP_KEY);
        if (!json) return;
        try {
            const pkg = JSON.parse(json);
            const current = pkg.current;
            const initial = pkg.initial ? JSON.parse(pkg.initial) : { officeTimeDataLocal: {}, inputs: {} };
            const initialTimeData = initial.officeTimeDataLocal || {};

            if (current.activeOfficeId && officeSelect) {
                officeSelect.value = current.activeOfficeId;
                currentOfficeId = current.activeOfficeId;
            }

            if (current.officeTimeData) {
                for (const oid in current.officeTimeData) {
                    if (!current.officeTimeData[oid]) continue;
                    if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
                    for (const key in current.officeTimeData[oid]) {
                        const valCurrent = current.officeTimeData[oid][key];
                        const valInitial = (initialTimeData[oid]) ? initialTimeData[oid][key] : undefined;
                        if (String(valCurrent) !== String(valInitial ?? '')) {
                            officeTimeDataLocal[oid][key] = valCurrent;
                        }
                    }
                }
                if (currentOfficeId) renderOfficeToDom(currentOfficeId);
            }

            if (current.hourlyRate !== undefined && hourlyRateInput) {
                if (current.hourlyRate !== '') {
                    hourlyRateInput.value = current.hourlyRate;
                    if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = current.hourlyRate;
                }
            }

            if (current.inputs) {
                for (const [key, value] of Object.entries(current.inputs)) {
                    const initialVal = initial[key];
                    if (String(value) !== String(initialVal ?? '')) {
                        let input = document.querySelector(`[name="${key}"]`);
                        if (!input) input = document.getElementById(key);
                        if (input) input.value = value;
                    }
                }
            }
            updateTotals();
            updateButtonState();
            filterDetailsByOffice();

            const errorAlert = getOrCreateErrorAlert();
            if (errorAlert) {
                const msg = document.createElement('div');
                msg.innerHTML = '<strong>※復元されたデータがあります。</strong>';
                msg.className = 'mt-2 text-dark bg-warning-subtle p-2 rounded border border-warning';
                errorAlert.appendChild(msg);
            }
        } catch (e) {
            console.error('復元エラー', e);
        }
        sessionStorage.removeItem(BACKUP_KEY);
    }

    function clearBackup() { sessionStorage.removeItem(BACKUP_KEY); }

    function getFormDataString() {
        if (currentOfficeId && currentOfficeId !== 'all') captureCurrentOfficeTime(currentOfficeId);
        const inputs = document.querySelectorAll('input, select, textarea');
        const data = {};
        const ignoreIds = ['officeTimeData', 'officeSelect', 'hourlyRate', 'hiddenHourlyRate', 'totalHours', 'standardHours', 'overtimeHours', 'transferred_hours', 'fulltimeCount', 'contractCount', 'dispatchCount', 'bulkJsonData', 'cpMode', 'cpStatus', 'updatedAt', 'targetOfficeId'];
        inputs.forEach(input => {
            if (ignoreIds.includes(input.id)) return;
            if (input.type === 'hidden' && !input.classList.contains('detail-input')) return;
            if (input.name) data[input.name] = input.value;
        });
        data['officeTimeDataLocal'] = officeTimeDataLocal;
        return JSON.stringify(data);
    }

    function updateButtonState() {
        const cpIdInput = document.getElementById('monthlyCpId');
        const hasData = (cpIdInput && cpIdInput.value);
        const statusInput = document.getElementById('cpStatus');
        const parentStatus = statusInput ? statusInput.value : '';
        const isAllSelected = (officeSelect && officeSelect.value === 'all');

        if (isAdmin) {
            document.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.type === 'hidden') return;
                if (el.id !== 'yearSelect' && el.id !== 'monthSelect' && el.id !== 'officeSelect') {
                    el.disabled = true;
                }
            });

            // 1. 親コントロール（確定・解除ボタン・バッジ）の制御
            if (adminParentControls) {
                // コンテナは常に表示する（バッジを見せるため）
                adminParentControls.style.display = 'inline-flex';

                // ボタンを表示するかどうかは「全社」選択時のみ
                const showParentBtns = isAllSelected;

                if (parentStatus === 'fixed') {
                    if (btnParentFix) btnParentFix.style.display = 'none';
                    // 解除ボタンは全社選択時のみ
                    if (btnParentUnlock) btnParentUnlock.style.display = showParentBtns ? 'inline-block' : 'none';

                    if (parentFixedLabel) parentFixedLabel.style.display = 'inline-block';
                    if (unfixedBadge) unfixedBadge.style.display = 'none';
                } else {
                    if (btnParentFix) {
                        // 確定ボタンは全社選択時のみ
                        btnParentFix.style.display = showParentBtns ? 'inline-block' : 'none';
                        btnParentFix.disabled = !isAllFixedGlobal || !hasData;
                    }
                    if (btnParentUnlock) btnParentUnlock.style.display = 'none';
                    if (parentFixedLabel) parentFixedLabel.style.display = 'none';

                    // バッジは営業所選択に関わらず、未確定があれば表示
                    if (unfixedBadge) {
                        unfixedBadge.style.display = (unfixedOfficesGlobal.length > 0) ? 'inline-block' : 'none';
                    }
                }
            }

            // 2. 差し戻しボタン（個別営業所用）の制御
            if (btnReject) {
                // 全社選択時は差し戻しボタンは非表示
                if (isAllSelected) {
                    btnReject.style.display = 'none';
                } else {
                    // 個別選択時のロジック（既存のelseブロックの中身と同じ）
                    if (hasData && parentStatus === 'fixed') {
                        btnReject.style.display = 'none';
                    } else if (hasData) {
                        let childStatus = 'draft';
                        if (officeTimeDataLocal[currentOfficeId] && officeTimeDataLocal[currentOfficeId].status) {
                            childStatus = officeTimeDataLocal[currentOfficeId].status;
                        }
                        if (childStatus === 'fixed') {
                            btnReject.style.display = 'inline-block';
                        } else {
                            btnReject.style.display = 'none';
                        }
                    } else {
                        btnReject.style.display = 'none';
                    }
                }
            }
            return;
        }
        if (!submitBtnUpdate || !submitBtnFixed) return;
        const lockedStatuses = ['fixed', 'approved', 'registered'];

        if (!hasData || lockedStatuses.includes(parentStatus)) {
            submitBtnUpdate.disabled = true;
            submitBtnFixed.disabled = true;
            return;
        }

        if (initialFormData === "") return;

        // 現在選択している営業所個別のステータスを確認
        let isChildFixed = false;
        if (currentOfficeId && officeTimeDataLocal[currentOfficeId]) {
            // ステータスが 'fixed' ならフラグを立てる
            if (officeTimeDataLocal[currentOfficeId].status === 'fixed') {
                isChildFixed = true;
            }
        }
        const currentFormData = getFormDataString();
        const isChanged = (initialFormData !== currentFormData);
        // 修正ボタン: 「変更あり」かつ「全社以外」かつ「この営業所が確定済でない」
        const canUpdate = !isAllSelected && isChanged && !isChildFixed;
        submitBtnUpdate.disabled = !canUpdate;

        // 確定ボタン: 「変更なし」かつ「全社以外」かつ「この営業所が確定済でない」
        const canFix = !isChanged && !isAllSelected && !isChildFixed;
        submitBtnFixed.disabled = !canFix;
    }

    function initDirtyCheck() {
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
                        officeTimeDataLocal[oid][key] = roundTo2(val);
                    } else {
                        officeTimeDataLocal[oid][key] = parseInt(val) || 0;
                    }
                }
            });
        }
        if (currentOfficeId && currentOfficeId !== 'all') captureCurrentOfficeTime(currentOfficeId);
        initialFormData = getFormDataString();
        updateButtonState();
    }

    function filterDetailsByOffice() {
        if (!officeSelect) return;
        const selectedOfficeId = officeSelect.value;
        const detailRows = document.querySelectorAll('.detail-row');
        detailRows.forEach(row => {
            const rowOfficeId = row.getAttribute('data-office-id');
            const isMatch = (selectedOfficeId === 'all' || rowOfficeId === 'common' || rowOfficeId === selectedOfficeId);
            if (isMatch) {
                row.style.display = '';
                const isGloballyDisabled = document.getElementById('standardHours').disabled;
                const shouldDisable = isAdmin || (selectedOfficeId === 'all') || isGloballyDisabled;

                row.querySelectorAll('input').forEach(input => {
                    if (input.type !== 'hidden') input.disabled = shouldDisable;
                });
            } else {
                row.style.display = 'none';
                row.querySelectorAll('input').forEach(input => {
                    if (input.type !== 'hidden') input.disabled = true;
                });
            }
        });
        updateTotals();
    }

    function captureCurrentOfficeTime(oid) {
        if (!oid || oid === 'all') return;
        const data = officeTimeDataLocal[oid] || {};
        for (const key in timeFields) {
            const input = timeFields[key];
            if (input) {
                if (input.value === '') {
                    data[key] = '';
                } else {
                    let val = parseFloat(input.value) || 0;
                    if (key.includes('hours')) data[key] = roundTo2(val);
                    else data[key] = parseInt(input.value) || 0;
                }
            }
        }
        if (hourlyRateInput) {
            if (hourlyRateInput.value === '') data.hourly_rate = '';
            else data.hourly_rate = parseFloat(hourlyRateInput.value) || 0;
        }
        const currentRate = data.hourly_rate;
        for (const id in officeTimeDataLocal) {
            if (!officeTimeDataLocal[id]) officeTimeDataLocal[id] = {};
            officeTimeDataLocal[id].hourly_rate = currentRate;
        }
        officeTimeDataLocal[oid] = data;
    }

    function renderOfficeToDom(oid) {
        // 現在のステータスを取得してロック判定を行う
        const statusInput = document.getElementById('cpStatus');
        const parentStatus = statusInput ? statusInput.value : '';
        const lockedStatuses = ['fixed', 'approved', 'registered'];
        // 個別の営業所のステータスを確認
        let isChildFixed = false;
        if (oid && officeTimeDataLocal[oid] && officeTimeDataLocal[oid].status === 'fixed') {
            isChildFixed = true;
        }

        // 管理者、またはステータスがロック対象(親がFixed または 子がFixed)なら入力を無効化
        const shouldDisable = isAdmin || lockedStatuses.includes(parentStatus) || isChildFixed;
        for (const key in timeFields) {
            const input = timeFields[key];
            if (input) {
                input.value = '';
                input.disabled = shouldDisable;
                // 入力不可ならグレー背景にする
                if (shouldDisable) {
                    input.classList.add('bg-light');
                } else {
                    input.classList.remove('bg-light');
                }
            }
        }
        if (hourlyRateInput) {
            hourlyRateInput.value = '';
            hourlyRateInput.disabled = shouldDisable;
            // 入力不可ならグレー背景にする
            if (shouldDisable) {
                hourlyRateInput.classList.add('bg-light');
            } else {
                hourlyRateInput.classList.remove('bg-light');
            }
            hourlyRateInput.placeholder = '0';
        }

        if (oid === 'all') {
            const totals = {
                standard_hours: 0, overtime_hours: 0, transferred_hours: 0,
                fulltime_count: 0, contract_count: 0, dispatch_count: 0
            };
            for (const id in officeTimeDataLocal) {
                if (id === 'all') continue;
                const data = officeTimeDataLocal[id];
                if (!data) continue;
                totals.standard_hours += parseFloat(data.standard_hours) || 0;
                totals.overtime_hours += parseFloat(data.overtime_hours) || 0;
                totals.transferred_hours += parseFloat(data.transferred_hours) || 0;
                totals.fulltime_count += parseInt(data.fulltime_count) || 0;
                totals.contract_count += parseInt(data.contract_count) || 0;
                totals.dispatch_count += parseInt(data.dispatch_count) || 0;
            }
            for (const key in totals) {
                const input = timeFields[key];
                if (input) {
                    if (key.includes('hours')) input.value = roundTo2(totals[key]);
                    else input.value = totals[key];
                    input.disabled = true;
                    input.classList.add('bg-light');
                }
            }
            let finalRate = '';
            if (hiddenHourlyRateInput && hiddenHourlyRateInput.value && parseFloat(hiddenHourlyRateInput.value) > 0) {
                finalRate = hiddenHourlyRateInput.value;
            }
            if (hourlyRateInput) {
                hourlyRateInput.value = finalRate;
                hourlyRateInput.disabled = true;
                hourlyRateInput.classList.add('bg-light');
            }
            return;
        }

        if (oid && officeTimeDataLocal[oid]) {
            const data = officeTimeDataLocal[oid];
            for (const key in timeFields) {
                const input = timeFields[key];
                if (input) {
                    const val = data[key] !== undefined ? data[key] : '';
                    if (key.includes('hours')) input.value = roundTo2(val);
                    else input.value = val;
                }
            }
            if (hourlyRateInput) {
                hourlyRateInput.value = data.hourly_rate !== undefined ? data.hourly_rate : '';
            }
        }

        if (isAdmin) {
            document.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.type === 'hidden') return;
                if (el.id !== 'yearSelect' && el.id !== 'monthSelect' && el.id !== 'officeSelect') {
                    el.disabled = true;
                }
            });
        }
    }

    // 入力を無効化するヘルパー関数
    function disableAllInputs() {
        document.querySelectorAll('input, select, textarea').forEach(el => {
            if (el.type === 'hidden') return;
            // 年度・月・営業所選択だけは生かす
            if (el.id !== 'yearSelect' && el.id !== 'monthSelect' && el.id !== 'officeSelect') {
                el.disabled = true;
                el.classList.add('bg-light');
            }
        });
        if (submitBtnUpdate) submitBtnUpdate.disabled = true;
        if (submitBtnFixed) submitBtnFixed.disabled = true;
        if (btnReject) btnReject.style.display = 'none';

        // フォームの内容もクリアしておく
        renderOfficeToDom(currentOfficeId);
    }

    function prepareAndSubmitForm(action) {
        if (!monthSelect.value) { alert('年度と月を選択してください。'); return; }
        backupFormData();
        captureCurrentOfficeTime(currentOfficeId);

        const targetOfficeInput = document.getElementById('targetOfficeId');
        if (targetOfficeInput && officeSelect) { targetOfficeInput.value = officeSelect.value; }

        if (hourlyRateInput && hiddenHourlyRateInput) hiddenHourlyRateInput.value = hourlyRateInput.value;
        if (hiddenTime) hiddenTime.value = JSON.stringify(officeTimeDataLocal);

        if (action === 'reject' || action === 'parent_fix' || action === 'parent_unlock') {
            if (document.getElementById('cpMode')) document.getElementById('cpMode').value = action;
            form.submit();
            return;
        }

        const bulkData = { revenues: {}, amounts: {} };
        document.querySelectorAll('.revenue-input').forEach(input => {
            if (input.disabled || input.closest('tr').style.display === 'none') return;
            const id = input.getAttribute('data-revenue-item-id');
            if (id) bulkData.revenues[id] = input.value;
        });
        document.querySelectorAll('.detail-input').forEach(input => {
            if (input.disabled || input.closest('tr').style.display === 'none') return;
            const id = input.getAttribute('data-detail-id');
            if (id) bulkData.amounts[id] = input.value;
        });

        const bulkInput = document.getElementById('bulkJsonData');
        if (bulkInput) bulkInput.value = JSON.stringify(bulkData);
        if (document.getElementById('cpMode')) document.getElementById('cpMode').value = action;
        form.submit();
    }

    function resetInputFields() {
        if (hourlyRateInput) hourlyRateInput.value = '';
        if (totalHoursInput) totalHoursInput.value = '';
        if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = '';
        for (const key in timeFields) if (timeFields[key]) timeFields[key].value = '';
        document.querySelectorAll('.revenue-input').forEach(input => input.value = '');
        document.querySelectorAll('.detail-input').forEach(input => input.value = '');
        officeTimeDataLocal = {};
        if (document.getElementById('monthlyCpId')) document.getElementById('monthlyCpId').value = '';
        if (document.getElementById('cpStatus')) document.getElementById('cpStatus').value = '';
        if (document.getElementById('updatedAt')) document.getElementById('updatedAt').value = '';

        isAllFixedGlobal = false;
        unfixedOfficesGlobal = [];
        if (unfixedCountSpan) unfixedCountSpan.textContent = '0';
        if (unfixedOfficeListUl) unfixedOfficeListUl.innerHTML = '';

        document.querySelectorAll('input, select, textarea').forEach(el => {
            if (el.type === 'hidden') return;
            if (!isAdmin) {
                el.disabled = false;
                el.classList.remove('bg-light');
            }
        });

        updateTotals();
        initialFormData = "";
    }

    function updateMonthButtons(year) {
        if (!cpStatusMap) return;
        const yearData = cpStatusMap[year] || {};

        document.querySelectorAll('.month-btn').forEach(btn => {
            const m = btn.getAttribute('data-month');
            btn.className = 'btn btn-sm me-1 mb-1 month-btn';

            if (yearData[m]) {
                const status = yearData[m];
                if (status === 'fixed') {
                    btn.classList.add('btn-success');
                } else {
                    btn.classList.add('btn-primary');
                }
            } else {
                btn.classList.add('btn-secondary');
            }
        });
    }

    // --- イベントリスナー ---
    if (document.getElementById('confirmSubmit')) document.getElementById('confirmSubmit').addEventListener('click', function () { prepareAndSubmitForm('update'); });
    if (document.getElementById('cpFixConfirmBtn')) document.getElementById('cpFixConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('fixed'); });
    if (document.getElementById('cpRejectConfirmBtn')) document.getElementById('cpRejectConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('reject'); });
    if (document.getElementById('cpParentFixConfirmBtn')) document.getElementById('cpParentFixConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('parent_fix'); });
    if (document.getElementById('cpParentUnlockConfirmBtn')) document.getElementById('cpParentUnlockConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('parent_unlock'); });

    document.querySelectorAll('.month-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!yearSelect.value) {
                alert('年度を選択してください。');
                return;
            }
            const selectedMonth = this.getAttribute('data-month');
            let optionExists = false;
            for (let i = 0; i < monthSelect.options.length; i++) {
                if (monthSelect.options[i].value == selectedMonth) {
                    optionExists = true;
                    break;
                }
            }
            if (!optionExists) {
                const opt = document.createElement('option');
                opt.value = selectedMonth;
                opt.text = selectedMonth + '月';
                monthSelect.add(opt);
            }
            monthSelect.value = selectedMonth;
            monthSelect.dispatchEvent(new Event('change'));
        });
    });

    if (officeSelect) {
        officeSelect.addEventListener('change', () => {
            if (!monthSelect.value) {
                alert("月を選択してください。");
                officeSelect.value = currentOfficeId || 'all';
                return;
            }

            if (!isAdmin) {
                if (initialFormData !== "") {
                    const currentFormData = getFormDataString();
                    const isChanged = (initialFormData !== currentFormData);
                    if (isChanged) {
                        if (!confirm("入力内容が保存されていません。\n移動しますか？")) {
                            officeSelect.value = currentOfficeId;
                            return;
                        }
                    }
                }
            }

            const currentRate = hourlyRateInput ? hourlyRateInput.value : '';
            captureCurrentOfficeTime(currentOfficeId);
            currentOfficeId = officeSelect.value;
            renderOfficeToDom(currentOfficeId);
            if (currentOfficeId !== 'all' && currentRate !== '' && hourlyRateInput) {
                if (!hourlyRateInput.value) hourlyRateInput.value = currentRate;
                if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = hourlyRateInput.value;
                if (!officeTimeDataLocal[currentOfficeId]) officeTimeDataLocal[currentOfficeId] = {};
                officeTimeDataLocal[currentOfficeId].hourly_rate = parseFloat(hourlyRateInput.value);
            }

            // エラー表示コンテナを安全に取得・クリア
            const errorAlert = getOrCreateErrorAlert();
            if (errorAlert) errorAlert.innerHTML = '';

            const myOfficeData = officeTimeDataLocal[currentOfficeId];

            const hasParentData = document.getElementById('monthlyCpId') && document.getElementById('monthlyCpId').value;

            if (!isAdmin && currentOfficeId !== 'all' && hasParentData && !myOfficeData) {
                if (errorAlert) {
                    const year = yearSelect.value;
                    const month = monthSelect.value;
                    errorAlert.innerHTML = `
                        <div class="alert alert-warning d-flex align-items-center" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <div>
                                <strong>データ未登録</strong><br>
                                この営業所の ${month}月データはまだ登録されていません。<br>
                                <a href="../cp/cp.php?year=${year}&month=${month}" class="alert-link">CP計画画面（新規登録）</a> から登録を行ってください。
                            </div>
                        </div>`;
                }
                disableAllInputs();
            } else {
                // ステータスを確認して、ロック中なら有効化しないように制御
                const statusInput = document.getElementById('cpStatus');
                const parentStatus = statusInput ? statusInput.value : '';
                const lockedStatuses = ['fixed', 'approved', 'registered'];
                const isLocked = lockedStatuses.includes(parentStatus);
                const isAllSelected = (officeSelect.value === 'all');
                const shouldDisable = isLocked || isAllSelected;
                if (!isAdmin) {
                    document.querySelectorAll('input, select, textarea').forEach(el => {
                        if (el.type === 'hidden') return;
                        // プルダウン類は除外
                        if (el.id !== 'yearSelect' && el.id !== 'monthSelect' && el.id !== 'officeSelect') {

                            // shouldDisable フラグに基づいて制御
                            el.disabled = shouldDisable;

                            if (shouldDisable) {
                                el.classList.add('bg-light');
                            } else {
                                el.classList.remove('bg-light');
                            }
                        }
                    });
                }
            }

            filterDetailsByOffice();
            updateButtonState();
            initialFormData = getFormDataString();
        });
    }

    if (yearSelect) {
        yearSelect.addEventListener('change', function () {
            updateMonthButtons(this.value);
        });
    }

    function updateTotals() {
        document.querySelectorAll('td[id^="total-account-"]').forEach(td => {
            td.textContent = '0';
            const hidden = td.querySelector('input[type="hidden"]');
            if (hidden) hidden.value = 0;
        });
        document.querySelectorAll('td[id^="total-revenue-category-"]').forEach(td => {
            td.textContent = '0';
        });

        let totalHours = 0;
        let totalLaborCost = 0;
        let calculationRate = 0;
        if (hiddenHourlyRateInput && hiddenHourlyRateInput.value) {
            calculationRate = parseFloat(hiddenHourlyRateInput.value) || 0;
        } else if (hourlyRateInput) {
            calculationRate = parseFloat(hourlyRateInput.value) || 0;
        }
        for (const oid in officeTimeDataLocal) {
            if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
            officeTimeDataLocal[oid].hourly_rate = calculationRate;
        }
        if (currentOfficeId === 'all') {
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
        if (totalHoursInput) totalHoursInput.value = totalHours.toFixed(2);
        totalLaborCost = Math.round(totalHours * calculationRate);
        if (document.getElementById('info-labor-cost')) document.getElementById('info-labor-cost').textContent = totalLaborCost.toLocaleString();

        let expenseTotal = 0;
        const accountTotals = {};
        document.querySelectorAll('.detail-input').forEach(input => {
            const row = input.closest('tr');
            if (row && row.style.display === 'none') return;

            const rowOfficeId = row.getAttribute('data-office-id');
            if (currentOfficeId && currentOfficeId !== 'all') {
                if (rowOfficeId !== 'common' && rowOfficeId !== currentOfficeId) {
                    return;
                }
            }

            const val = parseFloat(input.value) || 0;
            const accountId = input.dataset.accountId;
            expenseTotal += val;
            if (!accountTotals[accountId]) accountTotals[accountId] = 0;
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

            const rowOfficeId = row.getAttribute('data-office-id');
            if (currentOfficeId && currentOfficeId !== 'all') {
                if (rowOfficeId !== 'common' && rowOfficeId !== currentOfficeId) {
                    return;
                }
            }

            const val = parseFloat(input.value) || 0;
            const categoryId = input.dataset.categoryId;
            revenueTotal += val;
            if (!revenueCategoryTotals[categoryId]) revenueCategoryTotals[categoryId] = 0;
            revenueCategoryTotals[categoryId] += val;
        });

        if (document.getElementById('total-revenue')) document.getElementById('total-revenue').textContent = revenueTotal.toLocaleString();
        if (document.getElementById('info-revenue-total')) document.getElementById('info-revenue-total').textContent = revenueTotal.toLocaleString();
        for (const [categoryId, sum] of Object.entries(revenueCategoryTotals)) {
            const target = document.getElementById(`total-revenue-category-${categoryId}`);
            if (target) target.textContent = sum.toLocaleString();
        }

        const grossProfit = revenueTotal - expenseTotal;
        if (document.getElementById('info-gross-profit')) document.getElementById('info-gross-profit').textContent = grossProfit.toLocaleString();
    }

    document.getElementById('mainForm').addEventListener('input', function (e) {
        if (e.target.matches('.detail-input, .revenue-input, #standardHours, #overtimeHours, #transferredHours, #hourlyRate, #fulltimeCount, #contractCount, #dispatchCount')) {
            if (e.target === hourlyRateInput) {
                if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = hourlyRateInput.value;
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

    if (monthSelect) {
        monthSelect.addEventListener('change', function (e) {
            const year = yearSelect.value;
            const month = monthSelect.value;
            if (!year || !month) return;

            resetInputFields();
            currentOfficeId = officeSelect ? officeSelect.value : null;

            if (e.isTrusted) {
                const errorAlert = document.getElementById('errorAlert');
                if (errorAlert) errorAlert.innerHTML = '';
            }

            fetch(`cp_edit_load.php?year=${year}&month=${month}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);

                    const errorAlert = getOrCreateErrorAlert();
                    if (errorAlert) errorAlert.innerHTML = '';

                    // 1. 親データ（月自体）が存在しない場合
                    if (!data.monthly_cp_id) {
                        if (errorAlert) {
                            errorAlert.innerHTML = `
                                <div class="alert alert-secondary d-flex align-items-center" role="alert">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    <div><strong>この月はまだ登録されていません。</strong></div>
                                </div>`;
                        }
                        disableAllInputs();
                        return;
                    }

                    // 2. データの展開
                    officeTimeDataLocal = data.offices || {};
                    if (document.getElementById('monthlyCpId')) document.getElementById('monthlyCpId').value = data.monthly_cp_id ?? '';
                    if (document.getElementById('cpStatus')) document.getElementById('cpStatus').value = data.status ?? '';

                    isAllFixedGlobal = !!data.all_fixed;
                    unfixedOfficesGlobal = data.unfixed_offices || [];

                    if (unfixedOfficeListUl) {
                        unfixedOfficeListUl.innerHTML = '';
                        if (unfixedOfficesGlobal.length > 0) {
                            unfixedOfficesGlobal.forEach(name => {
                                const li = document.createElement('li');
                                li.className = 'list-group-item';
                                li.textContent = name;
                                unfixedOfficeListUl.appendChild(li);
                            });
                        } else {
                            unfixedOfficeListUl.innerHTML = '<li class="list-group-item text-muted">なし（全営業所確定済）</li>';
                        }
                    }
                    if (unfixedCountSpan) unfixedCountSpan.textContent = unfixedOfficesGlobal.length;

                    const updatedAtInput = document.getElementById('updatedAt');
                    if (updatedAtInput) updatedAtInput.value = data.updated_at || '';
                    else {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden'; hidden.id = 'updatedAt'; hidden.name = 'updated_at';
                        hidden.value = data.updated_at || ''; form.appendChild(hidden);
                    }

                    const apiRate = data.common_hourly_rate;
                    if (apiRate && apiRate > 0) {
                        if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = apiRate;
                        if (hourlyRateInput) hourlyRateInput.value = apiRate;
                        for (const oid in officeTimeDataLocal) {
                            officeTimeDataLocal[oid].hourly_rate = parseFloat(apiRate);
                        }
                    }

                    // 3. 親はあるが、自分の営業所のデータがない場合 (Manager用)
                    const myOfficeData = officeTimeDataLocal[currentOfficeId];
                    if (!isAdmin && currentOfficeId !== 'all' && !myOfficeData) {
                        if (errorAlert) {
                            errorAlert.innerHTML = `
                                <div class="alert alert-warning d-flex align-items-center" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <div>
                                        <strong>データ未登録</strong><br>
                                        この営業所の ${month}月データはまだ登録されていません。<br>
                                        <a href="../cp/cp.php?year=${year}&month=${month}" class="alert-link">CP計画画面（新規登録）</a> から登録を行ってください。
                                    </div>
                                </div>`;
                        }
                        disableAllInputs();
                        renderOfficeToDom(currentOfficeId);
                        return; // 処理中断
                    }

                    // データセット
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

                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('error') && !isAdmin) restoreFormData();
                    else if (urlParams.has('success')) clearBackup();
                })
                .catch(error => {
                    console.error('データ読み込みエラー:', error);
                    alert('データの読み込みに失敗しました。');
                    resetInputFields();
                });
        });
    }

    if (window.history.replaceState) {
        const url = new URL(window.location.href);
        if (url.searchParams.has('error') || url.searchParams.has('success') || url.searchParams.has('msg')) {
            url.searchParams.delete('error');
            url.searchParams.delete('success');
            url.searchParams.delete('msg');
            window.history.replaceState({}, document.title, url.toString());
        }
    }

    // --- アコーディオン アイコン制御 ---
    document.querySelectorAll('.toggle-icon').forEach(btn => {
        const icon = btn.querySelector('i');
        const targetSelector = btn.getAttribute('data-bs-target');

        if (!targetSelector) return;

        const targets = document.querySelectorAll(targetSelector);

        targets.forEach(target => {
            target.addEventListener('show.bs.collapse', () => {
                if (icon.classList.contains('bi-plus-lg')) {
                    icon.classList.remove('bi-plus-lg');
                    icon.classList.add('bi-dash-lg');
                } else if (icon.classList.contains('bi-plus')) {
                    icon.classList.remove('bi-plus');
                    icon.classList.add('bi-dash');
                }
            });

            target.addEventListener('hide.bs.collapse', () => {
                setTimeout(() => {
                    const anyOpen = Array.from(targets).some(t => t.classList.contains('show') || t.classList.contains('collapsing'));
                    if (!anyOpen) {
                        if (icon.classList.contains('bi-dash-lg')) {
                            icon.classList.remove('bi-dash-lg');
                            icon.classList.add('bi-plus-lg');
                        } else if (icon.classList.contains('bi-dash')) {
                            icon.classList.remove('bi-dash');
                            icon.classList.add('bi-plus');
                        }
                    }
                }, 10);
            });
        });
    });
    // ---------------------------------------------------
    // 追加機能: 親グループが閉じたときに、子グループも自動的に閉じてアイコンをリセットする
    // ---------------------------------------------------
    const parentGroupSelectors = ['.l1-revenue-group', '.l1-expense-group'];

    parentGroupSelectors.forEach(selector => {
        // 親グループ（カテゴリ行など）を取得
        const parentRows = document.querySelectorAll(selector);

        parentRows.forEach(parentRow => {
            // 親行が「非表示(hide)」になったタイミングで発火
            parentRow.addEventListener('hide.bs.collapse', function (e) {
                // イベントのバブリング防止（念のため、自分自身のイベントのみ処理）
                if (e.target !== parentRow) return;

                // 1. この親行の中にある「子を開閉するボタン」を探す
                const childToggleBtn = parentRow.querySelector('.toggle-icon[data-bs-toggle="collapse"]');
                if (!childToggleBtn) return;

                // 2. そのボタンがターゲットにしている子行（詳細行）を取得
                const childTargetSelector = childToggleBtn.getAttribute('data-bs-target');
                if (!childTargetSelector) return;
                const childRows = document.querySelectorAll(childTargetSelector);

                // 3. 子行を強制的に閉じる（'show' クラスを削除）
                childRows.forEach(childRow => {
                    childRow.classList.remove('show');
                });

                // 4. ボタンのアイコンを「＋」に戻す
                const icon = childToggleBtn.querySelector('i');
                if (icon) {
                    // マイナス(開)ならプラス(閉)へ置換
                    if (icon.classList.contains('bi-dash')) {
                        icon.classList.remove('bi-dash');
                        icon.classList.add('bi-plus');
                    }
                    if (icon.classList.contains('bi-dash-lg')) {
                        icon.classList.remove('bi-dash-lg');
                        icon.classList.add('bi-plus-lg');
                    }
                    if (icon.classList.contains('bi-dash-circle')) {
                        icon.classList.remove('bi-dash-circle');
                        icon.classList.add('bi-plus-circle');
                    }
                }
            });
        });
    });
});