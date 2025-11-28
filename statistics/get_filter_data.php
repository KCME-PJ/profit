<?php
// statistics/get_filter_data.php
// 目的: 統計ダッシュボードのフィルター(年度、営業所)に表示する選択肢をDBから取得する

require_once __DIR__ . '/../includes/database.php'; // パスを修正
header('Content-Type: application/json');

try {
    $dbh = getDb();

    // 1. 存在する営業所リストを取得
    $stmtOffices = $dbh->query("SELECT id, name FROM offices ORDER BY id ASC");
    $offices = $stmtOffices->fetchAll(PDO::FETCH_ASSOC);

    // 2. データが存在する年度リストを取得 (全テーブルをスキャン)
    // (パフォーマンスより網羅性を優先)
    $sql = "
        (SELECT DISTINCT year FROM monthly_cp)
        UNION
        (SELECT DISTINCT year FROM monthly_forecast)
        UNION
        (SELECT DISTINCT year FROM monthly_plan)
        UNION
        (SELECT DISTINCT year FROM monthly_outlook)
        UNION
        (SELECT DISTINCT year FROM monthly_result)
        ORDER BY year DESC
    ";
    $stmtYears = $dbh->query($sql);
    $years = $stmtYears->fetchAll(PDO::FETCH_COLUMN, 0); // [2025, 2024, 2023] のような配列を取得

    // 3. JSONで出力
    echo json_encode([
        'years' => $years,
        'offices' => $offices
    ]);
} catch (Exception $e) {
    http_response_code(500); // サーバーエラー
    echo json_encode(['error' => 'フィルターデータの取得に失敗しました: ' . $e->getMessage()]);
    exit;
}
