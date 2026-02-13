<?php
session_start();
require_once '../includes/database.php';
require_once 'validate_account.php';
require_once '../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // バリデーションチェック
    $errors = validateAccountData($_POST);

    if (empty($_POST['account_id'])) {
        $errors['account_id'] = '更新対象のIDが指定されていません。';
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
        header('Location: account_list.php');
        exit;
    }

    // データ取得
    $id = $_POST['account_id'];
    $name = trim($_POST['account_name']);
    $identifier = trim($_POST['account_identifier']);
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    $sort_order = (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') ? (int)$_POST['sort_order'] : 100;

    try {
        $db = getDb();

        // 重複チェック (更新用)
        $sqlCheck = "SELECT COUNT(*) FROM accounts WHERE name = :name AND id != :id";
        $stmt = $db->prepare($sqlCheck);
        $stmt->execute([':name' => $name, ':id' => $id]);

        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'この勘定科目名はすでに存在しています。';
            header('Location: account_list.php');
            exit;
        }

        $sql = "UPDATE accounts SET name = :name, identifier = :identifier, note = :note, sort_order = :sort_order WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':identifier' => $identifier,
            ':note' => $note,
            ':sort_order' => $sort_order,
            ':id' => $id,
        ]);

        // ログ記録
        logAudit($db, 'account', $id, 'update', [
            'msg' => 'Account updated',
            'name' => $name,
            'identifier' => $identifier,
            'sort_order' => $sort_order
        ]);

        $_SESSION['success'] = '勘定科目が更新されました。';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'エラー: ' . $e->getMessage();
    }

    header('Location: account_list.php');
    exit;
}
