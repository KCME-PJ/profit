<?php
// statistics/get_available_months.php

require_once __DIR__ . '/../includes/database.php';
header('Content-Type: application/json');

try {
    $dbh = getDb();

    // データが存在する年月を統合して取得
    $sql = "
        SELECT year, month FROM monthly_cp WHERE status = 'fixed'
        UNION
        SELECT year, month FROM monthly_plan WHERE status = 'fixed'
        UNION
        SELECT year, month FROM monthly_result WHERE status = 'fixed'
    ";

    $stmt = $dbh->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- ソートロジック: 4月始まりの昇順 ---
    usort($rows, function ($a, $b) {
        $yA = (int)$a['year'];
        $mA = (int)$a['month'];
        $yB = (int)$b['year'];
        $mB = (int)$b['month'];

        // 1. まず「年度(year)」で昇順比較
        // DBに格納されている year が既に「年度」であるため、そのまま比較します
        if ($yA !== $yB) {
            return $yA - $yB;
        }

        // 2. 同じ年度内なら、月を「4月起点」で比較
        // 4月=4 ... 12月=12, 1月=13, 2月=14, 3月=15 としてスコア化
        $scoreA = ($mA <= 3) ? $mA + 12 : $mA;
        $scoreB = ($mB <= 3) ? $mB + 12 : $mB;

        return $scoreA - $scoreB;
    });

    // JSON形式に整形
    $availableMonths = [];
    foreach ($rows as $r) {
        $availableMonths[] = [
            // 検索用: DBの値そのまま (analysis.phpで分解してそのまま検索に使います)
            'value' => sprintf('%04d-%02d', $r['year'], $r['month']),

            // 表示用: 「年度」であることを明示する場合
            // DB上の「2025年1月」は、実際は2026年1月ですが、データ通り表示します
            'text' => sprintf('%d年%d月', $r['year'], $r['month'])
        ];
    }

    echo json_encode(['months' => $availableMonths]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
