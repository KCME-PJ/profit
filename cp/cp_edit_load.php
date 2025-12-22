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

    // 1. monthly_cp の ID, status を取得
    // CPは親テーブルに hourly_rate を持たず、子テーブル(monthly_cp_time)で持つ設計だが
    // ここでは代表として子テーブルから1つ取得するか、もしくは関数内で処理
    $stmt = $dbh->prepare("SELECT id, status FROM monthly_cp WHERE year = :year AND month = :month LIMIT 1");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $cp = $stmt->fetch(PDO::FETCH_ASSOC);

    // 初期レスポンス構造を定義
    $response = [
        'monthly_cp_id' => 0,
        'offices' => [], // 営業所ごとの時間データ格納キー
        'details' => [],
        'revenues' => [],
        'status' => 'none',
        'common_hourly_rate' => 0
    ];

    if ($cp) {
        $cpId = (int)$cp['id'];
        $response['monthly_cp_id'] = $cpId;
        $response['status'] = $cp['status'] ?? 'draft';

        // 共通賃率を取得 (monthly_cp_timeから代表値を取得)
        $rateStmt = $dbh->prepare("SELECT hourly_rate FROM monthly_cp_time WHERE monthly_cp_id = ? AND type = 'cp' LIMIT 1");
        $rateStmt->execute([$cpId]);
        $common_hourly_rate = (float)($rateStmt->fetchColumn() ?? 0);
        $response['common_hourly_rate'] = $common_hourly_rate;

        // 2. 営業所ごとの時間・人数データを全件取得
        $timeStmt = $dbh->prepare("SELECT * FROM monthly_cp_time WHERE monthly_cp_id = ? AND type = 'cp'");
        $timeStmt->execute([$cpId]);

        while ($row = $timeStmt->fetch(PDO::FETCH_ASSOC)) {
            $officeId = $row['office_id'];
            $response['offices'][$officeId] = [
                'standard_hours'    => (float)($row['standard_hours'] ?? 0),
                'overtime_hours'    => (float)($row['overtime_hours'] ?? 0),
                'transferred_hours' => (float)($row['transferred_hours'] ?? 0),
                'fulltime_count'    => (int)($row['fulltime_count'] ?? 0),
                'contract_count'    => (int)($row['contract_count'] ?? 0),
                'dispatch_count'    => (int)($row['dispatch_count'] ?? 0),
                'hourly_rate'       => $common_hourly_rate,
            ];
        }

        // 3. 詳細データ（経費）の取得
        $detailStmt = $dbh->prepare("
            SELECT detail_id, amount
            FROM monthly_cp_details
            WHERE monthly_cp_id = :cp_id AND type = 'cp'
        ");
        $detailStmt->execute(['cp_id' => $cpId]);
        $detailsData = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        $response['details'] = array_column($detailsData, 'amount', 'detail_id');

        // 4. 収入データ
        $revenueStmt = $dbh->prepare("
            SELECT revenue_item_id, amount
            FROM monthly_cp_revenues
            WHERE monthly_cp_id = :cp_id
        ");
        $revenueStmt->execute(['cp_id' => $cpId]);
        $revenueData = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);
        $response['revenues'] = array_column($revenueData, 'amount', 'revenue_item_id');
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
