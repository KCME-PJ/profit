<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);

    if ($accountId === null || $accountId === false) {
        $_SESSION['error'] = '無効な勘定科目IDです。';
        header('Location: account_list.php');
        exit;
    }

    try {
        $db = getDb();

        // 削除前に、この勘定科目が「詳細(details)」で使われているかチェック
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM details WHERE account_id = :id');
        $checkStmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $checkStmt->execute();

        if ($checkStmt->fetchColumn() > 0) {
            // 使われている場合はエラーにして戻す
            $_SESSION['error'] = 'この勘定科目は「詳細」で使用されているため削除できません。<br>先に紐付いている詳細データを削除または変更してください。';
            header('Location: account_list.php');
            exit;
        }

        // ログ用に削除前の情報を取得
        $nameStmt = $db->prepare("SELECT name, identifier FROM accounts WHERE id = :id");
        $nameStmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $nameStmt->execute();
        $accountInfo = $nameStmt->fetch(PDO::FETCH_ASSOC);

        // 使われていなければ削除実行
        $stmt = $db->prepare('DELETE FROM accounts WHERE id = :id');
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();

        // ログ記録
        if ($accountInfo) {
            logAudit($db, 'account', $accountId, 'delete', [
                'msg' => 'Account deleted',
                'name' => $accountInfo['name'],
                'identifier' => $accountInfo['identifier']
            ]);
        }

        $_SESSION['success'] = '勘定科目が正常に削除されました。';
    } catch (PDOException $e) {
        $_SESSION['error'] = '削除処理中にエラーが発生しました: ' . $e->getMessage();
    }

    header('Location: account_list.php');
    exit;
}
