<?php
require_once '../includes/database.php';

header('Content-Type: application/json');

try {
    $dbh = getDb();

    // パラメータの取得とバリデーション
    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
    $month = isset($_GET['month']) ? (int)$_GET['month'] : null;

    if (!$year || !$month) {
        http_response_code(400);
        throw new Exception("パラメータが不足しています。");
    }

    // 1. monthly_plan の ID, status, 共通賃率を取得
    $stmt = $dbh->prepare("SELECT id, status, hourly_rate FROM monthly_plan WHERE year = :year AND month = :month LIMIT 1");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    // 初期レスポンス構造を定義
    $response = [
        'plan_id' => 0,
        'offices' => [], // 営業所ごとの時間データ格納キー
        'details' => [],
        'revenues' => [], // ★ 修正: 'revenues' を追加
        'status' => 'none',
        'common_hourly_rate' => 0
    ];

    if ($plan) {
        $planId = (int)$plan['id'];
        $response['plan_id'] = $planId;
        $response['status'] = $plan['status'] ?? 'draft';

        // 共通賃率をレスポンスのルートに追加
        $common_hourly_rate = (float)($plan['hourly_rate'] ?? 0);
        $response['common_hourly_rate'] = $common_hourly_rate;

        // 2. 営業所ごとの時間・人数データを全件取得
        $timeStmt = $dbh->prepare("SELECT * FROM monthly_plan_time WHERE monthly_plan_id = ?");
        $timeStmt->execute([$planId]);

        while ($row = $timeStmt->fetch(PDO::FETCH_ASSOC)) {
            $officeId = $row['office_id'];
            $response['offices'][$officeId] = [
                'standard_hours'    => (float)($row['standard_hours'] ?? 0),
                'overtime_hours'    => (float)($row['overtime_hours'] ?? 0),
                'transferred_hours' => (float)($row['transferred_hours'] ?? 0),
                'fulltime_count'    => (int)($row['fulltime_count'] ?? 0),
                'contract_count'    => (int)($row['contract_count'] ?? 0),
                'dispatch_count'    => (int)($row['dispatch_count'] ?? 0),
                // 賃率は親から取得した共通値を格納
                'hourly_rate'       => $common_hourly_rate,
            ];
        }

        // 3. 詳細データ（detail_id ⇒ amount）の取得
        $detailStmt = $dbh->prepare("
            SELECT detail_id, amount
            FROM monthly_plan_details
            WHERE plan_id = :plan_id
        ");
        $detailStmt->execute(['plan_id' => $planId]);
        $detailsData = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        $response['details'] = array_column($detailsData, 'amount', 'detail_id');

        // ★ 修正: 4. 収入データ (revenue_item_id ⇒ amount) の取得 (forecast_edit_load.php L84-L94 をコピー・修正)
        $revenueStmt = $dbh->prepare("
            SELECT revenue_item_id, amount
            FROM monthly_plan_revenues
            WHERE plan_id = :plan_id
        ");
        $revenueStmt->execute(['plan_id' => $planId]);
        $revenueData = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);
        $response['revenues'] = array_column($revenueData, 'amount', 'revenue_item_id');
        // (ここまで追加)

    } else {
        // データが存在しない場合
        $response['status'] = 'none';
        $response['common_hourly_rate'] = 0;
    }

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
