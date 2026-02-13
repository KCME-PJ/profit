<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

// POSTリクエスト以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: detail_list.php');
    exit;
}

try {
    $dbh = getDb();
    $id = $_POST['id'] ?? null;

    if (empty($id)) {
        throw new Exception('削除対象のIDが指定されていません。');
    }

    // 1. 削除前の存在確認
    $stmt = $dbh->prepare('SELECT * FROM details WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $targetDetail = $stmt->fetch(PDO::FETCH_ASSOC); // log記録用に情報を取得しておく

    if (!$targetDetail) {
        throw new Exception('削除対象の詳細が見つかりません。');
    }

    // ---------------------------------------------------------
    // 2. 使用状況の全フェーズチェック
    // ---------------------------------------------------------

    // チェック対象のテーブルと表示名のリスト
    $checkTargets = [
        'monthly_cp_details'       => 'CP実績',
        'monthly_forecast_details' => '見通し',
        'monthly_plan_details'     => '予定',
        'monthly_outlook_details'  => '月末見込み',
        'monthly_result_details'   => '概算'
    ];

    foreach ($checkTargets as $tableName => $phaseName) {
        try {
            $sql = "SELECT COUNT(*) FROM {$tableName} WHERE detail_id = :id";
            $stmtCheck = $dbh->prepare($sql);
            $stmtCheck->execute([':id' => $id]);

            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("この詳細は「{$phaseName}」で使用されているため削除できません。<br>過去データを保持するため、削除ではなく名称変更などで対応してください。");
            }
        } catch (PDOException $e) {
            // テーブルが存在しないエラー(1146, 42S02)の場合は無視
            if ($e->getCode() == '42S02') {
                continue;
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------
    // 3. 削除実行
    // ---------------------------------------------------------
    $stmt = $dbh->prepare('DELETE FROM details WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // ログ記録
    if ($targetDetail) {
        logAudit($dbh, 'detail', $id, 'delete', [
            'msg' => 'Detail deleted',
            'name' => $targetDetail['name'],
            'identifier' => $targetDetail['identifier'],
            'office_id' => $targetDetail['office_id'],
            'account_id' => $targetDetail['account_id'],
        ]);
    }

    $_SESSION['success'] = '詳細が削除されました。';
    header('Location: detail_list.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: detail_list.php');
    exit;
}
