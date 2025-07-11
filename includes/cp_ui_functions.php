<?php
require_once 'database.php';

/**
 * 指定された年度における各月のCPデータ状態を返す
 * - "none"：未登録
 * - "draft"：登録済（未確定）
 * - "fixed"：確定済
 *
 * @param int $year 対象の年
 * @param PDO|null $dbh DBハンドル（省略可）
 * @return array [1 => 'none', 2 => 'draft', ..., 12 => 'fixed']
 */
function getCpStatusByYear($year, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    // 初期化（1～12月を全て "none" に）
    $statusList = array_fill(1, 12, 'none');

    // 月ごとの status を取得
    $stmt = $dbh->prepare("SELECT month, status FROM monthly_cp WHERE year = ?");
    $stmt->execute([$year]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $month = (int)$row['month'];
        $status = $row['status'] ?? 'draft';
        if (!in_array($status, ['fixed', 'draft'])) {
            $status = 'draft'; // 未確定でも何か入っていれば draft 扱い
        }
        $statusList[$month] = $status;
    }

    return $statusList;
}

/**
 * 登録済の年度一覧 + 現在年度 + 翌年度 を取得
 *
 * @param PDO|null $dbh
 * @return int[] 年度の配列（昇順）
 */
function getAvailableCpYears($dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $stmt = $dbh->query("SELECT DISTINCT year FROM monthly_cp ORDER BY year ASC");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $currentYear = (int)date('Y');
    $nextYear = $currentYear + 1;

    if (!in_array($currentYear, $years)) {
        $years[] = $currentYear;
    }
    if (!in_array($nextYear, $years)) {
        $years[] = $nextYear;
    }

    sort($years);
    return $years;
}
