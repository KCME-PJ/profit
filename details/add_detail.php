<?php
session_start();
require_once '../includes/database.php';

try {
    // POSTデータを取得
    $account_id = trim($_POST['account_id']);
    $name = trim($_POST['name'] ?? '');
    $identifier = trim($_POST['identifier'] ?? '');
    $note = trim($_POST['note'] ?? '');

    // 入力値の検証
    if (empty($name)) {
        throw new Exception('詳細名は必須です。');
    }
    if (empty($identifier)) {
        throw new Exception('一意識別子は必須です。');
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
        throw new Exception('一意識別子は半角英数字、ハイフン、アンダースコアのみ使用できます。');
    }

    // データベース接続
    $dbh = getDb();

    // 重複チェック
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM details WHERE identifier = :identifier');
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('同じ一意識別子がすでに存在します。');
    }

    // データの挿入
    $stmt = $dbh->prepare('INSERT INTO details (account_id, name, identifier, note) VALUES (:account_id, :name, :identifier, :note)');
    $stmt->bindValue(':account_id', $account_id, PDO::PARAM_INT);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->bindValue(':note', $note, PDO::PARAM_STR);
    $stmt->execute();

    $_SESSION['success'] = '詳細が正常に登録されました。';
    header('Location: detail.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: detail.php');
    exit;
}
