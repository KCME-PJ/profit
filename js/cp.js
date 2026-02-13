document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('cpForm');
    const officeTimeData = {};
    const officeSelect = document.getElementById('officeSelect');
    const monthSelect = document.getElementById('monthSelect');
    const hourlyRateInput = document.getElementById('hourlyRate');
    const registerButton = document.querySelector('.register-button');
    const totalHoursInput = document.getElementById('totalHours');
    const timeFields = {
        standard_hours: document.getElementById('standardHours'),
        overtime_hours: document.getElementById('overtimeHours'),
        transferred_hours: document.getElementById('transferredHours'),
        fulltime_count: document.getElementById('fulltimeCount'),
        contract_count: document.getElementById('contractCount'),
        dispatch_count: document.getElementById('dispatchCount')
    };
    const revenueInputs = document.querySelectorAll('.revenue-input');
    const expenseInputs = document.querySelectorAll('.detail-input');

    let currentOfficeId = officeSelect.value || null;

    // グローバル変数から取得する
    let currentStatusMap = window.cpStatusMap || {};

    function roundTo2(num) {
        if (num === '' || num === null || num === undefined) return '';
        const f = parseFloat(num);
        if (isNaN(f)) return '';
        return Math.round(f * 100) / 100;
    }

    function captureCurrentOfficeTime(oid) {
        if (!oid) return;
        const data = officeTimeData[oid] || {};
        for (const key in timeFields) {
            const input = timeFields[key];
            if (input) {
                if (key.includes('hours')) {
                    data[key] = roundTo2(input.value);
                } else {
                    data[key] = input.value;
                }
            }
        }
        officeTimeData[oid] = data;
    }

    function renderOfficeTime(oid) {
        for (const key in timeFields) {
            const input = timeFields[key];
            if (input) {
                input.value = '';
                input.disabled = false;
            }
        }
        if (oid && officeTimeData[oid]) {
            const data = officeTimeData[oid];
            for (const key in timeFields) {
                const input = timeFields[key];
                if (input) {
                    const val = data[key] !== undefined ? data[key] : '';
                    if (key.includes('hours')) {
                        input.value = roundTo2(val);
                    } else {
                        input.value = val;
                    }
                }
            }
        }
    }

    function checkRegisterButtonState() {
        const month = parseInt(monthSelect.value);
        const status = currentStatusMap[month] || 'none';
        if (status === 'draft' || status === 'fixed') {
            registerButton.disabled = true;
            registerButton.textContent = (status === 'fixed') ? '確定済' : '登録済';
            registerButton.classList.remove('btn-outline-danger');
            registerButton.classList.add('btn-secondary');
        } else {
            registerButton.disabled = false;
            registerButton.textContent = '登録';
            registerButton.classList.remove('btn-secondary');
            registerButton.classList.add('btn-outline-danger');
        }
    }

    function filterDetailsByOffice() {
        const selectedOfficeId = officeSelect.value;
        const detailRows = document.querySelectorAll('.detail-row');

        detailRows.forEach(row => {
            const rowOfficeId = row.getAttribute('data-office-id');
            const inputs = row.querySelectorAll('input');
            if (rowOfficeId === 'common' || rowOfficeId === selectedOfficeId) {
                row.style.display = '';
                inputs.forEach(input => input.disabled = false);
            } else {
                row.style.display = 'none';
            }
        });
        calculate();
        checkRegisterButtonState();
    }

    function fetchStatus(year, officeId) {
        if (!year || !officeId) return;
        fetch(`get_cp_status.php?year=${year}&office_id=${officeId}`)
            .then(response => response.json())
            .then(data => {
                currentStatusMap = data;
                updateStatusButtons(data);
                checkRegisterButtonState();
            })
            .catch(err => console.error('Status fetch error:', err));
    }

    function updateStatusButtons(statusMap) {
        const buttons = document.querySelectorAll('.col-md-10 button.btn-sm');
        buttons.forEach(btn => {
            const txt = btn.textContent.trim();
            const month = parseInt(txt.replace('月', ''));
            if (!isNaN(month) && statusMap[month]) {
                const status = statusMap[month];
                btn.className = 'btn btn-sm me-1 mb-1';
                if (status === 'fixed') {
                    btn.classList.add('btn-success');
                } else if (status === 'draft') {
                    btn.classList.add('btn-primary');
                } else {
                    btn.classList.add('btn-secondary');
                }
            }
        });
    }

    function calculate() {
        let currentHours = 0;
        const std = parseFloat(timeFields.standard_hours.value) || 0;
        const ovt = parseFloat(timeFields.overtime_hours.value) || 0;
        const trn = parseFloat(timeFields.transferred_hours.value) || 0;
        currentHours = std + ovt + trn;

        if (totalHoursInput) {
            totalHoursInput.value = roundTo2(currentHours);
        }

        const hourlyRate = parseFloat(hourlyRateInput.value) || 0;
        const laborCost = Math.round(roundTo2(currentHours) * hourlyRate);

        document.getElementById('info-labor-cost').textContent = laborCost.toLocaleString();

        let revenueTotal = 0;
        const revenueCategoryTotals = {};

        revenueInputs.forEach(input => {
            const row = input.closest('tr');
            if (row && row.style.display === 'none') return;
            const val = parseFloat(input.value) || 0;
            const categoryId = input.getAttribute('data-category-id');
            revenueTotal += val;
            if (!revenueCategoryTotals[categoryId]) revenueCategoryTotals[categoryId] = 0;
            revenueCategoryTotals[categoryId] += val;
        });

        document.getElementById('total-revenue').textContent = Math.round(revenueTotal).toLocaleString();
        document.getElementById('info-revenue-total').textContent = Math.round(revenueTotal).toLocaleString();

        Object.keys(revenueCategoryTotals).forEach(categoryId => {
            const elem = document.getElementById(`total-revenue-category-${categoryId}`);
            if (elem) elem.textContent = Math.round(revenueCategoryTotals[categoryId]).toLocaleString();
        });

        let expenseTotal = 0;
        const accountTotals = {};

        expenseInputs.forEach(input => {
            const row = input.closest('tr');
            if (row && row.style.display === 'none') return;
            const val = parseFloat(input.value) || 0;
            const accountId = input.getAttribute('data-account-id');
            expenseTotal += val;
            if (!accountTotals[accountId]) accountTotals[accountId] = 0;
            accountTotals[accountId] += val;
        });

        document.getElementById('total-expense').textContent = Math.round(expenseTotal).toLocaleString();
        document.getElementById('info-expense-total').textContent = Math.round(expenseTotal).toLocaleString();

        Object.keys(accountTotals).forEach(accountId => {
            const elem = document.getElementById(`total-account-${accountId}`);
            if (elem) elem.textContent = Math.round(accountTotals[accountId]).toLocaleString();
        });

        const grossProfit = revenueTotal - expenseTotal;
        document.getElementById('info-gross-profit').textContent = Math.round(grossProfit).toLocaleString();
    }

    monthSelect.addEventListener('change', checkRegisterButtonState);

    form.addEventListener('input', function (event) {
        if (event.target.classList.contains('time-input') ||
            event.target.id === 'hourlyRate' ||
            event.target.classList.contains('revenue-input') ||
            event.target.classList.contains('detail-input')) {
            calculate();
        }
    });

    officeSelect.addEventListener('change', function () {
        captureCurrentOfficeTime(currentOfficeId);
        currentOfficeId = officeSelect.value;
        renderOfficeTime(currentOfficeId);
        filterDetailsByOffice();
        const year = document.getElementById('yearSelect').value;
        fetchStatus(year, currentOfficeId);
    });

    window.onYearChange = function () {
        const year = document.getElementById('yearSelect').value;
        const url = new URL(window.location.href);
        url.searchParams.set('year', year);
        window.location.href = url.toString();
    };

    form.addEventListener("submit", function (e) {
        captureCurrentOfficeTime(currentOfficeId);
        const rate = hourlyRateInput.value;
        for (const officeId in officeTimeData) {
            if (officeTimeData[officeId]) {
                officeTimeData[officeId]['hourly_rate'] = rate;
            }
        }
        document.getElementById("officeTimeData").value = JSON.stringify(officeTimeData);

        const bulkData = {
            revenues: {},
            accounts: {}
        };
        document.querySelectorAll('.revenue-input').forEach(input => {
            const id = input.getAttribute('data-id');
            const val = input.value;
            if (id) bulkData.revenues[id] = val;
        });
        document.querySelectorAll('.detail-input').forEach(input => {
            const id = input.getAttribute('data-id');
            const val = input.value;
            if (id) bulkData.accounts[id] = val;
        });
        document.getElementById('bulkJsonData').value = JSON.stringify(bulkData);
    });

    const l1Toggles = [{
        btn: document.querySelector('[data-bs-target=".l1-revenue-group"]'),
        targetClass: '.l1-revenue-group'
    },
    {
        btn: document.querySelector('[data-bs-target=".l1-expense-group"]'),
        targetClass: '.l1-expense-group'
    }
    ];

    l1Toggles.forEach(l1 => {
        if (!l1.btn) return;
        const iconElementL1 = l1.btn.querySelector('i');
        const l2Rows = document.querySelectorAll(l1.targetClass);
        l2Rows.forEach(l2Row => {
            l2Row.addEventListener('show.bs.collapse', () => {
                if (iconElementL1) {
                    iconElementL1.classList.remove('bi-plus-lg');
                    iconElementL1.classList.add('bi-dash-lg');
                }
            });
            l2Row.addEventListener('hide.bs.collapse', () => {
                setTimeout(() => {
                    const anyOpen = Array.from(l2Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                    if (!anyOpen && iconElementL1) {
                        iconElementL1.classList.remove('bi-dash-lg');
                        iconElementL1.classList.add('bi-plus-lg');
                    }
                }, 350);
                const l2Button = l2Row.querySelector('.toggle-icon-l2');
                if (l2Button) {
                    const l3TargetSelector = l2Button.getAttribute('data-bs-target');
                    if (l3TargetSelector) {
                        document.querySelectorAll(l3TargetSelector).forEach(el => {
                            const inst = bootstrap.Collapse.getInstance(el);
                            if (inst) inst.hide();
                        });
                        const iconL2 = l2Button.querySelector('i');
                        if (iconL2) {
                            iconL2.classList.remove('bi-dash');
                            iconL2.classList.add('bi-plus');
                        }
                    }
                }
            });
        });
    });

    document.querySelectorAll('.toggle-icon-l2').forEach(function (l2Button) {
        const iconElementL2 = l2Button.querySelector('i');
        const l3TargetSelector = l2Button.getAttribute('data-bs-target');
        if (!l3TargetSelector) return;
        const l3Rows = document.querySelectorAll(l3TargetSelector);
        l3Rows.forEach(l3Row => {
            l3Row.addEventListener('show.bs.collapse', () => {
                if (iconElementL2) {
                    iconElementL2.classList.remove('bi-plus');
                    iconElementL2.classList.add('bi-dash');
                }
            });
            l3Row.addEventListener('hide.bs.collapse', () => {
                setTimeout(() => {
                    const anyOpen = Array.from(l3Rows).some(el => el.classList.contains('show') || el.classList.contains('collapsing'));
                    if (!anyOpen && iconElementL2) {
                        iconElementL2.classList.remove('bi-dash');
                        iconElementL2.classList.add('bi-plus');
                    }
                }, 350);
            });
        });
    });

    const errorAlertElem = document.getElementById('errorAlert');
    if (errorAlertElem) {
        errorAlertElem.addEventListener('close.bs.alert', function () {
            const url = new URL(window.location.href);
            url.searchParams.delete('error');
            window.history.replaceState({}, document.title, url.pathname + url.search);
        });
    }
    const successAlertElem = document.getElementById('successAlert');
    if (successAlertElem) {
        successAlertElem.addEventListener('close.bs.alert', function () {
            const url = new URL(window.location.href);
            url.searchParams.delete('success');
            url.searchParams.delete('month');
            window.history.replaceState({}, document.title, url.pathname + url.search);
        });
    }

    renderOfficeTime(currentOfficeId);
    filterDetailsByOffice();
    calculate();
    checkRegisterButtonState();
});