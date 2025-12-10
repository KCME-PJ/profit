<?php
session_start();
require_once '../includes/database.php';
require_once 'validate_account.php'; // バリデーションファイルを読み込み

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // バリデーションチェック (共通関数を使用)
    // フォームの name 属性が (account_name, account_identifier, note) であることを前提とします
    $errors = validateAccountData($_POST);

    // IDが存在するかのチェックは validateAccountData には含まれないため、個別に確認
    if (empty($_POST['account_id'])) {
        $errors['account_id'] = '更新対象のIDが指定されていません。';
    }

    // エラーがあればリダイレクト
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
        header('Location: account_list.php');
        exit;
    }

    // バリデーション通過後のデータ取得
    // trim して取得
    $id = $_POST['account_id'];
    $name = trim($_POST['account_name']);
    $identifier = trim($_POST['account_identifier']);
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';

    try {
        $db = getDb();

        // 重複チェック (更新用)
        // 「自分以外のID」で「同じ名前」のものがあるかチェック
        $sqlCheck = "SELECT COUNT(*) FROM accounts WHERE name = :name AND id != :id";
        $stmt = $db->prepare($sqlCheck);
        $stmt->execute([':name' => $name, ':id' => $id]);

        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'この勘定科目名はすでに存在しています。';
            header('Location: account_list.php');
            exit;
        }

        // 更新処理
        $sql = "UPDATE accounts SET name = :name, identifier = :identifier, note = :note WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':identifier' => $identifier,
            ':note' => $note,
            ':id' => $id,
        ]);

        $_SESSION['success'] = '勘定科目が更新されました。';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'エラー: ' . $e->getMessage();
    }

    header('Location: account_list.php');
    exit;
}
