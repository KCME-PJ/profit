<?php
require_once 'database.php';

/**
 * 指定された年度における各月の見込みデータ状態を返す
 * - "none"：未登録
 * - "draft"：登録済（未確定）
 * - "fixed"：確定済
 *
 * @param int $year 対象の年
 * @param PDO|null $dbh DBハンドル
 * @return array [1 => 'none', 2 => 'draft', ..., 12 => 'fixed']
 */
function getOutlookStatusByYear($year, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $statusList = array_fill(1, 12, 'none');

    $stmt = $dbh->prepare("SELECT month, status FROM monthly_outlook WHERE year = ?");
    $stmt->execute([$year]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $month = (int)$row['month'];
        $status = $row['status'] ?? 'draft';
        if (!in_array($status, ['fixed', 'draft'])) {
            $status = 'draft';
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
function getAvailableOutlookYears($dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $stmt = $dbh->query("SELECT DISTINCT year FROM monthly_outlook ORDER BY year ASC");
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

/**
 * 指定年度の月ごとの「確定状況」（fixed or draft）を返す
 *
 * @param int $year
 * @param PDO $dbh
 * @return array
 */
function getOutlookFixedStatusByYear(int $year, PDO $dbh): array
{
    $statuses = array_fill(1, 12, 'draft');

    $stmt = $dbh->prepare("SELECT month, status FROM monthly_outlook WHERE year = :year");
    $stmt->execute([':year' => $year]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $month = (int)$row['month'];
        if ($row['status'] === 'fixed') {
            $statuses[$month] = 'fixed';
        }
    }

    return $statuses;
}
