<?php
session_start();
require_once '../includes/database.php';

try {
    // DB接続
    $dbh = getDb();

    // POSTデータの受け取り
    $id = $_POST['id'] ?? null;

    // バリデーション: IDが存在しない場合
    if (empty($id)) {
        throw new Exception('削除対象が指定されていません。');
    }

    // 削除対象の営業所名を取得
    $stmt = $dbh->prepare('SELECT name FROM offices WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $office = $stmt->fetch(PDO::FETCH_ASSOC);

    // 該当レコードが存在しない場合
    if (!$office) {
        throw new Exception('指定された営業所が見つかりません。');
    }

    $officeName = $office['name']; // 削除対象の営業所名

    // データ削除
    $stmt = $dbh->prepare('DELETE FROM offices WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // 成功メッセージをセッションに保存してリダイレクト
    $_SESSION['success'] = htmlspecialchars($officeName) . ' が削除されました。';
    header('Location: office_list.php');
    exit;
} catch (Exception $e) {
    // エラーメッセージをセッションに保存してリダイレクト
    $_SESSION['error'] = $e->getMessage();
    header('Location: office_list.php');
    exit;
}
