<?php
require_once __DIR__ . '/database.php';

/**
 * 指定された年度における各月のPlanデータ状態を返す
 *
 * @param int $year 対象の年
 * @param PDO|null $dbh DBハンドル
 * @param int|null $targetOfficeId 対象の営業所ID (Managerの場合に指定)
 * @return array [1 => 'none', 2 => 'draft', ..., 12 => 'fixed']
 */
function getPlanStatusByYear($year, $dbh = null, $targetOfficeId = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    // 初期化（1～12月を全て "none" に）
    $statusList = array_fill(1, 12, 'none');

    if ($targetOfficeId > 0) {
        // A. 特定の営業所: 親と子を結合して子ステータスを取得
        $sql = "
            SELECT 
                m.month, 
                t.status 
            FROM monthly_plan m
            INNER JOIN monthly_plan_time t ON m.id = t.monthly_plan_id 
            WHERE m.year = ? 
            AND t.office_id = ?
        ";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([$year, $targetOfficeId]);
    } else {
        // B. 全社 (AdminでAll選択時): 親テーブルのステータスをそのまま取得
        $sql = "SELECT month, status FROM monthly_plan WHERE year = ?";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([$year]);
    }

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
