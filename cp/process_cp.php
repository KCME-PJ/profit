<?php
require_once '../includes/database.php';
require_once '../includes/cp_functions.php';

try {
    // フォーム POST データを取得
    $data = $_POST;

    // 登録処理
    registerMonthlyCp($data);

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
    $error = urlencode($e->getMessage());
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
