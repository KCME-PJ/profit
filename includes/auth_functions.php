<?php

/**
 * 認証関連の共通関数
 */

/**
 * 現在のパスワードが正しいか検証する
 * * @param PDO $pdo データベース接続オブジェクト
 * @param int $user_id ユーザーID
 * @param string $input_password 入力された平文パスワード
 * @return bool 検証成功ならtrue
 */
function verify_current_password($pdo, $user_id, $input_password)
{
    // ユーザー情報を取得
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false; // ユーザーが存在しない、または無効
    }

    // ハッシュ化されたパスワードと照合
    return password_verify($input_password, $user['password']);
}
