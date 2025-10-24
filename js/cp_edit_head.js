document.addEventListener('DOMContentLoaded', function () {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');

    if (!yearSelect || !monthSelect || !window.yearMonthData) {
        console.warn('初期化に必要な要素が見つかりません。');
        return;
    }

    yearSelect.addEventListener('change', function () {
        const selectedYear = this.value;
        const availableMonths = yearMonthData[selectedYear] || [];

        // 月の選択肢更新
        monthSelect.innerHTML = '<option value="" disabled selected>月を選択</option>';

        // 4月～3月の順にソート
        const sortedMonths = [...availableMonths].sort((a, b) => {
            const getSortValue = m => (m >= 4 ? m : m + 12); // 4〜12はそのまま、1〜3は13〜15として比較
            return getSortValue(a) - getSortValue(b);
        });

        sortedMonths.forEach(month => {
            const option = document.createElement('option');
            option.value = month;
            option.textContent = month + '月';
            monthSelect.appendChild(option);
        });

        // 月セレクト有効化
        monthSelect.disabled = false;

        // 月ボタンの色更新
        fetch(`../cp/get_cp_status.php?year=${selectedYear}`)
            .then(response => response.json())
            .then(statusList => {
                const container = document.getElementById('monthButtonsContainer');
                container.innerHTML = ''; // 一旦全削除
                const startMonth = 4;

                for (let i = 0; i < 12; i++) {
                    const month = ((startMonth + i - 1) % 12) + 1;
                    const status = statusList[month];
                    console.log(`${month}月: status=${status}`);
                    const colorClass =
                        status === 'fixed' ? 'success' :
                            status === 'draft' ? 'primary' :
                                'secondary';

                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `btn btn-${colorClass} btn-sm me-1 mb-1`;
                    btn.disabled = true;
                    btn.textContent = `${month}月`;
                    container.appendChild(btn);
                }
            })
            .catch(err => console.error('月ボタン取得エラー:', err));
    });

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
                url = `cp_export_excel.php?year=${year}&month=${month}`;
            } else if (type === 'details') {
                url = `cp_export_excel_details.php?year=${year}&month=${month}`;
            }
            window.location.href = url;
        });
    });
});