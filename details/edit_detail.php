<?php
require_once '../includes/database.php';

try {
    $dbh = getDb();

    $id = $_POST['id'] ?? null;
    $detail_name = trim($_POST['detail_name'] ?? '');
    $detail_identifier = trim($_POST['detail_identifier'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $account_id = $_POST['account_id'] ?? null;

    if (empty($id) || empty($detail_name) || empty($detail_identifier) || empty($account_id)) {
        throw new Exception('入力データに不備があります。');
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $detail_identifier)) {
        throw new Exception('一意識別子には、半角英数字、ハイフン、アンダースコアのみ使用可能です。');
    }

    $stmt = $dbh->prepare('SELECT id FROM details WHERE identifier = :identifier AND id != :id');
    $stmt->execute([':identifier' => $detail_identifier, ':id' => $id]);
    if ($stmt->fetch()) {
        throw new Exception('一意識別子が既に使用されています。');
    }

    $sql = "UPDATE details 
            SET name = :name, identifier = :identifier, note = :note, account_id = :account_id 
            WHERE id = :id";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':name' => $detail_name,
        ':identifier' => $detail_identifier,
        ':note' => $note,
        ':account_id' => $account_id,
        ':id' => $id
    ]);

    session_start();
    $_SESSION['success'] = '詳細が更新されました。';
    header('Location: detail_list.php');
    exit;
} catch (Exception $e) {
    session_start();
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = [
        'id' => $id,
        'detail_name' => $detail_name,
        'detail_identifier' => $detail_identifier,
        'note' => $note,
        'account_id' => $account_id,
    ];
    header('Location: detail_list.php');
    exit;
}
