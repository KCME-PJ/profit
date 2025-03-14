<?php
session_start();
require_once '../includes/database.php';

try {
    // POSTデータを取得
    $name = trim($_POST['office_name'] ?? '');
    $identifier = trim($_POST['office_identifier'] ?? '');
    $note = trim($_POST['note'] ?? '');

    // 入力値の検証
    if (empty($name)) {
        throw new Exception('事業所名は必須です。');
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
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM offices WHERE name = :name AND identifier = :identifier');
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('同じ事業所がすでに存在します。');
    }

    // データの挿入
    $stmt = $dbh->prepare('INSERT INTO offices (name, identifier, note) VALUES (:name, :identifier, :note)');
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->bindValue(':note', $note, PDO::PARAM_STR);
    $stmt->execute();

    $_SESSION['success'] = '事業所が正常に登録されました。';
    header('Location: office_list.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: office.php');
    exit;
}
