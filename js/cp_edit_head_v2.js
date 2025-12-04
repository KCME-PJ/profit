document.addEventListener('DOMContentLoaded', function () {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const monthButtonsContainer = document.getElementById('monthButtonsContainer');

    // PHPから渡された登録済みデータがない場合のガード
    const yearMonthData = window.yearMonthData || {};

    // 現在の年度のステータス一覧を保持する変数
    let currentYearStatuses = {};

    if (!yearSelect || !monthSelect) {
        console.warn('初期化に必要な要素が見つかりません。');
        return;
    }

    /**
     * 年度選択に応じて月セレクトボックスを更新する
     */
    function updateMonths() {
        const selectedYear = yearSelect.value;
        const availableMonths = yearMonthData[selectedYear] || [];

        // 月の選択肢リセット
        monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';
        monthSelect.disabled = true;

        if (availableMonths.length > 0) {
            // 4月始まりでソート
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

    /**
     * APIからステータスを取得し、月ボタンの表示と機能を更新する
     * @param {string} year 選択された年度
     */
    function updateCpStatusButtons(year) {
        // ボタンコンテナを初期化
        monthButtonsContainer.innerHTML = '';
        const startMonth = 4;

        // プレースホルダーとしてボタンを生成（非活性状態）
        for (let i = 0; i < 12; i++) {
            const month = ((startMonth + i - 1) % 12) + 1;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-secondary btn-sm me-1 mb-1';
            btn.disabled = true;
            btn.textContent = `${month}月`;
            btn.dataset.month = month; // 後で特定するためにデータ属性付与
            monthButtonsContainer.appendChild(btn);
        }

        if (!year) return;

        // ステータス取得API呼び出し
        // ※パスは実行されるHTML(cp_edit.php)からの相対パスになります。
        // forecastと同じ構成なら './get_cp_status.php' と想定されます。
        fetch(`./get_cp_status.php?year=${year}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(statusList => {
                // 取得したステータスをグローバル変数に保存（Excel出力等で使用）
                currentYearStatuses = statusList;

                // ボタンの状態を更新
                const buttons = monthButtonsContainer.querySelectorAll('button');
                buttons.forEach(btn => {
                    const month = parseInt(btn.dataset.month, 10);
                    const status = statusList[month] || 'none';

                    // ステータスに応じたクラス設定
                    btn.className = 'btn btn-sm me-1 mb-1'; // クラスリセット
                    if (status === 'fixed') {
                        btn.classList.add('btn-success');
                    } else if (status === 'draft' || status === 'registered') {
                        btn.classList.add('btn-primary');
                    } else {
                        btn.classList.add('btn-secondary');
                    }

                    // ステータスがある場合（登録済み）、ボタンを有効化してクリックイベントを設定
                    if (status !== 'none') {
                        btn.disabled = false;
                        btn.addEventListener('click', function () {
                            // 月セレクトボックスと連動
                            monthSelect.value = month;
                            // changeイベントを発火させて、詳細データの読み込み処理を実行させる
                            monthSelect.dispatchEvent(new Event('change'));
                        });
                    } else {
                        btn.disabled = true;
                    }
                });
            })
            .catch(err => {
                console.error('月ボタンステータス取得エラー:', err);
                currentYearStatuses = {}; // エラー時はリセット
            });
    }

    // ---------------------------------------------
    // --- イベントリスナー ---
    // ---------------------------------------------

    // 年度変更時
    yearSelect.addEventListener('change', function () {
        const selectedYear = this.value;
        updateMonths();
        updateCpStatusButtons(selectedYear);

        // URLパラメータ更新（リロード時の状態維持用）
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('year', selectedYear);
            url.searchParams.delete('month');
            window.history.replaceState({}, document.title, url.pathname + url.search);
        }
    });

    // ---------------------------------------------
    // --- 初期ロード処理 ---
    // ---------------------------------------------

    // ページロード時に年度が選択されていれば初期化を実行
    if (yearSelect.value) {
        const initialYear = yearSelect.value;
        updateMonths();
        updateCpStatusButtons(initialYear);

        // URLパラメータで月が指定されている場合、プルダウンに値をセット
        const urlParams = new URLSearchParams(window.location.search);
        const initialMonth = urlParams.get('month');
        // monthSelectのoptionは非同期ではなく同期的に生成しているので、ここでセット可能
        // ただし、updateMonths()内で option 生成しているため、即座にセットしてOK
        if (initialMonth && yearMonthData[initialYear] && yearMonthData[initialYear].includes(parseInt(initialMonth))) {
            monthSelect.value = initialMonth;
        }
    }

    // ---------------------------------------------
    // --- Excel集計ボタンの処理 ---
    // ---------------------------------------------
    const exportButtons = document.querySelectorAll('[data-export-type]');

    exportButtons.forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();

            const year = yearSelect.value;
            // 月の選択は必須ではなくなったため取得してもチェックには使わない

            // 1. 年度のみチェックに変更
            if (!year) {
                alert('年度を選択してください。');
                return;
            }

            // 2. ステータスチェック（未登録チェック・未確定確認）を削除
            // PHP側で「データがあれば出す、なければ0で出す」仕様になったため、
            // 特定の月のステータスでブロックする必要はありません。

            const type = button.getAttribute('data-export-type');
            let url = '';

            // 3. URLパラメータから month を削除
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