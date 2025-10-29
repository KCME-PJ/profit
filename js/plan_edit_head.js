document.addEventListener('DOMContentLoaded', function () {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const monthButtonsContainer = document.getElementById('monthButtonsContainer');

    // PHPから渡された登録済みデータ
    // yearMonthData は plan_edit.php で window.yearMonthData として設定されている
    const yearMonthData = window.yearMonthData;

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
    function updatePlanStatusButtons(year) {
        // 全ボタンを初期化
        document.querySelectorAll('#monthButtonsContainer button').forEach(button => {
            button.classList.remove('btn-primary', 'btn-success');
            button.classList.add('btn-secondary');
            button.disabled = true;
            // 既存のイベントリスナーを削除 (二重登録防止)
            button.replaceWith(button.cloneNode(true));
        });

        if (!year) return;

        // 新しいボタンに再度参照し直す
        const monthButtons = document.querySelectorAll('#monthButtonsContainer button');

        // ステータス取得 (API参照を plan バージョンに修正)
        fetch(`get_plan_status.php?year=${year}`)
            .then(response => response.json())
            .then(statuses => {
                // PHP側で返される statuses: {1: 'draft', 2: 'fixed', ...}
                monthButtons.forEach(button => {
                    const month = parseInt(button.textContent.replace('月', ''), 10);
                    if (isNaN(month)) return;

                    const status = statuses[month] || 'none';
                    button.disabled = false;
                    button.className = 'btn btn-sm me-1 mb-1'; // クラス初期化

                    if (status === 'fixed') {
                        button.classList.add('btn-success');
                    } else if (status === 'draft' || status === 'registered') {
                        button.classList.add('btn-primary');
                    } else {
                        button.classList.add('btn-secondary');
                    }

                    // 月ボタンクリック時のイベント再設定
                    button.addEventListener('click', function () {
                        // 月ドロップダウンの値を更新し、changeイベントを発火させる
                        monthSelect.value = month;
                        monthSelect.dispatchEvent(new Event('change'));
                    });
                });
            })
            .catch(error => console.error('ステータス取得エラー:', error));
    }

    // ---------------------------------------------
    // --- イベントリスナー ---
    // ---------------------------------------------

    // 年度選択時の処理
    yearSelect.addEventListener('change', function () {
        const selectedYear = yearSelect.value;
        if (!selectedYear) return;

        updateMonths();
        // 関数名を updatePlanStatusButtons に修正
        updatePlanStatusButtons(selectedYear);

        // URLパラメータを更新 (リロード時に year パラメータを維持するため)
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('year', selectedYear);
            url.searchParams.delete('month'); // 月は一旦リセット
            window.history.replaceState({}, document.title, url.pathname + url.search);
        }
    });

    // ---------------------------------------------
    // --- 初期ロード処理 ---
    // ---------------------------------------------

    // URLパラメータから year, month を取得
    const urlParams = new URLSearchParams(window.location.search);
    const initialYear = urlParams.get('year');
    const initialMonth = urlParams.get('month');

    if (initialYear) {
        // 1. 年度ドロップダウンの値をセット
        yearSelect.value = initialYear;
        // 2. 月ドロップダウンのオプションとボタンのステータスを更新
        updateMonths();
        // 関数名を updatePlanStatusButtons に修正
        updatePlanStatusButtons(initialYear);

        if (initialMonth) {
            // 3. 月ドロップダウンの値をセット (データロードは body.js の役割)
            monthSelect.value = initialMonth;
        }
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

            const type = button.getAttribute('data-export-type');
            let url = '';

            if (type === 'summary') {
                url = `plan_export_excel.php?year=${year}&month=${month}`;
            } else if (type === 'details') {
                url = `plan_export_excel_details.php?year=${year}&month=${month}`;
            }
            window.location.href = url;
        });
    });
});