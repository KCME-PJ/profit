document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');

    const userRole = document.body.getAttribute('data-user-role') || 'viewer';
    const isAdmin = (userRole === 'admin');
    const isViewer = (userRole === 'viewer');

    // 画面読み込み直後のURLパラメータを記憶しておく
    const initialUrlParams = new URLSearchParams(window.location.search);
    const hasInitialError = initialUrlParams.has('error');
    const hasInitialSuccess = initialUrlParams.has('success');

    // ----------------------------------------------------------------
    // DOM要素の取得
    // ----------------------------------------------------------------

    // --- 営業所別入力要素 ---
    const officeSelect = document.getElementById('officeSelect');
    const hiddenTime = document.getElementById('officeTimeData');
    const hourlyRateInput = document.getElementById('hourlyRate');

    // 総時間表示用エレメント & 賃率隠しフィールド
    const totalHoursInput = document.getElementById('totalHours');
    const hiddenHourlyRateInput = document.getElementById('hiddenHourlyRate');

    // ボタン
    const submitBtnUpdate = document.querySelector('.register-button1'); // 修正ボタン
    const submitBtnFixed = document.querySelector('.register-button2');  // 確定ボタン
    const btnReject = document.getElementById('btnReject');

    // Admin Controls
    const adminParentControls = document.getElementById('adminParentControls');
    // 画面上のトリガーボタンのIDを正しく取得 (Confirmボタンではない)
    const btnParentFix = document.getElementById('btnParentFix');
    const btnParentUnlock = document.getElementById('btnParentUnlock');
    const parentFixedLabel = document.getElementById('parentFixedLabel');
    const unfixedBadge = document.getElementById('unfixedBadge');
    const unfixedCountSpan = document.getElementById('unfixedCount');
    const unfixedOfficeListUl = document.getElementById('unfixedOfficeList');

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

    // グローバルステータス管理
    let isAllFixedGlobal = false;
    let unfixedOfficesGlobal = [];

    // --- ヘルパー関数: 小数点誤差を解消する (小数点第2位まで) ---
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
            alertDiv = document.createElement('div');
            alertDiv.id = 'errorAlert';
            if (form && form.parentNode) {
                form.parentNode.insertBefore(alertDiv, form);
            }
        }
        return alertDiv;
    }

    // 1. 初期ロード
    try {
        officeTimeDataLocal = JSON.parse(hiddenTime.value || "{}");
    } catch (e) {
        console.error('初期データのパースに失敗:', e);
    }

    // ----------------------------------------------------------------
    // データ退避・復元機能 (SessionStorage)
    // ----------------------------------------------------------------
    const BACKUP_KEY = 'outlook_edit_backup_data';

    function backupFormData() {
        if (isAdmin || isViewer) return; // AdminとViewerはバックアップ不要

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
            const ignoreIds = ['monthlyOutlookId', 'olStatus', 'updatedAt', 'bulkJsonData', 'officeTimeData', 'officeSelect', 'yearSelect', 'monthSelect', 'hourlyRate', 'hiddenHourlyRate', 'outlookMode', 'targetOfficeId'];
            const timeFieldIds = ['standardHours', 'overtimeHours', 'transferred_hours', 'fulltimeCount', 'contractCount', 'dispatchCount'];

            if (input.id && (ignoreIds.includes(input.id) || timeFieldIds.includes(input.id))) return;
            if (input.type === 'hidden' && !input.classList.contains('detail-input')) return;

            const key = input.name || input.id;
            if (key) currentData.inputs[key] = input.value;
        });

        const backupPackage = {
            current: currentData,
            initial: initialFormData
        };
        sessionStorage.setItem(BACKUP_KEY, JSON.stringify(backupPackage));
    }

    function restoreFormData() {
        if (isAdmin || isViewer) return; // AdminとViewerは復元不要

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
                    let input = document.querySelector(`[name="${key}"]`);
                    if (!input) input = document.getElementById(key);
                    if (input) input.value = value;
                }
            }
            updateTotals();
            updateButtonState();
            filterDetailsByOffice();

            const errorAlert = getOrCreateErrorAlert();
            if (errorAlert) {
                const msg = document.createElement('div');
                msg.innerHTML = '<strong>※入力内容を復元し、最新データと統合しました。<br>内容を確認して再度保存してください。</strong>';
                msg.className = 'mt-2 text-dark bg-warning-subtle p-2 rounded border border-warning';
                errorAlert.appendChild(msg);
            }
        } catch (e) {
            console.error('復元エラー', e);
        }
        sessionStorage.removeItem(BACKUP_KEY);
    }

    function clearBackup() {
        sessionStorage.removeItem(BACKUP_KEY);
    }

    // ----------------------------------------------------------------
    // 変更検知 (Dirty Check)
    // ----------------------------------------------------------------
    function getFormDataString() {
        if (currentOfficeId && currentOfficeId !== 'all') {
            captureCurrentOfficeTime(currentOfficeId);
        }
        const inputs = document.querySelectorAll('input, select, textarea');
        const data = {};
        const ignoreIds = [
            'officeTimeData', 'officeSelect', 'hourlyRate', 'hiddenHourlyRate', 'totalHours',
            'standardHours', 'overtimeHours', 'transferred_hours', 'fulltimeCount', 'contractCount', 'dispatchCount',
            'bulkJsonData', 'outlookMode', 'olStatus', 'updatedAt', 'targetOfficeId'
        ];

        inputs.forEach(input => {
            if (ignoreIds.includes(input.id)) return;
            if (input.type === 'hidden' && !input.classList.contains('detail-input')) return;
            if (input.name) data[input.name] = input.value;
        });

        data['officeTimeDataLocal'] = officeTimeDataLocal;
        if (hourlyRateInput) data['hourlyRate'] = hourlyRateInput.value;

        return JSON.stringify(data);
    }

    function updateButtonState() {
        const olIdInput = document.getElementById('monthlyOutlookId');
        const hasData = (olIdInput && olIdInput.value);
        const statusInput = document.getElementById('olStatus');
        const parentStatus = statusInput ? statusInput.value : '';
        const isAllSelected = (officeSelect && officeSelect.value === 'all');

        if (isViewer) {
            if (submitBtnUpdate) submitBtnUpdate.disabled = true;
            if (submitBtnFixed) submitBtnFixed.disabled = true;
            return;
        }

        if (isAdmin) {
            document.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.type === 'hidden') return;
                if (el.id !== 'yearSelect' && el.id !== 'monthSelect' && el.id !== 'officeSelect') {
                    el.disabled = true;
                }
            });

            if (adminParentControls) {
                adminParentControls.style.display = 'inline-flex';
                const showParentBtns = isAllSelected;

                if (parentStatus === 'fixed') {
                    if (btnParentFix) btnParentFix.style.display = 'none';
                    if (btnParentUnlock) btnParentUnlock.style.display = showParentBtns ? 'inline-block' : 'none';
                    if (parentFixedLabel) parentFixedLabel.style.display = 'inline-block';
                    if (unfixedBadge) unfixedBadge.style.display = 'none';
                } else {
                    if (btnParentFix) {
                        btnParentFix.style.display = showParentBtns ? 'inline-block' : 'none';
                        // 全ての営業所が確定済みで、かつデータが存在する場合のみ押せる
                        btnParentFix.disabled = !isAllFixedGlobal || !hasData;
                    }
                    if (btnParentUnlock) btnParentUnlock.style.display = 'none';
                    if (parentFixedLabel) parentFixedLabel.style.display = 'none';
                    if (unfixedBadge) {
                        unfixedBadge.style.display = (unfixedOfficesGlobal.length > 0) ? 'inline-block' : 'none';
                    }
                }
            }

            if (btnReject) {
                if (isAllSelected) {
                    btnReject.style.display = 'none';
                } else {
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

        let isChildFixed = false;
        if (currentOfficeId && officeTimeDataLocal[currentOfficeId]) {
            if (officeTimeDataLocal[currentOfficeId].status === 'fixed') {
                isChildFixed = true;
            }
        }

        const currentFormData = getFormDataString();
        const isChanged = (initialFormData !== currentFormData);

        const canUpdate = !isAllSelected && isChanged && !isChildFixed;
        submitBtnUpdate.disabled = !canUpdate;

        const canFix = !isChanged && !isAllSelected && !isChildFixed;
        submitBtnFixed.disabled = !canFix;
    }

    function initDirtyCheck() {
        const floatKeys = ['standard_hours', 'overtime_hours', 'transferred_hours'];
        const intKeys = ['fulltime_count', 'contract_count', 'dispatch_count'];
        for (const oid in officeTimeDataLocal) {
            if (!officeTimeDataLocal[oid]) continue;
            [...floatKeys, ...intKeys].forEach(key => {
                let val = officeTimeDataLocal[oid][key];
                if (val === undefined || val === null || val === '') {
                    officeTimeDataLocal[oid][key] = '';
                } else {
                    if (floatKeys.includes(key)) officeTimeDataLocal[oid][key] = roundTo2(val);
                    else officeTimeDataLocal[oid][key] = parseInt(val) || 0;
                }
            });
        }
        if (currentOfficeId && currentOfficeId !== 'all') {
            captureCurrentOfficeTime(currentOfficeId);
        }
        initialFormData = getFormDataString();
        updateButtonState();
    }

    // ----------------------------------------------------------------
    // UI制御 (フィルタリング & 描画)
    // ----------------------------------------------------------------
    function filterDetailsByOffice() {
        if (!officeSelect) return;
        const selectedOfficeId = officeSelect.value;
        const detailRows = document.querySelectorAll('.detail-row');

        detailRows.forEach(row => {
            const rowOfficeId = row.getAttribute('data-office-id');
            const isMatch = (selectedOfficeId === 'all' || rowOfficeId === 'common' || rowOfficeId === selectedOfficeId);

            // 入力欄の無効化（Admin、Viewer）
            if (isMatch) {
                row.style.display = '';
                const isGloballyDisabled = document.getElementById('standardHours').disabled;
                const shouldDisable = isAdmin || isViewer || (selectedOfficeId === 'all') || isGloballyDisabled;
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
            const rateVal = (hourlyRateInput.value === '') ? '' : parseFloat(hourlyRateInput.value) || 0;
            for (const id in officeTimeDataLocal) {
                if (!officeTimeDataLocal[id]) officeTimeDataLocal[id] = {};
                officeTimeDataLocal[id].hourly_rate = rateVal;
            }
        }
        officeTimeDataLocal[oid] = data;
    }

    function renderOfficeToDom(oid) {
        const statusInput = document.getElementById('olStatus');
        const parentStatus = statusInput ? statusInput.value : '';
        const lockedStatuses = ['fixed', 'approved', 'registered'];

        let isChildFixed = false;
        if (oid && officeTimeDataLocal[oid] && officeTimeDataLocal[oid].status === 'fixed') {
            isChildFixed = true;
        }

        const isNotRegistered = !parentStatus || parentStatus === 'none';
        const shouldDisable = isAdmin || isViewer || lockedStatuses.includes(parentStatus) || isChildFixed || isNotRegistered;

        if (oid === 'all') {
            let totals = { standard_hours: 0, overtime_hours: 0, transferred_hours: 0, fulltime_count: 0, contract_count: 0, dispatch_count: 0 };
            let commonRate = '';
            if (hourlyRateInput && hourlyRateInput.value) {
                commonRate = hourlyRateInput.value;
            } else if (hiddenHourlyRateInput && hiddenHourlyRateInput.value) {
                commonRate = hiddenHourlyRateInput.value;
            }

            for (const id in officeTimeDataLocal) {
                const d = officeTimeDataLocal[id];
                if (!d) continue;
                totals.standard_hours += parseFloat(d.standard_hours) || 0;
                totals.overtime_hours += parseFloat(d.overtime_hours) || 0;
                totals.transferred_hours += parseFloat(d.transferred_hours) || 0;
                totals.fulltime_count += parseInt(d.fulltime_count) || 0;
                totals.contract_count += parseInt(d.contract_count) || 0;
                totals.dispatch_count += parseInt(d.dispatch_count) || 0;
            }
            for (const key in timeFields) {
                if (timeFields[key]) {
                    if (key.includes('hours')) timeFields[key].value = roundTo2(totals[key]);
                    else timeFields[key].value = totals[key];
                    timeFields[key].disabled = true;
                    timeFields[key].classList.add('bg-light');
                }
            }
            if (hourlyRateInput) {
                hourlyRateInput.value = commonRate;
                hourlyRateInput.disabled = true;
                hourlyRateInput.classList.add('bg-light');
            }
            updateTotals();
            return;
        }

        const data = officeTimeDataLocal[oid] || {};
        for (const key in timeFields) {
            const val = data[key];
            if (timeFields[key]) {
                const rawVal = (val !== undefined && val !== null && val !== '') ? val : '';
                timeFields[key].value = key.includes('hours') ? roundTo2(rawVal) : rawVal;

                timeFields[key].disabled = shouldDisable;
                if (shouldDisable) {
                    timeFields[key].classList.add('bg-light');
                } else {
                    timeFields[key].classList.remove('bg-light');
                }
            }
        }

        if (hourlyRateInput) {
            if (!hourlyRateInput.value && hiddenHourlyRateInput && hiddenHourlyRateInput.value) {
                hourlyRateInput.value = hiddenHourlyRateInput.value;
            }
            hourlyRateInput.disabled = shouldDisable;
            if (shouldDisable) {
                hourlyRateInput.classList.add('bg-light');
            } else {
                hourlyRateInput.classList.remove('bg-light');
            }
        }
        updateTotals();

        if (isAdmin) {
            document.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.type === 'hidden') return;
                if (el.id !== 'yearSelect' && el.id !== 'monthSelect' && el.id !== 'officeSelect') {
                    el.disabled = true;
                }
            });
        }
    }

    function disableAllInputs() {
        document.querySelectorAll('input, select, textarea').forEach(el => {
            if (el.type === 'hidden') return;
            if (el.id !== 'yearSelect' && el.id !== 'monthSelect' && el.id !== 'officeSelect') {
                el.disabled = true;
                el.classList.add('bg-light');
            }
        });
        if (submitBtnUpdate) submitBtnUpdate.disabled = true;
        if (submitBtnFixed) submitBtnFixed.disabled = true;
        if (btnReject) btnReject.style.display = 'none';
        renderOfficeToDom(currentOfficeId);
    }

    function prepareAndSubmitForm(action) {
        if (!monthSelect.value) { alert('年度と月を選択してください。'); return; }
        backupFormData();
        captureCurrentOfficeTime(currentOfficeId);

        const targetOfficeInput = document.getElementById('targetOfficeId');
        if (targetOfficeInput && officeSelect) targetOfficeInput.value = officeSelect.value;

        if (hourlyRateInput && hiddenHourlyRateInput) hiddenHourlyRateInput.value = hourlyRateInput.value;
        if (hiddenTime) hiddenTime.value = JSON.stringify(officeTimeDataLocal);

        if (document.getElementById('outlookMode')) document.getElementById('outlookMode').value = action;

        if (action === 'reject' || action === 'parent_fix' || action === 'parent_unlock') {
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

        form.submit();
    }

    function resetInputFields() {
        if (hourlyRateInput) hourlyRateInput.value = '';
        if (totalHoursInput) totalHoursInput.value = '';
        if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = '';
        for (const key in timeFields) { if (timeFields[key]) timeFields[key].value = ''; }
        document.querySelectorAll('.revenue-input').forEach(input => input.value = '');
        document.querySelectorAll('.detail-input').forEach(input => input.value = '');
        officeTimeDataLocal = {};
        if (document.getElementById('monthlyOutlookId')) document.getElementById('monthlyOutlookId').value = '';
        if (document.getElementById('olStatus')) document.getElementById('olStatus').value = '';
        if (document.getElementById('updatedAt')) document.getElementById('updatedAt').value = '';

        isAllFixedGlobal = false;
        unfixedOfficesGlobal = [];
        if (unfixedCountSpan) unfixedCountSpan.textContent = '0';
        if (unfixedOfficeListUl) unfixedOfficeListUl.innerHTML = '';

        updateTotals();
        initialFormData = "";
    }

    // --- イベントリスナー ---
    if (document.getElementById('confirmSubmit')) document.getElementById('confirmSubmit').addEventListener('click', function () { prepareAndSubmitForm('update'); });
    if (document.getElementById('outlookFixConfirmBtn')) document.getElementById('outlookFixConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('fixed'); });
    if (document.getElementById('outlookRejectConfirmBtn')) document.getElementById('outlookRejectConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('reject'); });
    if (document.getElementById('outlookParentFixConfirmBtn')) document.getElementById('outlookParentFixConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('parent_fix'); });
    if (document.getElementById('outlookParentUnlockConfirmBtn')) document.getElementById('outlookParentUnlockConfirmBtn').addEventListener('click', function () { prepareAndSubmitForm('parent_unlock'); });

    document.querySelectorAll('.month-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!yearSelect.value) {
                alert('年度を選択してください。');
                return;
            }
            const selectedMonth = this.getAttribute('data-month');
            let optionExists = false;
            for (let i = 0; i < monthSelect.options.length; i++) {
                if (monthSelect.options[i].value == selectedMonth) { optionExists = true; break; }
            }
            if (!optionExists) {
                const opt = document.createElement('option');
                opt.value = selectedMonth; opt.text = selectedMonth + '月';
                monthSelect.add(opt);
            }
            monthSelect.value = selectedMonth;
            monthSelect.dispatchEvent(new Event('change'));
        });
    });

    if (officeSelect) {
        officeSelect.addEventListener('change', () => {
            if (!monthSelect.value) { alert("月を選択してください。"); officeSelect.value = currentOfficeId || 'all'; return; }

            // Adminでなければチェック
            if (!isAdmin) {
                if (initialFormData !== "") {
                    const currentFormData = getFormDataString();
                    if (initialFormData !== currentFormData) {
                        if (!confirm("入力内容が保存されていません。\n移動しますか？")) { officeSelect.value = currentOfficeId; return; }
                    }
                }
            }
            const currentRate = hourlyRateInput ? hourlyRateInput.value : '';
            captureCurrentOfficeTime(currentOfficeId);
            currentOfficeId = officeSelect.value;
            renderOfficeToDom(currentOfficeId);
            if (currentOfficeId !== 'all' && currentRate !== '' && hourlyRateInput) {
                hourlyRateInput.value = currentRate;
                if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = currentRate;
                if (!officeTimeDataLocal[currentOfficeId]) officeTimeDataLocal[currentOfficeId] = {};
                officeTimeDataLocal[currentOfficeId].hourly_rate = parseFloat(currentRate);
            }
            const errorAlert = getOrCreateErrorAlert();
            if (errorAlert) {
                errorAlert.innerHTML = '';
                errorAlert.className = ''; // クラス（ピンクの背景色など）を削除
                errorAlert.removeAttribute('style'); // 余計なスタイルをクリア
            }

            const myOfficeData = officeTimeDataLocal[currentOfficeId];
            const parentIdInput = document.getElementById('monthlyOutlookId');
            const hasParentData = parentIdInput && parentIdInput.value;

            // Adminでも「その営業所のデータが無い」場合はアラートを出す
            if (currentOfficeId !== 'all' && hasParentData && !myOfficeData) {
                if (errorAlert) {
                    // 黄色い警告の帯として再設定
                    errorAlert.className = 'alert alert-warning d-flex align-items-center mt-5 position-relative';
                    errorAlert.style.zIndex = '1050';
                    errorAlert.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div>
                            <strong>データ未登録</strong><br>
                            この営業所のデータはまだ登録されていません。
                        </div>
                    `;
                }
                if (!isAdmin) {
                    disableAllInputs();
                } else {
                    renderOfficeToDom(currentOfficeId); // Adminは見れるように
                }
            } else {
                renderOfficeToDom(currentOfficeId);
            }
            filterDetailsByOffice(); updateButtonState(); initialFormData = getFormDataString();
        });
    }

    if (yearSelect) yearSelect.addEventListener('change', function () { });

    function updateTotals() {
        let totalHours = 0; let totalLaborCost = 0;
        const hourlyRate = (hourlyRateInput ? parseFloat(hourlyRateInput.value) : 0) || 0;
        for (const oid in officeTimeDataLocal) {
            if (!officeTimeDataLocal[oid]) officeTimeDataLocal[oid] = {};
            officeTimeDataLocal[oid].hourly_rate = hourlyRate;
        }
        if (currentOfficeId === 'all') {
            for (const officeId in officeTimeDataLocal) {
                const data = officeTimeDataLocal[officeId];
                if (data) {
                    totalHours += (parseFloat(data.standard_hours) || 0) + (parseFloat(data.overtime_hours) || 0) + (parseFloat(data.transferred_hours) || 0);
                }
            }
        } else {
            if (currentOfficeId) {
                captureCurrentOfficeTime(currentOfficeId);
                const data = officeTimeDataLocal[currentOfficeId];
                if (data) totalHours += (parseFloat(data.standard_hours) || 0) + (parseFloat(data.overtime_hours) || 0) + (parseFloat(data.transferred_hours) || 0);
            }
        }
        if (totalHoursInput) totalHoursInput.value = totalHours.toFixed(2);
        totalLaborCost = Math.round(totalHours * hourlyRate);
        if (document.getElementById('info-labor-cost')) document.getElementById('info-labor-cost').textContent = totalLaborCost.toLocaleString();

        let expenseTotal = 0; const accountTotals = {};
        document.querySelectorAll('.detail-input').forEach(input => {
            const row = input.closest('tr');
            if (row && row.style.display === 'none') return;
            const rowOfficeId = row.getAttribute('data-office-id');
            if (currentOfficeId && currentOfficeId !== 'all') {
                if (rowOfficeId !== 'common' && rowOfficeId !== currentOfficeId) return;
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
        let revenueTotal = 0; const revenueCategoryTotals = {};
        document.querySelectorAll('.revenue-input').forEach(input => {
            const row = input.closest('tr');
            if (row && row.style.display === 'none') return;
            const rowOfficeId = row.getAttribute('data-office-id');
            if (currentOfficeId && currentOfficeId !== 'all') {
                if (rowOfficeId !== 'common' && rowOfficeId !== currentOfficeId) return;
            }
            const val = parseFloat(input.value) || 0;
            const categoryId = input.dataset.categoryId;
            revenueTotal += val;
            if (!revenueCategoryTotals[categoryId]) revenueCategoryTotals[categoryId] = 0;
            revenueCategoryTotals[categoryId] += val;
        });
        if (document.getElementById('total-revenue')) document.getElementById('total-revenue').textContent = revenueTotal.toLocaleString();
        if (document.getElementById('info-revenue-total')) document.getElementById('info-revenue-total').textContent = revenueTotal.toLocaleString();
        document.querySelectorAll('[id^="total-revenue-category-"]').forEach(target => {
            const idStr = target.id.replace('total-revenue-category-', '');
            const sum = revenueCategoryTotals[idStr] || 0;
            target.textContent = sum.toLocaleString();
        });
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

            // 変数名を requestParams に変更して、外側のスコープ変数との衝突を回避
            const requestParams = new URLSearchParams(window.location.search);

            if (e.isTrusted) {
                const errorAlert = document.getElementById('errorAlert');
                if (errorAlert) { errorAlert.innerHTML = ''; errorAlert.className = ''; }
                const successAlert = document.getElementById('successAlert');
                if (successAlert) { successAlert.innerHTML = ''; successAlert.className = ''; }
            }

            fetch(`outlook_edit_load.php?year=${year}&month=${month}`).then(response => response.json()).then(data => {
                if (data.error) throw new Error(data.error);

                const errorAlert = getOrCreateErrorAlert();

                // 初回ロードでエラーがなく、かつユーザー操作でない場合はクリアを許可
                if (errorAlert && !hasInitialError && !e.isTrusted) {
                    errorAlert.innerHTML = ''; errorAlert.className = '';
                }

                if (!data.monthly_outlook_id) {
                    if (errorAlert && (e.isTrusted || !hasInitialError)) {
                        errorAlert.className = 'alert alert-secondary d-flex align-items-center mt-5 position-relative';
                        errorAlert.style.zIndex = '1050';
                        errorAlert.innerHTML = `<i class="bi bi-info-circle-fill me-2"></i><div><strong>この月はまだ登録されていません。</strong></div>`;
                    }
                    disableAllInputs(); return;
                }

                officeTimeDataLocal = data.offices || {};
                if (document.getElementById('monthlyOutlookId')) document.getElementById('monthlyOutlookId').value = data.monthly_outlook_id ?? '';
                if (document.getElementById('olStatus')) document.getElementById('olStatus').value = data.status ?? '';
                isAllFixedGlobal = !!data.all_fixed; unfixedOfficesGlobal = data.unfixed_offices || [];
                if (unfixedOfficeListUl) {
                    unfixedOfficeListUl.innerHTML = '';
                    if (unfixedOfficesGlobal.length > 0) {
                        unfixedOfficesGlobal.forEach(name => {
                            const li = document.createElement('li'); li.className = 'list-group-item'; li.textContent = name; unfixedOfficeListUl.appendChild(li);
                        });
                    } else unfixedOfficeListUl.innerHTML = '<li class="list-group-item text-muted">なし（全営業所確定済）</li>';
                }
                if (unfixedCountSpan) unfixedCountSpan.textContent = unfixedOfficesGlobal.length;
                const updatedAtInput = document.getElementById('updatedAt');
                if (updatedAtInput) updatedAtInput.value = data.updated_at || '';
                else { const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.id = 'updatedAt'; hidden.name = 'updated_at'; hidden.value = data.updated_at || ''; form.appendChild(hidden); }
                const loadedRate = data.common_hourly_rate || '';
                if (hourlyRateInput) {
                    hourlyRateInput.value = (loadedRate !== 0 && loadedRate !== '') ? loadedRate : '';
                    if (hiddenHourlyRateInput) hiddenHourlyRateInput.value = hourlyRateInput.value;
                }
                for (const oid in officeTimeDataLocal) {
                    officeTimeDataLocal[oid].hourly_rate = loadedRate ? parseFloat(loadedRate) : '';
                }
                const myOfficeData = officeTimeDataLocal[currentOfficeId];

                // 親データはあるが、自営業所のデータが無い場合
                if (currentOfficeId !== 'all' && !myOfficeData) {
                    if (errorAlert && (e.isTrusted || !hasInitialError)) {
                        const year = yearSelect.value;
                        const month = monthSelect.value;
                        errorAlert.className = 'alert alert-warning d-flex align-items-center mt-5 position-relative';
                        errorAlert.style.zIndex = '1050';
                        errorAlert.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div>
                            <strong>データ未登録</strong><br>
                            この営業所のデータはまだ登録されていません。<br>
                            <a href="../cp/cp.php?year=${year}&month=${month}" class="alert-link">CP計画画面（新規登録）</a> から登録を行ってください。
                        </div>`;
                    }
                    if (!isAdmin) {
                        disableAllInputs();
                        renderOfficeToDom(currentOfficeId);
                        return;
                    } else {
                        renderOfficeToDom(currentOfficeId); // Adminは見れるように
                    }
                } else {
                    renderOfficeToDom(currentOfficeId);
                }

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
                initDirtyCheck(); filterDetailsByOffice();

                // 初回URLパラメータを使って復元処理を判断
                if (!e.isTrusted) {
                    if (hasInitialError && !isAdmin) restoreFormData();
                    else if (hasInitialSuccess) clearBackup();
                }
            }).catch(error => { console.error('データ読み込みエラー:', error); alert('データの読み込みに失敗しました。'); resetInputFields(); });
        });
    }

    const l1Toggles = [{ btn: document.querySelector('[data-bs-target=".l1-revenue-group"]'), targetClass: '.l1-revenue-group' }, { btn: document.querySelector('[data-bs-target=".l1-expense-group"]'), targetClass: '.l1-expense-group' }];
    l1Toggles.forEach(l1 => {
        if (!l1.btn) return;
        const iconElementL1 = l1.btn.querySelector('i');
        const l2Rows = document.querySelectorAll(l1.targetClass);
        l2Rows.forEach(l2Row => {
            l2Row.addEventListener('show.bs.collapse', () => { if (iconElementL1) { iconElementL1.classList.remove('bi-plus-lg'); iconElementL1.classList.add('bi-dash-lg'); } });
            l2Row.addEventListener('hide.bs.collapse', () => {
                setTimeout(() => {
                    const anyOpen = Array.from(l2Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                    if (!anyOpen && iconElementL1) { iconElementL1.classList.remove('bi-dash-lg'); iconElementL1.classList.add('bi-plus-lg'); }
                }, 350);
                const l2Button = l2Row.querySelector('.toggle-icon');
                if (l2Button) {
                    const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                    if (l3TargetSelector) {
                        const l3Rows = document.querySelectorAll(l3TargetSelector);
                        l3Rows.forEach(l3Row => { const instance = bootstrap.Collapse.getInstance(l3Row); if (instance) instance.hide(); });
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
            l3Row.addEventListener('show.bs.collapse', () => { if (iconElementL2) { iconElementL2.classList.remove('bi-plus'); iconElementL2.classList.add('bi-dash'); } });
            l3Row.addEventListener('hide.bs.collapse', () => {
                setTimeout(() => {
                    const anyOpen = Array.from(l3Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                    if (!anyOpen && iconElementL2) { iconElementL2.classList.remove('bi-dash'); iconElementL2.classList.add('bi-plus'); }
                }, 350);
            });
        });
    });

    const urlParams = new URLSearchParams(window.location.search);
    const initialMonth = urlParams.get('month');
    if (urlParams.get('year') && initialMonth && yearSelect && monthSelect) {
        yearSelect.value = urlParams.get('year');
        if (yearSelect.dispatchEvent) yearSelect.dispatchEvent(new Event('change'));
        setTimeout(() => { monthSelect.value = initialMonth; if (monthSelect.dispatchEvent) monthSelect.dispatchEvent(new Event('change')); }, 100);
    } else { renderOfficeToDom(currentOfficeId); filterDetailsByOffice(); initDirtyCheck(); }

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