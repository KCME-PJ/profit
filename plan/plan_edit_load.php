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

    // 1. monthly_plan の ID, status, 共通賃率, updated_atを取得
    $stmt = $dbh->prepare("SELECT id, status, hourly_rate, updated_at FROM monthly_plan WHERE year = :year AND month = :month LIMIT 1");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    // 初期レスポンス構造
    $response = [
        'plan_id' => 0,
        'offices' => [],
        'details' => [],
        'revenues' => [],
        'status' => 'none',
        'common_hourly_rate' => 0,
        'updated_at' => '',
        'unfixed_offices' => [], // 未確定営業所リスト
        'all_fixed' => false     // 全営業所確定フラグ
    ];

    if ($plan) {
        $planId = (int)$plan['id'];
        $response['plan_id'] = $planId;
        $response['status'] = $plan['status'] ?? 'draft';
        $response['updated_at'] = $plan['updated_at'];
        $response['common_hourly_rate'] = (float)($plan['hourly_rate'] ?? 0);

        // 2. 営業所ごとの時間・人数データを取得
        $timeStmt = $dbh->prepare("SELECT * FROM monthly_plan_time WHERE monthly_plan_id = ?");
        $timeStmt->execute([$planId]);

        // ステータス判定用のマップを作成
        $officeStatusMap = [];

        while ($row = $timeStmt->fetch(PDO::FETCH_ASSOC)) {
            $officeId = $row['office_id'];
            $officeStatusMap[$officeId] = $row['status']; // draft or fixed

            $response['offices'][$officeId] = [
                'standard_hours'    => (float)($row['standard_hours'] ?? 0),
                'overtime_hours'    => (float)($row['overtime_hours'] ?? 0),
                'transferred_hours' => (float)($row['transferred_hours'] ?? 0),
                'fulltime_count'    => (int)($row['fulltime_count'] ?? 0),
                'contract_count'    => (int)($row['contract_count'] ?? 0),
                'dispatch_count'    => (int)($row['dispatch_count'] ?? 0),
                // 賃率は親データから継承するが、子データとして持たせておく（JS側の計算用）
                'hourly_rate'       => $response['common_hourly_rate'],
                'status'            => $row['status']
            ];
        }

        // 3. 全営業所リストを取得し、未確定の営業所を特定する
        $officesStmt = $dbh->query("SELECT id, name FROM offices ORDER BY identifier ASC");
        $allOffices = $officesStmt->fetchAll(PDO::FETCH_ASSOC);

        $unfixedList = [];
        $fixedCount = 0;
        $totalOffices = count($allOffices);

        foreach ($allOffices as $office) {
            $oid = $office['id'];
            $currentStatus = $officeStatusMap[$oid] ?? 'none'; // データが無い場合は none 扱い

            // fixed 以外はすべて「未確定」とみなす
            if ($currentStatus !== 'fixed') {
                $unfixedList[] = $office['name'];
            } else {
                $fixedCount++;
            }
        }

        $response['unfixed_offices'] = $unfixedList;
        // 全営業所が存在し、かつ全て fixed なら true
        $response['all_fixed'] = ($fixedCount === $totalOffices && $totalOffices > 0);


        // 4. 詳細データ（経費）の取得
        $detailStmt = $dbh->prepare("SELECT detail_id, amount FROM monthly_plan_details WHERE plan_id = :plan_id");
        $detailStmt->execute(['plan_id' => $planId]);
        $detailsData = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
        $response['details'] = array_column($detailsData, 'amount', 'detail_id');

        // 5. 収入データ
        $revenueStmt = $dbh->prepare("SELECT revenue_item_id, amount FROM monthly_plan_revenues WHERE plan_id = :plan_id");
        $revenueStmt->execute(['plan_id' => $planId]);
        $revenueData = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);
        $response['revenues'] = array_column($revenueData, 'amount', 'revenue_item_id');
    } else {
        // データが存在しない場合
        $response['status'] = 'none';
        $response['common_hourly_rate'] = 0;
        $response['updated_at'] = '';

        // 全て未確定扱い
        $officesStmt = $dbh->query("SELECT name FROM offices ORDER BY identifier ASC");
        $response['unfixed_offices'] = $officesStmt->fetchAll(PDO::FETCH_COLUMN);
        $response['all_fixed'] = false;
    }

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
