<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // フォームデータの取得
    $name = trim($_POST['name'] ?? '');
    $sort_order = (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') ? (int)$_POST['sort_order'] : 100;

    // バリデーション
    if (empty($name)) {
        $_SESSION['error'] = '収入カテゴリ名は必須です。';
        header('Location: revenue_category.php');
        exit;
    } elseif (mb_strlen($name) > 100) {
        $_SESSION['error'] = '収入カテゴリ名は100文字以内で入力してください。';
        header('Location: revenue_category.php');
        exit;
    }

    try {
        $db = getDb();

        // 重複チェック
        $stmt = $db->prepare("SELECT COUNT(*) FROM revenue_categories WHERE name = :name");
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = '同じ収入カテゴリ名が既に登録されています。';
            header('Location: revenue_category.php');
            exit;
        }

        // 登録実行
        $stmt = $db->prepare("INSERT INTO revenue_categories (name, sort_order) VALUES (:name, :sort_order)");
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':sort_order', $sort_order, PDO::PARAM_INT);
        $stmt->execute();

        // ログ記録
        $newId = $db->lastInsertId();
        logAudit($db, 'revenue_category', $newId, 'create', [
            'msg' => 'New revenue category created',
            'name' => $name,
            'sort_order' => $sort_order
        ]);

        $_SESSION['success'] = '収入カテゴリを登録しました。';
        header('Location: revenue_category_list.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'データベースエラーが発生しました: ' . $e->getMessage();
        header('Location: revenue_category.php');
        exit;
    }
}
