<?php
// login.php
require_once 'includes/database.php';

// セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 既にログイン済みならトップへリダイレクト
if (isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $dbh = getDb();
        // 氏名カラムが分かれているため、それぞれ取得します
        $stmt = $dbh->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // 認証成功：セッションハイジャック対策
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            // 画面表示用に姓と名を結合してセッションに保存
            $_SESSION['display_name'] = $user['last_name'] . ' ' . $user['first_name'];
            $_SESSION['office_id'] = $user['office_id'];
            $_SESSION['role']      = $user['role'];

            // ログイン後のリダイレクト
            header("Location: index.html");
            exit;
        } else {
            $error = 'ユーザーIDまたはパスワードが間違っています。';
        }
    } catch (Exception $e) {
        $error = 'システムエラーが発生しました: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | 採算管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header h2 {
            font-size: 1.5rem;
            color: #333;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-header">
            <h2>採算管理システム</h2>
            <p class="text-muted">ログインしてください</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">ユーザーID</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">パスワード</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">ログイン</button>
        </form>
    </div>
</body>

</html>