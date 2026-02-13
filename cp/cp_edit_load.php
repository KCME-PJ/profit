<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

// ブラウザキャッシュを無効化するヘッダー
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

try {
    $dbh = getDb();

    // パラメータの取得とバリデーション
    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
    $month = isset($_GET['month']) ? (int)$_GET['month'] : null;

    if (!$year || !$month) {
        http_response_code(400);
        throw new Exception("パラメータが不足しています。");
    }

    // 1. monthly_cp の ID, status, updated_at を取得
    // 重複時は最新のIDを優先
    $stmt = $dbh->prepare("SELECT id, status, updated_at FROM monthly_cp WHERE year = :year AND month = :month ORDER BY id DESC LIMIT 1");
    $stmt->execute(['year' => $year, 'month' => $month]);
    $cp = $stmt->fetch(PDO::FETCH_ASSOC);

    // 初期レスポンス構造
    $response = [
        'monthly_cp_id' => 0,
        'offices' => [],
        'details' => [],
        'revenues' => [],
        'status' => 'none',
        'updated_at' => '',
        'common_hourly_rate' => 0,
        // 月次締め用データ
        'unfixed_offices' => [],
        'all_fixed' => false
    ];

    if ($cp) {
        $cpId = (int)$cp['id'];
        $response['monthly_cp_id'] = $cpId;
        $response['status'] = $cp['status'] ?? 'draft';
        $response['updated_at'] = $cp['updated_at'];

        // --------------------------------------------------------
        // 0より大きい有効な賃率を「更新日時が新しい順」に1件取得する
        // --------------------------------------------------------
        $rateStmt = $dbh->prepare("
            SELECT hourly_rate 
            FROM monthly_cp_time 
            WHERE monthly_cp_id = ? 
              AND type = 'cp' 
              AND hourly_rate > 0 
            ORDER BY updated_at DESC 
            LIMIT 1
        ");
        $rateStmt->execute([$cpId]);

        // 取得できなければ 0
        $common_hourly_rate = (float)($rateStmt->fetchColumn() ?: 0);
        $response['common_hourly_rate'] = $common_hourly_rate;

        // --------------------------------------------------------
        // 2. 営業所ごとの時間・人数データを全件取得 & ステータス確認
        // --------------------------------------------------------
        $timeStmt = $dbh->prepare("SELECT * FROM monthly_cp_time WHERE monthly_cp_id = ? AND type = 'cp'");
        $timeStmt->execute([$cpId]);

        // 提出済みデータのステータスマップを作成 [office_id => status]
        $submittedStatuses = [];

        while ($row = $timeStmt->fetch(PDO::FETCH_ASSOC)) {
            $officeId = $row['office_id'];
            $submittedStatuses[$officeId] = $row['status']; // statusを記録

            $response['offices'][$officeId] = [
                'standard_hours'    => (float)($row['standard_hours'] ?? 0),
                'overtime_hours'    => (float)($row['overtime_hours'] ?? 0),
                'transferred_hours' => (float)($row['transferred_hours'] ?? 0),
                'fulltime_count'    => (int)($row['fulltime_count'] ?? 0),
                'contract_count'    => (int)($row['contract_count'] ?? 0),
                'dispatch_count'    => (int)($row['dispatch_count'] ?? 0),
                'hourly_rate'       => $common_hourly_rate,
                'status'            => $row['status']
            ];
        }

        // --------------------------------------------------------
        // 未確定営業所の集計
        // --------------------------------------------------------
        // 全営業所マスタを取得
        $officesStmt = $dbh->query("SELECT id, name FROM offices ORDER BY identifier ASC");
        $allOffices = $officesStmt->fetchAll(PDO::FETCH_ASSOC);

        $unfixedList = [];
        foreach ($allOffices as $office) {
            $oid = $office['id'];
            // データが存在しない OR ステータスが 'fixed' でない場合
            if (!isset($submittedStatuses[$oid]) || $submittedStatuses[$oid] !== 'fixed') {
                $unfixedList[] = $office['name'];
            }
        }

        $response['unfixed_offices'] = $unfixedList;
        $response['all_fixed'] = empty($unfixedList); // 空なら全確定

        // --------------------------------------------------------
        // 3. 詳細データ（経費）の取得
        // --------------------------------------------------------
        $detailStmt = $dbh->prepare("
            SELECT detail_id, amount
            FROM monthly_cp_details
            WHERE monthly_cp_id = :cp_id AND type = 'cp'
        ");
        $detailStmt->execute(['cp_id' => $cpId]);
        $detailsData = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
        $response['details'] = array_column($detailsData, 'amount', 'detail_id');

        // --------------------------------------------------------
        // 4. 収入データ
        // --------------------------------------------------------
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
        $response['updated_at'] = '';
        $response['unfixed_offices'] = []; // 親がない＝全員未確定だが、リストは空で返す（UI側で制御）
        $response['all_fixed'] = false;
    }

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
