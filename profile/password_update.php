<?php
session_start();

// 必要なファイルを読み込み
require_once '../includes/database.php';      // DB接続関数 getDb()
require_once '../includes/auth_check.php';    // ログイン認証確認
require_once '../includes/auth_functions.php'; // パスワード照合関数 verify_current_password()

// POSTリクエスト以外は編集画面へ強制リダイレクト
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: password_edit.php');
    exit;
}

// 1. 入力値の取得
$current_pass = $_POST['current_password'] ?? '';
$new_pass_raw = $_POST['new_password'] ?? '';
$confirm_pass_raw = $_POST['confirm_password'] ?? '';
$user_id = $_SESSION['user_id'];

// 2. 入力値のサニタイズ（全角英数字を半角に変換）
// 'a': 全角英数字を半角にする（スペースは変換せずそのまま残す→後のチェックで弾くため）
$new_pass = mb_convert_kana($new_pass_raw, 'a');
$confirm_pass = mb_convert_kana($confirm_pass_raw, 'a');

// 3. 文字種バリデーション（厳密チェック）
// \x21-\x7E : ASCII文字の「!」から「~」まで（半角英数字・半角記号すべて）
// ※スペース(\x20)や日本語、全角記号が含まれていると false になります
if (!preg_match('/^[\x21-\x7E]+$/', $new_pass)) {
    $_SESSION['error_msg'] = '使用できない文字（スペース、日本語、全角記号など）が含まれています。半角英数字・記号のみ使用可能です。';
    header('Location: password_edit.php');
    exit;
}

// 4. 基本バリデーション
if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
    $_SESSION['error_msg'] = 'すべての項目を入力してください。';
    header('Location: password_edit.php');
    exit;
}

if ($new_pass !== $confirm_pass) {
    $_SESSION['error_msg'] = '新しいパスワードと確認用パスワードが一致しません。';
    header('Location: password_edit.php');
    exit;
}

if (strlen($new_pass) < 8) {
    $_SESSION['error_msg'] = 'パスワードは8文字以上で設定してください。';
    header('Location: password_edit.php');
    exit;
}

try {
    // DB接続取得（関数版）
    $pdo = getDb();

    // 5. 現在のパスワードが正しいか検証
    if (!verify_current_password($pdo, $user_id, $current_pass)) {
        $_SESSION['error_msg'] = '現在のパスワードが間違っています。';
        header('Location: password_edit.php');
        exit;
    }

    // 6. トランザクション開始
    $pdo->beginTransaction();

    // パスワードのハッシュ化
    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);

    // usersテーブル更新
    $stmt = $pdo->prepare("UPDATE users SET password = :pwd, updated_at = NOW() WHERE id = :id");
    $stmt->execute([
        ':pwd' => $new_hash,
        ':id' => $user_id
    ]);

    // 7. 監査ログ(audit_logs)への記録
    // セキュリティ上、変更後のパスワード自体は記録しません
    $log_content = json_encode([
        'event' => 'password_change',
        'msg' => 'ユーザーによるパスワード変更実施',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ], JSON_UNESCAPED_UNICODE);

    $office_id = $_SESSION['office_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'];

    $logStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (user_id, office_id, phase, target_id, action, content, ip_address, created_at) 
        VALUES 
        (:uid, :oid, 'profile', :tid, 'update', :content, :ip, NOW())
    ");

    $logStmt->execute([
        ':uid' => $user_id,
        ':oid' => $office_id,
        ':tid' => $user_id,      // 対象ID（自分自身）
        ':content' => $log_content,
        ':ip' => $ip_address
    ]);

    // コミット
    $pdo->commit();

    // 成功メッセージをセット
    $_SESSION['success_msg'] = 'パスワードを正常に変更しました。';
    header('Location: password_edit.php');
    exit;
} catch (Exception $e) {
    // エラー発生時はロールバック
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // エラーメッセージをセット
    $_SESSION['error_msg'] = 'システムエラーが発生しました: ' . $e->getMessage();
    header('Location: password_edit.php');
    exit;
}
