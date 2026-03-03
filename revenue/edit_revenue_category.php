<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // データ取得
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $sort_order = (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') ? (int)$_POST['sort_order'] : 100;

    // バリデーション
    if (empty($id) || empty($name)) {
        $_SESSION['error'] = '入力データに不備があります。';
        header('Location: revenue_category_list.php');
        exit;
    } elseif (mb_strlen($name) > 100) {
        $_SESSION['error'] = '収入カテゴリ名は100文字以内で入力してください。';
        header('Location: revenue_category_list.php');
        exit;
    }

    try {
        $db = getDb();

        // 重複チェック (自分自身は除く)
        $sqlCheck = "SELECT COUNT(*) FROM revenue_categories WHERE name = :name AND id != :id";
        $stmt = $db->prepare($sqlCheck);
        $stmt->execute([':name' => $name, ':id' => $id]);

        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'この収入カテゴリ名はすでに存在しています。';
            header('Location: revenue_category_list.php');
            exit;
        }

        // 更新実行
        $sql = "UPDATE revenue_categories SET name = :name, sort_order = :sort_order WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':sort_order' => $sort_order,
            ':id' => $id
        ]);

        // ログ記録
        logAudit($db, 'revenue_category', $id, 'update', [
            'msg' => 'Revenue category updated',
            'name' => $name,
            'sort_order' => $sort_order
        ]);

        $_SESSION['success'] = '収入カテゴリが更新されました。';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'エラー: ' . $e->getMessage();
    }

    header('Location: revenue_category_list.php');
    exit;
}
