document.addEventListener('DOMContentLoaded', function () {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const monthButtonsContainer = document.getElementById('monthButtonsContainer');

    // PHPから渡された登録済みデータ
    const yearMonthData = window.yearMonthData;

    // 現在の年度のステータス一覧を保持する変数を定義
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
    function updateForecastStatusButtons(year) {
        // 全ボタンを初期化
        document.querySelectorAll('#monthButtonsContainer button').forEach(button => {
            button.classList.remove('btn-primary', 'btn-success');
            button.classList.add('btn-secondary');
            button.disabled = true;
            button.replaceWith(button.cloneNode(true));
        });

        if (!year) return;

        // 新しいボタンに再度参照し直す
        const monthButtons = document.querySelectorAll('#monthButtonsContainer button');

        // ステータス取得
        fetch(`get_forecast_status.php?year=${year}`)
            .then(response => response.json())
            .then(statuses => {

                // 取得したステータスをグローバル変数に保存
                currentYearStatuses = statuses;

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
                        monthSelect.value = month;
                        monthSelect.dispatchEvent(new Event('change'));
                    });
                });
            })
            .catch(error => {
                console.error('ステータス取得エラー:', error);
                currentYearStatuses = {}; // エラー時はリセット
            });
    }

    // ---------------------------------------------
    // --- イベントリスナー ---
    // ---------------------------------------------

    // 年度選択時の処理
    yearSelect.addEventListener('change', function () {
        const selectedYear = yearSelect.value;
        if (!selectedYear) return;

        updateMonths();
        updateForecastStatusButtons(selectedYear);

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

    const urlParams = new URLSearchParams(window.location.search);
    const initialYear = urlParams.get('year');
    const initialMonth = urlParams.get('month');

    if (initialYear) {
        yearSelect.value = initialYear;
        updateMonths();
        updateForecastStatusButtons(initialYear);

        if (initialMonth) {
            monthSelect.value = initialMonth;
        }
    }

    // ------------------------------
    // --- エクセル集計ボタンの処理 ---
    // ------------------------------
    const exportButtons = document.querySelectorAll('[data-export-type]');

    exportButtons.forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();

            const year = yearSelect.value;
            const month = monthSelect.value;

            if (!year || !month) {
                console.error('年度と月を選択してください。');
                return;
            }

            // 現在選択中の月のステータスを取得
            const status = currentYearStatuses[month] || 'none';

            // 1. 未登録データは出力させない
            if (status === 'none') {
                console.error('この月のデータは未登録のため出力できません。');
                // alert('この月のデータは未登録のため出力できません。');
                return;
            }

            // 2. URLを定義
            const type = button.getAttribute('data-export-type');
            let url = '';
            if (type === 'summary') {
                url = `forecast_export_excel.php?year=${year}&month=${month}`;
            } else if (type === 'details') {
                url = `forecast_export_excel_details.php?year=${year}&month=${month}`;
            }

            // 3. ステータスに応じて警告または実行
            if (status === 'draft' || status === 'registered') {
                // Draftの場合、警告を表示 (confirmが使える前提)
                if (confirm('このデータは未確定 (Draft) です。\n未確定のデータを出力しますか？')) {
                    // ユーザーが「OK」を押した場合のみ実行
                    window.location.href = url;
                }
                // (ユーザーが「キャンセル」を押した場合は何もしない)
            } else if (status === 'fixed') {
                // Fixedの場合は警告なしで実行
                window.location.href = url;
            }
        });
    });
});