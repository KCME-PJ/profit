<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: revenue_item_list.php');
    exit;
}

try {
    $dbh = getDb();
    $id = $_POST['id'] ?? null;

    if (empty($id)) {
        throw new Exception('削除対象のIDが指定されていません。');
    }

    // 1. 削除前の存在確認
    $stmt = $dbh->prepare('SELECT * FROM revenue_items WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $targetItem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetItem) {
        throw new Exception('削除対象の収入項目が見つかりません。');
    }

    // ---------------------------------------------------------
    // 2. 使用状況の全フェーズチェック (削除ガード)
    // ---------------------------------------------------------
    // チェック対象のテーブルと表示名のリスト
    $checkTargets = [
        'monthly_cp_revenues'       => 'CP実績',
        'monthly_forecast_revenues' => '見通し',
        'monthly_plan_revenues'     => '予定',
        'monthly_outlook_revenues'  => '月末見込み',
        'monthly_result_revenues'   => '概算'
    ];

    foreach ($checkTargets as $tableName => $phaseName) {
        try {
            // 月次テーブルのカラム名が revenue_item_id であることを前提としています
            $sql = "SELECT COUNT(*) FROM {$tableName} WHERE revenue_item_id = :id";
            $stmtCheck = $dbh->prepare($sql);
            $stmtCheck->execute([':id' => $id]);

            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("この収入項目は「{$phaseName}」で使用されているため削除できません。過去データを保持するため、削除ではなく名称変更などで対応してください。");
            }
        } catch (PDOException $e) {
            // テーブルがまだ存在しないエラー(1146, 42S02)の場合は無視して続行
            if ($e->getCode() == '42S02' || strpos($e->getMessage(), '1146') !== false) {
                continue;
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------
    // 3. 削除実行
    // ---------------------------------------------------------
    $stmt = $dbh->prepare('DELETE FROM revenue_items WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // ログ記録
    if ($targetItem) {
        logAudit($dbh, 'revenue_item', $id, 'delete', [
            'msg' => 'Revenue item deleted',
            'name' => $targetItem['name'],
            'office_id' => $targetItem['office_id'],
            'revenue_category_id' => $targetItem['revenue_category_id']
        ]);
    }

    $_SESSION['success'] = '収入項目が削除されました。';
    header('Location: revenue_item_list.php');
    exit;
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: revenue_item_list.php');
    exit;
}
