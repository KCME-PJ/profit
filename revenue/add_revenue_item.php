<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

try {
    // POSTデータを取得
    $revenue_category_id = trim($_POST['revenue_category_id'] ?? '');
    $office_id = trim($_POST['office_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $sort_order = (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') ? (int)$_POST['sort_order'] : 100;

    // 入力値の検証
    if (empty($name)) {
        throw new Exception('収入項目名は必須です。');
    }
    if (empty($revenue_category_id)) {
        throw new Exception('収入カテゴリは必須です。');
    }
    if (empty($office_id)) {
        throw new Exception('営業所は必須です。');
    }

    $dbh = getDb();

    // 項目名の重複チェック (同じ営業所内に同名の収入項目がないか)
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM revenue_items WHERE name = :name AND office_id = :office_id');
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':office_id', $office_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->fetchColumn() > 0) {
        throw new Exception('この営業所にはすでに同じ収入項目名が登録されています。');
    }

    // データの挿入
    $sql = 'INSERT INTO revenue_items (revenue_category_id, office_id, name, note, sort_order) 
            VALUES (:revenue_category_id, :office_id, :name, :note, :sort_order)';
    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':revenue_category_id', $revenue_category_id, PDO::PARAM_INT);
    $stmt->bindValue(':office_id', $office_id, PDO::PARAM_INT);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':note', $note, PDO::PARAM_STR);
    $stmt->bindValue(':sort_order', $sort_order, PDO::PARAM_INT);
    $stmt->execute();

    // ログ記録
    $newId = $dbh->lastInsertId();
    logAudit($dbh, 'revenue_item', $newId, 'create', [
        'msg' => 'New revenue item created',
        'name' => $name,
        'office_id' => $office_id,
        'revenue_category_id' => $revenue_category_id,
        'sort_order' => $sort_order
    ]);

    $_SESSION['success'] = '収入項目が正常に登録されました。';
    header('Location: revenue_item_list.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: revenue_item.php');
    exit;
}
