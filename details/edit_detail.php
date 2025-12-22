<?php
require_once '../includes/database.php';

// セッションが開始されていない場合は開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $dbh = getDb();

    // フォームデータの取得
    $id = $_POST['id'] ?? null;
    $detail_name = trim($_POST['detail_name'] ?? '');
    $detail_identifier = trim($_POST['detail_identifier'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $account_id = $_POST['account_id'] ?? null;
    $office_id = $_POST['office_id'] ?? null;

    // 入力チェック
    if (empty($id) || empty($detail_name) || empty($detail_identifier) || empty($account_id) || empty($office_id)) {
        throw new Exception('入力データに不備があります。');
    }

    // 一意識別子のバリデーション
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $detail_identifier)) {
        throw new Exception('一意識別子には、半角英数字、ハイフン、アンダースコアのみ使用可能です。');
    }

    // 1. 一意識別子の重複チェック（全体でチェック、自分自身は除く）
    $stmt = $dbh->prepare('SELECT id FROM details WHERE identifier = :identifier AND id != :id');
    $stmt->execute([':identifier' => $detail_identifier, ':id' => $id]);
    if ($stmt->fetch()) {
        throw new Exception('一意識別子が既に使用されています。');
    }

    // 2. 詳細名の重複チェック（営業所内でチェック、自分自身は除く）
    // ★修正: office_id も条件に加え、同じ営業所内でのみ名前重複を禁止する
    $stmt = $dbh->prepare('SELECT id FROM details WHERE name = :name AND office_id = :office_id AND id != :id');
    $stmt->execute([
        ':name' => $detail_name,
        ':office_id' => $office_id,
        ':id' => $id
    ]);
    if ($stmt->fetch()) {
        throw new Exception('この営業所内ですでに同じ詳細名が使用されています。');
    }

    // データの更新
    $sql = "UPDATE details 
            SET name = :name, identifier = :identifier, note = :note, account_id = :account_id, office_id = :office_id
            WHERE id = :id";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':name' => $detail_name,
        ':identifier' => $detail_identifier,
        ':note' => $note,
        ':account_id' => $account_id,
        ':office_id' => $office_id,
        ':id' => $id
    ]);

    // 成功時
    $_SESSION['success'] = '詳細が更新されました。';
    header('Location: detail_list.php');
    exit;
} catch (Exception $e) {
    // エラー時
    $_SESSION['error'] = $e->getMessage();
    // 入力値を保持してリダイレクト（フォーム側で復元処理がある場合）
    $_SESSION['form_data'] = [
        'id' => $id ?? '',
        'detail_name' => $detail_name ?? '',
        'detail_identifier' => $detail_identifier ?? '',
        'note' => $note ?? '',
        'account_id' => $account_id ?? '',
        'office_id' => $office_id ?? ''
    ];
    header('Location: detail_list.php');
    exit;
}
