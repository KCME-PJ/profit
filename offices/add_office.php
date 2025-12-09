<?php
session_start();
require_once '../includes/database.php';

try {
    // POSTデータを取得
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
    // 2. 入力値の検証 (厳格チェック)
    // -----------------------------------------------------
    if (empty($name)) {
        throw new Exception('事業所名は必須です。');
    }
    if (empty($identifier)) {
        throw new Exception('コードは必須です。');
    }

    // ハイフン(-)、アンダースコア(_)、スペースを許可しない
    // 許可するのは「半角英字(A-Z)」と「数字(0-9)」のみ
    if (!preg_match('/^[A-Z0-9]+$/', $identifier)) {
        throw new Exception('コードに使用できない文字が含まれています。記号（ハイフン、アンダースコア、スペース等）は使用できません。');
    }

    // データベース接続
    $dbh = getDb();

    // -----------------------------------------------------
    // 重複チェック
    // -----------------------------------------------------
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM offices WHERE identifier = :identifier');
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('このコード（' . htmlspecialchars($identifier) . '）は既に使用されています。');
    }

    // データの挿入
    $stmt = $dbh->prepare('INSERT INTO offices (name, identifier, note) VALUES (:name, :identifier, :note)');
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $stmt->bindValue(':note', $note, PDO::PARAM_STR);
    $stmt->execute();

    $_SESSION['success'] = '事業所が正常に登録されました。';
    header('Location: office_list.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: office.php');
    exit;
}
