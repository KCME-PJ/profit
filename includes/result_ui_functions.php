<?php
require_once 'database.php';

/**
 * 指定された年度における各月の概算実績データ状態を返す
 * - "none"：未登録
 * - "draft"：登録済（未確定）
 * - "fixed"：確定済
 *
 * @param int $year 対象の年
 * @param PDO|null $dbh DBハンドル
 * @return array [1 => 'none', 2 => 'draft', ..., 12 => 'fixed']
 */
function getResultStatusByYear($year, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    // 初期化（1～12月を全て "none" に）
    $statusList = array_fill(1, 12, 'none');

    // 月ごとの status を取得
    $stmt = $dbh->prepare("SELECT month, status FROM monthly_result WHERE year = ?");
    $stmt->execute([$year]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $month = (int)$row['month'];
        $status = $row['status'] ?? 'draft';
        if (!in_array($status, ['fixed', 'draft'])) {
            // DBに予期せぬ値が入っていた場合も draft 扱い
            $status = 'draft';
        }
        $statusList[$month] = $status;
    }

    return $statusList;
}
