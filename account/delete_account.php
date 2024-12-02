<?php
session_start();
require_once '../includes/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);

    if ($accountId === null || $accountId === false) {
        $_SESSION['error'] = '無効な勘定科目IDです。';
        header('Location: account_list.php');
        exit;
    }

    try {
        $db = getDb();
        $stmt = $db->prepare('DELETE FROM accounts WHERE id = :id');
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['success'] = '勘定科目が正常に削除されました。';
    } catch (PDOException $e) {
        $_SESSION['error'] = '削除処理中にエラーが発生しました: ' . $e->getMessage();
    }

    header('Location: account_list.php');
    exit;
}
