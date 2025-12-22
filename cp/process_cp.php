<?php
require_once '../includes/database.php';
require_once '../includes/cp_functions.php';

try {
    // フォーム POST データを取得
    $data = $_POST;

    // ★追加: bulkJsonData が存在する場合はデコードしてマージする
    // cp.php のJSで全ての入力値をまとめて送信しているので、これを優先して使用する
    if (!empty($_POST['bulkJsonData'])) {
        $bulkData = json_decode($_POST['bulkJsonData'], true);

        if (is_array($bulkData)) {
            // 収入データの取得
            if (isset($bulkData['revenues']) && is_array($bulkData['revenues'])) {
                $data['revenues'] = $bulkData['revenues'];
            }
            // 経費データの取得
            // (cp.php のJSでは 'accounts' というキーで作成)
            if (isset($bulkData['accounts']) && is_array($bulkData['accounts'])) {
                $data['accounts'] = $bulkData['accounts'];
            }
        }
    }

    // 登録処理
    // registerMonthlyCp関数は $data['revenues'], $data['accounts'] を参照する仕様
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
