<?php
require_once '../includes/database.php';
require_once '../includes/result_functions.php';

$dbh = getDb();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit("不正なアクセスです。");
}

try {
    $actionType = $_POST['action_type'] ?? 'update';

    if ($actionType === 'update') {
        updateMonthlyResult($_POST, $dbh);
        $message = "概算実績を修正しました。";
    } elseif ($actionType === 'fixed') {
        confirmMonthlyResult($_POST, $dbh);
        $message = "概算実績を確定しました。";
    } else {
        throw new Exception("無効なアクションタイプです。");
    }
?>
    <!DOCTYPE html>
    <html lang="ja">

    <head>
        <meta charset="UTF-8">
        <title>完了</title>
        <meta http-equiv="refresh" content="3;url=result_edit.php">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>

    <body class="bg-light">
        <div class="container mt-5">
            <div class="alert alert-success shadow rounded p-4 text-center">
                <h4 class="alert-heading mb-3"><?= htmlspecialchars($message) ?></h4>
                <p class="mb-3">3秒後に自動で編集ページに戻ります。</p>
                <a href="result_edit.php" class="btn btn-primary">すぐに戻る</a>
            </div>
        </div>
    </body>

    </html>

<?php
} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    $error = urlencode($e->getMessage());
    header("Location: result_edit.php?error={$error}");
    exit;
}
