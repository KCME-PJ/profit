<?php
require_once '../includes/database.php';
require_once 'validate_account.php';
require_once '../includes/logger.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // バリデーションチェック
    $errors = validateAccountData($_POST);

    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
        $_SESSION['form_inputs'] = $_POST;
        header('Location: account.php');
        exit;
    }

    // フォームデータの取得
    $account_name = trim($_POST['account_name']);
    $account_identifier = trim($_POST['account_identifier']);
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    $sort_order = (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') ? (int)$_POST['sort_order'] : 100;

    try {
        $db = getDb();

        // 重複チェック
        $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE name = :name");
        $stmt->bindValue(':name', $account_name, PDO::PARAM_STR);
        $stmt->execute();
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $_SESSION['error'] = '同じ勘定科目名が既に登録されています。';
            header('Location: account.php');
            exit;
        }

        $stmt = $db->prepare("INSERT INTO accounts (name, identifier, note, sort_order) VALUES (:name, :identifier, :note, :sort_order)");
        $stmt->bindValue(':name', $account_name, PDO::PARAM_STR);
        $stmt->bindValue(':identifier', $account_identifier, PDO::PARAM_STR);
        $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        $stmt->bindValue(':sort_order', $sort_order, PDO::PARAM_INT);
        $stmt->execute();

        // ログ記録
        $newId = $db->lastInsertId();
        logAudit($db, 'account', $newId, 'create', [
            'msg' => 'New account created',
            'name' => $account_name,
            'identifier' => $account_identifier,
            'sort_order' => $sort_order
        ]);

        $_SESSION['success'] = '勘定科目を登録しました。';
        header('Location: account_list.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'データベースエラーが発生しました: ' . $e->getMessage();
        header('Location: account.php');
        exit;
    }
}
