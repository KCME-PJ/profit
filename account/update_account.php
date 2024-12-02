<?php
session_start();
require_once '../includes/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['account_id'] ?? null;
    $name = $_POST['account_name'] ?? null;
    $identifier = $_POST['account_identifier'] ?? null;
    $note = $_POST['description'] ?? null;

    if (!$id || !$name || !$identifier) {
        $_SESSION['error'] = '必要なデータが不足しています。';
        header('Location: account_list.php');
        exit;
    }

    try {
        $db = getDb();

        // 重複チェック
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
