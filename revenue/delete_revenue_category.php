<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (empty($id)) {
        $_SESSION['error'] = '無効なIDです。';
        header('Location: revenue_category_list.php');
        exit;
    }

    try {
        $db = getDb();

        // 削除ガード: このカテゴリが「収入項目(revenue_items)」で使われているかチェック
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM revenue_items WHERE revenue_category_id = :id');
        $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();

        if ($checkStmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'このカテゴリは「収入項目」で使用されているため削除できません。先に紐付いている収入項目を削除または変更してください。';
            header('Location: revenue_category_list.php');
            exit;
        }

        // 削除前にログ用情報を取得
        $nameStmt = $db->prepare("SELECT name FROM revenue_categories WHERE id = :id");
        $nameStmt->execute([':id' => $id]);
        $categoryInfo = $nameStmt->fetch(PDO::FETCH_ASSOC);

        // 削除実行
        $stmt = $db->prepare('DELETE FROM revenue_categories WHERE id = :id');
        $stmt->execute([':id' => $id]);

        // ログ記録
        if ($categoryInfo) {
            logAudit($db, 'revenue_category', $id, 'delete', [
                'msg' => 'Revenue category deleted',
                'name' => $categoryInfo['name']
            ]);
        }

        $_SESSION['success'] = '収入カテゴリが正常に削除されました。';
    } catch (PDOException $e) {
        $_SESSION['error'] = '削除処理中にエラーが発生しました: ' . $e->getMessage();
    }

    header('Location: revenue_category_list.php');
    exit;
}
