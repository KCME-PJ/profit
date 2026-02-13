<?php
require_once 'database.php';

/**
 * 指定された年度・営業所における各月のCPデータ状態を返す
 * * @param int $year 対象の年
 * @param PDO $dbh DBハンドル
 * @param int $officeId 対象営業所ID (0の場合は全社または未指定)
 * @return array [1 => 'none', 2 => 'draft', ..., 12 => 'fixed']
 */
function getCpStatusByYear(int $year, $dbh, int $officeId = 0)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    // 初期化（1～12月を全て "none" に）
    $statusList = [];
    for ($i = 1; $i <= 12; $i++) {
        $statusList[$i] = 'none';
    }

    if ($year <= 0) {
        return $statusList;
    }

    // -----------------------------------------------------
    // ステータスの取得ロジック
    // -----------------------------------------------------
    if ($officeId > 0) {
        // 特定の営業所が指定されている場合
        // 親(monthly_cp)と子(monthly_cp_time)を結合して取得
        $sql = "
            SELECT 
                m.month, 
                t.status 
            FROM monthly_cp m
            INNER JOIN monthly_cp_time t ON m.id = t.monthly_cp_id
            WHERE m.year = ? 
            AND t.office_id = ?
        ";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([$year, $officeId]);
    } else {
        $sql = "SELECT month, status FROM monthly_cp WHERE year = ?";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([$year]);
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $month = (int)$row['month'];
        $status = $row['status'] ?? 'draft';

        // draft でも fixed でもない謎の値が入っていた場合のガード
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
    return $years; // array_uniqueは不要（in_arrayチェック済み）
}
