<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

try {
    $dbh = getDb();

    // フォームデータの取得
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $revenue_category_id = $_POST['revenue_category_id'] ?? null;
    $office_id = $_POST['office_id'] ?? null;
    $note = trim($_POST['note'] ?? '');
    $sort_order = (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') ? (int)$_POST['sort_order'] : 100;

    // 入力チェック
    if (empty($id) || empty($name) || empty($revenue_category_id) || empty($office_id)) {
        throw new Exception('入力データに不備があります。');
    }

    // 重複チェック（同じ営業所内で、自分自身以外の同じ名前がないか）
    $stmt = $dbh->prepare('SELECT id FROM revenue_items WHERE name = :name AND office_id = :office_id AND id != :id');
    $stmt->execute([
        ':name' => $name,
        ':office_id' => $office_id,
        ':id' => $id
    ]);
    if ($stmt->fetch()) {
        throw new Exception('この営業所内ですでに同じ収入項目名が使用されています。');
    }

    // 更新処理
    $sql = "UPDATE revenue_items 
            SET name = :name, note = :note, revenue_category_id = :revenue_category_id, office_id = :office_id, sort_order = :sort_order
            WHERE id = :id";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':name' => $name,
        ':note' => $note,
        ':revenue_category_id' => $revenue_category_id,
        ':office_id' => $office_id,
        ':sort_order' => $sort_order,
        ':id' => $id
    ]);

    // ログ記録
    logAudit($dbh, 'revenue_item', $id, 'update', [
        'msg' => 'Revenue item updated',
        'name' => $name,
        'office_id' => $office_id,
        'revenue_category_id' => $revenue_category_id,
        'sort_order' => $sort_order
    ]);

    $_SESSION['success'] = '収入項目が更新されました。';
    header('Location: revenue_item_list.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: revenue_item_list.php');
    exit;
}
