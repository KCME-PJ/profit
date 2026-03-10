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

    // 1. monthly_cp の ID, status, 共通賃率, updated_atを取得
    $stmt = $dbh->prepare("SELECT id, status, hourly_rate, updated_at FROM monthly_cp WHERE year = :year AND month = :month LIMIT 1");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $cp = $stmt->fetch(PDO::FETCH_ASSOC);

    // 初期レスポンス構造
    $response = [
        'monthly_cp_id' => 0,
        'offices' => [],
        'details' => [],
        'revenues' => [],
        'status' => 'none',
        'common_hourly_rate' => 0,
        'updated_at' => '',
        'unfixed_offices' => [], // 未確定営業所リスト
        'all_fixed' => false     // 全営業所確定フラグ
    ];

    if ($cp) {
        $cpId = (int)$cp['id'];
        $response['monthly_cp_id'] = $cpId;
        $response['status'] = $cp['status'] ?? 'draft';
        $response['updated_at'] = $cp['updated_at'];
        $response['common_hourly_rate'] = (float)($cp['hourly_rate'] ?? 0);

        // 2. 営業所ごとの時間・人数データを取得
        $timeStmt = $dbh->prepare("SELECT * FROM monthly_cp_time WHERE monthly_cp_id = ?");
        $timeStmt->execute([$cpId]);

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


        // 4. 詳細データ（経費）
        $detailStmt = $dbh->prepare("SELECT detail_id, amount FROM monthly_cp_details WHERE monthly_cp_id = :monthly_cp_id");
        $detailStmt->execute(['monthly_cp_id' => $cpId]);
        $detailsData = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
        $response['details'] = array_column($detailsData, 'amount', 'detail_id');

        // 5. 収入データ
        $revenueStmt = $dbh->prepare("SELECT revenue_item_id, amount FROM monthly_cp_revenues WHERE monthly_cp_id = :monthly_cp_id");
        $revenueStmt->execute(['monthly_cp_id' => $cpId]);
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
