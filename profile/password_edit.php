<?php
// セッション開始・ログイン認証確認
require_once '../includes/auth_check.php';

// エラーメッセージ等がセッションにあれば取得（update処理からのリダイレクト用）
$error_msg = isset($_SESSION['error_msg']) ? $_SESSION['error_msg'] : '';
$success_msg = isset($_SESSION['success_msg']) ? $_SESSION['success_msg'] : '';

// 表示したらセッションのメッセージは消去
unset($_SESSION['error_msg']);
unset($_SESSION['success_msg']);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワード変更 | 採算管理システム</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../css/index.css">

    <style>
        body {
            background-color: #f0f2f5;
            /* 背景色：目に優しいライトグレー */
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            font-family: "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif;
        }

        .card-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            /* ふんわりとした影 */
            overflow: hidden;
        }

        .card-header-custom {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            /* プロフェッショナルな青グラデーション */
            color: white;
            padding: 20px;
            text-align: center;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .btn-custom {
            background-color: #0d6efd;
            border: none;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-custom:disabled {
            background-color: #6c757d;
            /* グレー色 */
            border-color: #6c757d;
            opacity: 0.65;
            /* 少し薄くする */
            cursor: not-allowed;
            /* マウスを乗せると禁止マークに */
            transform: none;
            /* ホバー時の動きを無効化 */
            box-shadow: none;
            /* 影を消す */
        }

        .btn-custom:hover {
            background-color: #0b5ed7;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3);
        }

        .back-link {
            text-decoration: none;
            color: #6c757d;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            margin-bottom: 20px;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: #0d6efd;
        }

        .form-floating>label {
            color: #6c757d;
        }

        /* 入力チェック時のエラースタイル */
        .validation-error {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
            /* JSで制御 */
            align-items: center;
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6 col-xl-5">

                <a href="../index.html" class="back-link">
                    <i class="bi bi-arrow-left me-1"></i> ダッシュボードへ戻る
                </a>

                <div class="card card-custom">
                    <div class="card-header card-header-custom">
                        <i class="bi bi-shield-lock-fill me-2"></i>パスワード変更
                    </div>
                    <div class="card-body p-4 p-md-5">

                        <?php if ($error_msg): ?>
                            <div class="alert alert-danger d-flex align-items-center" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <div><?php echo htmlspecialchars($error_msg, ENT_QUOTES); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($success_msg): ?>
                            <div class="alert alert-success d-flex align-items-center" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <div><?php echo htmlspecialchars($success_msg, ENT_QUOTES); ?></div>
                            </div>
                        <?php endif; ?>

                        <form action="password_update.php" method="POST" id="passwordForm" novalidate>

                            <div class="form-floating mb-3">
                                <input type="password" class="form-control" id="currentPassword" name="current_password" placeholder="現在のパスワード" required>
                                <label for="currentPassword">現在のパスワード</label>
                            </div>

                            <hr class="my-4 text-muted opacity-25">

                            <div class="mb-3">
                                <div class="form-floating input-group">
                                    <input type="password" class="form-control" id="newPassword" name="new_password" placeholder="新しいパスワード" required minlength="8">
                                    <label for="newPassword">新しいパスワード (8文字以上)</label>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleNewPass">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text text-muted" id="passwordHelp">
                                    <i class="bi bi-info-circle me-1"></i>8文字以上の英数字・記号（スペース不可）
                                </div>
                                <div id="charError" class="validation-error text-danger mt-1" style="display: none; font-size: 0.85rem;">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> 半角英数字・記号のみ入力可能です（日本語・スペースは不可）。
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="form-floating input-group">
                                    <input type="password" class="form-control" id="confirmPassword" name="confirm_password" placeholder="確認用パスワード" required>
                                    <label for="confirmPassword">新しいパスワード (確認)</label>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPass">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div id="matchError" class="validation-error">
                                    <i class="bi bi-x-circle-fill me-1"></i> パスワードが一致しません
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-custom btn-lg text-white" id="submitBtn">
                                    パスワードを変更する
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-3 text-muted" style="font-size: 0.8rem;">
                    &copy; Profit Management System
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="../js/password_check.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function togglePassword(inputId, btnId) {
                const input = document.getElementById(inputId);
                const btn = document.getElementById(btnId);
                const icon = btn.querySelector('i');

                btn.addEventListener('click', function() {
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('bi-eye');
                        icon.classList.add('bi-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('bi-eye-slash');
                        icon.classList.add('bi-eye');
                    }
                });
            }

            togglePassword('newPassword', 'toggleNewPass');
            togglePassword('confirmPassword', 'toggleConfirmPass');
        });
    </script>

</body>

</html>