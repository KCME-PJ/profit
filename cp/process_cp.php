<?php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';
require_once '../includes/cp_functions.php';

// ユーザーコンテキストの作成
$userContext = [
    'user_id'   => $_SESSION['user_id'] ?? 0,
    'office_id' => $_SESSION['office_id'] ?? null,
    'role'      => $_SESSION['role'] ?? 'viewer',
    'username'  => $_SESSION['username'] ?? 'unknown'
];

try {
    // フォーム POST データを取得
    $data = $_POST;

    // DB接続
    $dbh = getDb();

    // -----------------------------------------------------
    // JSONデータのデコード処理
    // -----------------------------------------------------

    // 1. 時間データ (officeTimeData)
    if (isset($data['officeTimeData']) && is_string($data['officeTimeData'])) {
        $data['officeTimeData'] = json_decode($data['officeTimeData'], true) ?? [];
    }

    // 2. 一括データ (bulkJsonData) のマージ
    if (!empty($_POST['bulkJsonData'])) {
        $bulkData = json_decode($_POST['bulkJsonData'], true);

        if (is_array($bulkData)) {
            // 収入データの取得
            if (isset($bulkData['revenues']) && is_array($bulkData['revenues'])) {
                $data['revenues'] = $bulkData['revenues'];
            }
            // 経費データの取得
            if (isset($bulkData['accounts']) && is_array($bulkData['accounts'])) {
                $data['amounts'] = $bulkData['accounts'];
            }
        }
    }

    // -----------------------------------------------------
    // 登録処理実行
    // -----------------------------------------------------
    // 第3引数に $userContext を渡すことで、Managerが他拠点を消す事故を防ぐ
    registerMonthlyCp($data, $dbh, $userContext);

    // 成功後に年度・月を付けてリダイレクト（選択を保持）
    $year  = isset($data['year'])  ? (int)$data['year']  : '';
    $month = isset($data['month']) ? (int)$data['month'] : '';

    $query = http_build_query([
        'success' => 1,
        'year'    => $year,
        'month'   => $month
    ]);

    header("Location: cp.php?{$query}");
    exit;
} catch (Exception $e) {
    // エラーメッセージをエンコードしてリダイレクト（選択を保持）
    $year  = isset($_POST['year'])  ? (int)$_POST['year']  : '';
    $month = isset($_POST['month']) ? (int)$_POST['month'] : '';
    $query = http_build_query([
        'error' => $e->getMessage(),
        'year'  => $year,
        'month' => $month
    ]);

    header("Location: cp.php?{$query}");
    exit;
}
