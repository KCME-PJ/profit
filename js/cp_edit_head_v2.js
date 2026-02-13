document.addEventListener('DOMContentLoaded', function () {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const officeSelect = document.getElementById('officeSelect');
    const monthButtonsContainer = document.getElementById('monthButtonsContainer');

    const yearMonthData = window.yearMonthData || {};
    let currentYearStatuses = {};

    if (!yearSelect || !monthSelect) {
        console.warn('初期化に必要な要素が見つかりません。');
        return;
    }

    function updateMonths() {
        const selectedYear = yearSelect.value;
        const availableMonths = yearMonthData[selectedYear] || [];

        monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
        monthSelect.disabled = true;

        if (availableMonths.length > 0) {
            const sortedMonths = [...availableMonths].sort((a, b) => {
                const getSortValue = m => (m >= 4 ? m : m + 12);
                return getSortValue(a) - getSortValue(b);
            });

            sortedMonths.forEach(month => {
                const option = document.createElement('option');
                option.value = month;
                option.textContent = month + '月';
                monthSelect.appendChild(option);
            });

            monthSelect.disabled = false;
        }
    }

    function updateCpStatusButtons(year) {
        monthButtonsContainer.innerHTML = '';
        const startMonth = 4;

        // プレースホルダー生成
        for (let i = 0; i < 12; i++) {
            const month = ((startMonth + i - 1) % 12) + 1;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.id = `monthBtn${month}`;
            btn.className = 'btn btn-secondary btn-sm me-1 mb-1 month-btn';
            btn.textContent = `${month}月`;
            btn.dataset.month = month;
            // 初期状態は有効にしておく（色はグレー）
            // btn.disabled = true; 
            monthButtonsContainer.appendChild(btn);
        }

        if (!year) return;

        let officeId = 0;
        if (officeSelect) {
            officeId = officeSelect.value;
        }
        if (officeId === 'all') officeId = 0;

        fetch(`./get_cp_status.php?year=${year}&office_id=${officeId}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(statusList => {
                currentYearStatuses = statusList;

                const buttons = monthButtonsContainer.querySelectorAll('button');
                buttons.forEach(btn => {
                    const month = parseInt(btn.dataset.month, 10);
                    const status = statusList[month] || 'none';

                    // クラスリセット (month-btn を維持)
                    btn.className = 'btn btn-sm me-1 mb-1 month-btn';

                    if (status === 'fixed') {
                        btn.classList.add('btn-success');
                    } else if (status === 'draft' || status === 'registered') {
                        btn.classList.add('btn-primary');
                    } else {
                        btn.classList.add('btn-secondary');
                    }

                    // 全社表示の場合、未登録(none)でも月ボタンを押せば
                    // その月へ移動して「新規作成(Draft)」として扱えるようにする
                    if (status !== 'none') {
                        // データあり
                    } else {
                        // データなし（グレー）
                    }

                    // クリックイベント再設定
                    btn.addEventListener('click', function () {
                        // プルダウンにない月（未登録月）でも選択できるようにする処理
                        let optionExists = false;
                        for (let i = 0; i < monthSelect.options.length; i++) {
                            if (monthSelect.options[i].value == month) {
                                optionExists = true;
                                break;
                            }
                        }
                        if (!optionExists) {
                            const opt = document.createElement('option');
                            opt.value = month;
                            opt.text = month + '月';
                            monthSelect.add(opt);
                        }

                        monthSelect.value = month;
                        monthSelect.dispatchEvent(new Event('change'));
                    });
                });
            })
            .catch(err => {
                console.error('月ボタンステータス取得エラー:', err);
            });
    }

    // イベントリスナー
    yearSelect.addEventListener('change', function () {
        const selectedYear = this.value;
        updateMonths();
        updateCpStatusButtons(selectedYear);

        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('year', selectedYear);
            url.searchParams.delete('month');
            window.history.replaceState({}, document.title, url.pathname + url.search);
        }
    });

    if (officeSelect) {
        officeSelect.addEventListener('change', function () {
            const selectedYear = yearSelect.value;
            if (selectedYear) {
                updateCpStatusButtons(selectedYear);
            }
        });
    }

    // 初期ロード
    if (yearSelect.value) {
        const initialYear = yearSelect.value;
        updateMonths();
        updateCpStatusButtons(initialYear);

        const urlParams = new URLSearchParams(window.location.search);
        const initialMonth = urlParams.get('month');
        if (initialMonth) {
            monthSelect.value = initialMonth;
        }
    }

    const exportButtons = document.querySelectorAll('[data-export-type]');
    exportButtons.forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            const year = yearSelect.value;
            if (!year) {
                alert('年度を選択してください。');
                return;
            }
            const type = button.getAttribute('data-export-type');
            let url = '';
            if (type === 'summary') {
                url = `cp_export_excel_v2.php?year=${year}`;
            } else if (type === 'details') {
                url = `cp_export_excel_details_v2.php?year=${year}`;
            }
            if (url) {
                window.location.href = url;
            }
        });
    });
});