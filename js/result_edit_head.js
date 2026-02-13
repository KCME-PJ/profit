document.addEventListener('DOMContentLoaded', function () {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const officeSelect = document.getElementById('officeSelect');
    const monthButtonsContainer = document.getElementById('monthButtonsContainer');

    // PHPから渡された登録済みデータ
    const yearMonthData = window.yearMonthData;

    // 現在の年度のステータス一覧を保持する変数
    let currentYearStatuses = {};

    /**
     * 年度選択に応じて月セレクトボックスを更新する
     */
    function updateMonths() {
        const selectedYear = yearSelect.value;

        // 一旦リセット
        monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
        monthSelect.disabled = true;

        if (selectedYear && yearMonthData[selectedYear]) {
            monthSelect.disabled = false;
            const months = yearMonthData[selectedYear];
            months.forEach(month => {
                const option = document.createElement('option');
                option.value = month;
                option.textContent = `${month}月`;
                monthSelect.appendChild(option);
            });
        }
    }

    /**
     * 月ボタンのステータス（色）を更新する
     * @param {string} year 選択された年度
     */
    function updateResultStatusButtons(year) {
        if (!year) return;

        // 現在選択中の営業所IDを取得
        const currentOfficeId = officeSelect ? officeSelect.value : '';

        // ボタンの参照を取得
        const monthButtons = document.querySelectorAll('#monthButtonsContainer button');

        fetch(`get_result_status.php?year=${year}&office_id=${currentOfficeId}`)
            .then(response => response.json())
            .then(statuses => {
                currentYearStatuses = statuses;

                monthButtons.forEach(button => {
                    const monthText = button.textContent.trim();
                    const month = parseInt(monthText.replace('月', ''), 10);
                    if (isNaN(month)) return;

                    const status = statuses[month] || 'none';
                    button.disabled = false;
                    button.className = 'btn btn-sm me-1 mb-1 month-btn'; // クラス初期化

                    if (status === 'fixed') {
                        button.classList.add('btn-success');   // 確定済: 緑
                    } else if (status === 'draft' || status === 'registered') {
                        button.classList.add('btn-primary');   // 登録済: 青
                    } else {
                        button.classList.add('btn-secondary'); // 未登録: グレー
                    }
                });
            })
            .catch(error => console.error('ステータス取得エラー:', error));
    }

    // ---------------------------------------------
    // --- イベントリスナー ---
    // ---------------------------------------------

    // 1. 年度変更時
    if (yearSelect) {
        yearSelect.addEventListener('change', function () {
            const selectedYear = this.value;
            updateMonths();
            updateResultStatusButtons(selectedYear);

            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.set('year', selectedYear);
                url.searchParams.delete('month');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            }
        });
    }

    // 2. 営業所変更時にもボタン色を更新する
    if (officeSelect) {
        officeSelect.addEventListener('change', function () {
            const selectedYear = yearSelect.value;
            if (selectedYear) {
                updateResultStatusButtons(selectedYear);
            }
        });
    }

    // 3. 月ボタンクリック時の処理 (イベント委譲)
    if (monthButtonsContainer) {
        monthButtonsContainer.addEventListener('click', function (e) {
            if (e.target.tagName === 'BUTTON') {
                const btn = e.target;
                const monthText = btn.textContent.trim();
                const month = parseInt(monthText.replace('月', ''), 10);

                if (!isNaN(month) && monthSelect) {
                    let optionExists = false;
                    for (let i = 0; i < monthSelect.options.length; i++) {
                        if (parseInt(monthSelect.options[i].value) === month) {
                            optionExists = true;
                            monthSelect.selectedIndex = i;
                            break;
                        }
                    }
                    if (!optionExists) {
                        const opt = document.createElement('option');
                        opt.value = month;
                        opt.text = month + '月';
                        monthSelect.add(opt);
                        monthSelect.value = month;
                    }
                    monthSelect.dispatchEvent(new Event('change'));
                }
            }
        });
    }

    // --- 初期ロード処理 ---
    const urlParams = new URLSearchParams(window.location.search);
    const initialYear = urlParams.get('year') || (yearSelect ? yearSelect.value : null);

    if (initialYear) {
        if (yearSelect) yearSelect.value = initialYear;
        updateMonths();
        const initialMonth = urlParams.get('month');
        if (initialMonth && monthSelect) {
            monthSelect.value = initialMonth;
        }
        updateResultStatusButtons(initialYear);
    }

    // エクセル集計ボタンの処理
    const exportButtons = document.querySelectorAll('[data-export-type]');

    exportButtons.forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();

            const year = yearSelect.value;
            const month = monthSelect.value;

            if (!year || !month) {
                alert('年度と月を選択してください。');
                return;
            }

            const status = currentYearStatuses[month] || 'none';

            if (status === 'none') {
                alert('この月のデータは未登録のため出力できません。');
                return;
            }

            const type = button.getAttribute('data-export-type');
            let url = '';

            if (type === 'summary') {
                url = `result_export_excel.php?year=${year}&month=${month}`;
            } else if (type === 'details') {
                url = `result_export_excel_details.php?year=${year}&month=${month}`;
            }

            if (status === 'draft' || status === 'registered') {
                if (confirm('このデータは未確定 (Draft) です。\n未確定のデータを出力しますか？')) {
                    window.location.href = url;
                }
            } else if (status === 'fixed') {
                window.location.href = url;
            }
        });
    });
});