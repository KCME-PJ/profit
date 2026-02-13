<?php
// セッションが開始されていなければ開始
if (session_status() === PHP_SESSION_NONE) {
    // セッションの有効期限設定などをここに入れることも可能
    session_start();
}

// ログイン判定
if (!isset($_SESSION['user_id'])) {

    // 未ログインの場合、ログイン画面へリダイレクト
    header("Location: /profit/login.php");
    exit;
}

// ログイン済みユーザー情報の展開（グローバル利用用）
$current_user_id   = $_SESSION['user_id'];
$current_office_id = $_SESSION['office_id'] ?? null;
$current_role      = $_SESSION['role'] ?? 'viewer';
$current_name      = $_SESSION['display_name'] ?? 'Guest';
