<?php
require_once '../includes/database.php'; // DB接続ファイル
require_once 'validate_account.php';     // バリデーションファイルを読み込み

session_start();

// POSTデータが送信されているか確認
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // バリデーションチェックを実行
    // $_POST の内容（account_name, account_identifier, note）を渡してチェック
    $errors = validateAccountData($_POST);

    // エラーが1つでもあれば、登録処理を中断して戻る
    if (!empty($errors)) {
        // エラーメッセージ配列を改行区切りの文字列にしてセッションに保存
        $_SESSION['error'] = implode('<br>', $errors);

        // 入力値を保持するためにセッションに保存しておくと親切（任意）
        $_SESSION['form_inputs'] = $_POST;

        header('Location: account.php'); // 元のフォームにリダイレクト
        exit;
    }

    // --- ここから下はバリデーションを通過したデータのみが来る ---

    // フォームデータの取得（trimで前後の余分な空白を削除して取得）
    $account_name = trim($_POST['account_name']);
    $account_identifier = trim($_POST['account_identifier']);
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';

    try {
        $db = getDb();

        // 勘定科目名の重複チェック
        $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE name = :name");
        $stmt->bindValue(':name', $account_name, PDO::PARAM_STR);
        $stmt->execute();
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $_SESSION['error'] = '同じ勘定科目名が既に登録されています。';
            header('Location: account.php');  // 元のフォームにリダイレクト
            exit;
        }

        // データベースに新しい勘定科目を追加
        $stmt = $db->prepare("INSERT INTO accounts (name, identifier, note) VALUES (:name, :identifier, :note)");
        $stmt->bindValue(':name', $account_name, PDO::PARAM_STR);
        $stmt->bindValue(':identifier', $account_identifier, PDO::PARAM_STR);
        $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        $stmt->execute();

        $_SESSION['success'] = '勘定科目を登録しました。'; // 成功メッセージ
        header('Location: account_list.php');  // 登録後はリストページにリダイレクト
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'データベースエラーが発生しました: ' . $e->getMessage();
        header('Location: account.php');
        exit;
    }
}
