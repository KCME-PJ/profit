<?php
require_once '../includes/database.php'; // DB接続ファイル

session_start();

// POSTデータが送信されているか確認
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // フォームデータの取得
    $account_name = $_POST['account_name'];
    $account_identifier = $_POST['account_identifier'];
    $note = $_POST['note'] ?? '';  // 説明が省略されている場合もあるのでnull合体演算子を使用

    // 勘定科目名の重複チェック
    try {
        $db = getDb();
        $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE name = :name");
        $stmt->bindValue(':name', $account_name, PDO::PARAM_STR);
        $stmt->execute();
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $_SESSION['error'] = '同じ勘定科目名が既に登録されています。';
            header('Location: account.php');  // 元のフォームにリダイレクト
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'データベースエラーが発生しました: ' . $e->getMessage();
        header('Location: account.php');
        exit;
    }

    // データベースに新しい勘定科目を追加
    try {
        $stmt = $db->prepare("INSERT INTO accounts (name, identifier, note) VALUES (:name, :identifier, :note)");
        $stmt->bindValue(':name', $account_name, PDO::PARAM_STR);
        $stmt->bindValue(':identifier', $account_identifier, PDO::PARAM_STR);
        $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        $stmt->execute();


        header('Location: account_list.php');  // 登録後はリストページにリダイレクト
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = '登録エラー: ' . $e->getMessage();
        header('Location: account.php');
        exit;
    }
}
