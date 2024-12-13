    <?php
    session_start();
    require_once '../includes/database.php';

    try {
        // POSTデータを取得
        $account_id = trim($_POST['account_id']);
        $office_id = trim($_POST['office_id']);  // 営業所情報を取得
        $name = trim($_POST['name'] ?? '');
        $identifier = trim($_POST['identifier'] ?? '');
        $note = trim($_POST['note'] ?? '');

        // 入力値の検証
        if (empty($name)) {
            throw new Exception('詳細名は必須です。');
        }
        if (empty($identifier)) {
            throw new Exception('一意識別子は必須です。');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
            throw new Exception('一意識別子は半角英数字、ハイフン、アンダースコアのみ使用できます。');
        }
        if (empty($office_id)) {
            throw new Exception('営業所は必須です。');
        }

        // データベース接続
        $dbh = getDb();

        // 重複チェック（詳細名と一意識別子）
        $stmt = $dbh->prepare('SELECT COUNT(*) FROM details WHERE identifier = :identifier OR name = :name');
        $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);  // 詳細名も重複チェック
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('同じ一意識別子または詳細名がすでに存在します。');
        }

        // データの挿入
        $stmt = $dbh->prepare('INSERT INTO details (account_id, office_id, name, identifier, note) VALUES (:account_id, :office_id, :name, :identifier, :note)');
        $stmt->bindValue(':account_id', $account_id, PDO::PARAM_INT);
        $stmt->bindValue(':office_id', $office_id, PDO::PARAM_INT);  // 営業所IDを挿入
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
        $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        $stmt->execute();

        $_SESSION['success'] = '正常に登録されました。';
        header('Location: detail.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: detail.php');
        exit;
    }
