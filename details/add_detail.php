<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

try {
    // POSTデータを取得
    $account_id = trim($_POST['account_id']);
    $office_id = trim($_POST['office_id']);
    $name = trim($_POST['name'] ?? '');
    $identifier = trim($_POST['identifier'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $sort_order = (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') ? (int)$_POST['sort_order'] : 100;

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
    if (empty($office_id)) {
        throw new Exception('営業所は必須です。');
    }

    // データベース接続
    $dbh = getDb();

    // 1. 一意識別子(identifier)の重複チェック
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM details WHERE identifier = :identifier');
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('指定された一意識別子はすでに使用されています。');
    }

    // 2. 詳細名(name)の重複チェック
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM details WHERE name = :name AND office_id = :office_id');
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':office_id', $office_id, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('この営業所にはすでに同じ詳細名が登録されています。');
    }

    // データの挿入
    $stmt = $dbh->prepare('INSERT INTO details (account_id, office_id, name, identifier, note, sort_order) VALUES (:account_id, :office_id, :name, :identifier, :note, :sort_order)');
    $stmt->bindValue(':account_id', $account_id, PDO::PARAM_INT);
    $stmt->bindValue(':office_id', $office_id, PDO::PARAM_INT);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->bindValue(':note', $note, PDO::PARAM_STR);
    $stmt->bindValue(':sort_order', $sort_order, PDO::PARAM_INT);
    $stmt->execute();

    // ログ記録
    $newId = $dbh->lastInsertId();
    logAudit($dbh, 'detail', $newId, 'create', [
        'msg' => 'New detail created',
        'name' => $name,
        'identifier' => $identifier,
        'office_id' => $office_id,
        'account_id' => $account_id,
        'sort_order' => $sort_order
    ]);

    $_SESSION['success'] = '正常に登録されました。';
    header('Location: detail.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: detail.php');
    exit;
}
