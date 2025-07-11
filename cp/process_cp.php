<?php
require_once '../includes/database.php';
require_once '../includes/cp_functions.php';

try {
    // データ取得（POST）
    $data = $_POST;

    // 登録処理
    registerMonthlyCp($data);

    // 成功後にリダイレクト
    header("Location: cp.php?success=1");
    exit;
} catch (Exception $e) {
    // エラー発生時はエラーメッセージをURLに含めてリダイレクト
    $error = urlencode($e->getMessage());
    header("Location: cp.php?error=" . $error);
    exit;
}
