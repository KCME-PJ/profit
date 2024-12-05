<?php
require_once '../includes/database.php';

try {
    // DB接続
    $dbh = getDb();

    // POSTデータの受け取り
    $id = $_POST['id'] ?? null;

    // IDがない場合はエラー
    if (empty($id)) {
        throw new Exception('削除対象のIDが指定されていません。');
    }

    // 削除前の存在確認
    $stmt = $dbh->prepare('SELECT id FROM details WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $detail = $stmt->fetch();

    if (!$detail) {
        throw new Exception('削除対象の詳細が見つかりません。');
    }

    // 削除処理
    $stmt = $dbh->prepare('DELETE FROM details WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // 成功メッセージをセッションに保存
    session_start();
    $_SESSION['success'] = '詳細が削除されました。';
    header('Location: detail_list.php');
    exit;
} catch (Exception $e) {
    // エラーメッセージをセッションに保存
    session_start();
    $_SESSION['error'] = $e->getMessage();
    header('Location: detail_list.php');
    exit;
}
