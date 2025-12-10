<?php
session_start();
require_once '../includes/database.php';

try {
    // DB接続
    $dbh = getDb();

    // POSTデータの受け取り
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['office_name'] ?? '');
    $rawIdentifier = trim($_POST['office_identifier'] ?? ''); // 元の入力値
    $note = trim($_POST['note'] ?? '');

    // -----------------------------------------------------
    // 1. 自動変換処理
    // -----------------------------------------------------
    // 全角英数 → 半角英数 ('r':英字, 'n':数字, 's':スペース)
    $identifier = mb_convert_kana($rawIdentifier, 'rns', 'UTF-8');

    // 小文字 → 大文字
    $identifier = strtoupper($identifier);

    // -----------------------------------------------------
    // 2. バリデーション
    // -----------------------------------------------------
    if (empty($id)) {
        throw new Exception('IDが指定されていません。');
    }
    if (empty($name)) {
        throw new Exception('営業所名は必須です。');
    }
    if (empty($identifier)) {
        throw new Exception('営業所コードは必須です。');
    }

    // ★修正: 半角英字(A-Z)と数字(0-9)のみ許可 (記号・スペース禁止)
    if (!preg_match('/^[A-Z0-9]+$/', $identifier)) {
        throw new Exception('コードに使用できない文字が含まれています。記号（ハイフン、アンダースコア、スペース等）は使用できません。');
    }

    // -----------------------------------------------------
    // 3. 重複チェック
    // -----------------------------------------------------
    $stmt = $dbh->prepare('SELECT id FROM offices WHERE identifier = :identifier AND id != :id');
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->fetch()) {
        throw new Exception('このコード（' . htmlspecialchars($identifier) . '）は既に使用されています。');
    }

    // -----------------------------------------------------
    // 4. データの更新
    // -----------------------------------------------------
    $sql = "UPDATE offices 
            SET name = :name, identifier = :identifier, note = :note 
            WHERE id = :id";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':name' => $name,
        ':identifier' => $identifier,
        ':note' => $note,
        ':id' => $id
    ]);

    // 成功メッセージをセッションに保存してリダイレクト
    $_SESSION['success'] = '営業所情報が正常に更新されました。';
    header('Location: office_list.php');
    exit;
} catch (Exception $e) {
    // エラーメッセージをセッションに保存してリダイレクト
    $_SESSION['error'] = $e->getMessage();
    header('Location: office_list.php');
    exit;
}
