<?php
session_start();
require_once '../includes/database.php';

try {
    // DB接続
    $dbh = getDb();

    // POSTデータの受け取り
    $id = $_POST['id'] ?? null;
    $office_name = trim($_POST['office_name'] ?? '');
    $office_identifier = trim($_POST['office_identifier'] ?? '');
    $note = trim($_POST['note'] ?? '');

    // バリデーション
    if (empty($id) || empty($office_name) || empty($office_identifier)) {
        throw new Exception('入力データに不備があります。');
    }

    // 一意識別子の形式チェック
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $office_identifier)) {
        throw new Exception('一意識別子には、半角英数字、ハイフン、アンダースコアのみ使用可能です。');
    }

    // 一意識別子の重複チェック
    $stmt = $dbh->prepare('SELECT id FROM offices WHERE identifier = :identifier AND id != :id');
    $stmt->execute([':identifier' => $office_identifier, ':id' => $id]);
    if ($stmt->fetch()) {
        throw new Exception('一意識別子が既に使用されています。');
    }

    // データの更新
    $sql = "UPDATE offices 
            SET name = :name, identifier = :identifier, note = :note 
            WHERE id = :id";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':name' => $office_name,
        ':identifier' => $office_identifier,
        ':note' => $note,
        ':id' => $id
    ]);

    // 成功メッセージをセッションに保存してリダイレクト
    $_SESSION['success'] = '営業所情報が更新されました。';
    header('Location: office_list.php');
    exit;
} catch (Exception $e) {
    // エラーメッセージをセッションに保存してリダイレクト
    $_SESSION['error'] = $e->getMessage();
    header('Location: office_list.php');
    exit;
}
