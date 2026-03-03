<?php
session_start();
require_once '../includes/database.php';

// POSTリクエスト以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: office_list.php');
    exit;
}

try {
    // DB接続
    $dbh = getDb();

    // POSTデータの受け取り
    $id = $_POST['id'] ?? null;

    // バリデーション: IDが存在しない場合
    if (empty($id)) {
        throw new Exception('削除対象が指定されていません。');
    }

    // 1. 削除前の存在確認（ログやメッセージ用に名前を取得）
    $stmt = $dbh->prepare('SELECT name, identifier FROM offices WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $targetOffice = $stmt->fetch(PDO::FETCH_ASSOC);

    // 該当レコードが存在しない場合
    if (!$targetOffice) {
        throw new Exception('指定された営業所（係）が見つかりません。');
    }

    $officeName = $targetOffice['name'];

    // ---------------------------------------------------------
    // 2. 使用状況の全フェーズチェック (削除ガード)
    // ---------------------------------------------------------
    // 営業所（係）は非常に多くのテーブルで使われるため、全てチェックします。
    $checkTargets = [
        // マスターテーブル
        'details'                  => '詳細マスター',
        'revenue_items'            => '収入項目マスター',
        // 実績テーブル
        'monthly_cp_details'       => 'CP実績データ',
        'monthly_forecast_details' => '見通しデータ',
        'monthly_plan_details'     => '予定データ',
        'monthly_outlook_details'  => '月末見込みデータ',
        'monthly_result_details'   => '概算データ'
    ];

    foreach ($checkTargets as $tableName => $phaseName) {
        try {
            $sql = "SELECT COUNT(*) FROM {$tableName} WHERE office_id = :id";
            $stmtCheck = $dbh->prepare($sql);
            $stmtCheck->execute([':id' => $id]);

            if ($stmtCheck->fetchColumn() > 0) {
                // どこか一つでも使われていたら即座にエラーとして処理を中断
                throw new Exception("この係は「{$phaseName}」で使用されているため削除できません。過去データを保持するため、削除ではなく名称変更などで対応してください。");
            }
        } catch (PDOException $e) {
            // テーブルが存在しないエラー(1146, 42S02)の場合は無視して次のチェックへ
            if ($e->getCode() == '42S02' || strpos($e->getMessage(), '1146') !== false) {
                continue;
            }
            // その他の重大なデータベースエラーはそのまま投げる
            throw $e;
        }
    }

    // ---------------------------------------------------------
    // 3. 削除実行
    // ---------------------------------------------------------
    $stmt = $dbh->prepare('DELETE FROM offices WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // 成功メッセージをセッションに保存してリダイレクト
    $_SESSION['success'] = htmlspecialchars($officeName) . ' が正常に削除されました。';
    header('Location: office_list.php');
    exit;
} catch (Exception $e) {
    // エラーメッセージをセッションに保存してリダイレクト
    $_SESSION['error'] = $e->getMessage();
    header('Location: office_list.php');
    exit;
}
