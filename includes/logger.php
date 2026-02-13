<?php
require_once __DIR__ . '/database.php';

/**
 * 監査ログを記録する
 *
 * @param PDO $dbh データベース接続
 * @param string $phase 対象機能 (forecast, plan など) - DBカラム名: phase
 * @param int $target_id 対象ID - DBカラム名: target_id
 * @param string $action 操作内容 (update, fix など) - DBカラム名: action
 * @param mixed $content 保存する詳細データ (配列やJSONなど) - DBカラム名: content
 */
function logAudit($dbh, $phase, $target_id, $action, $content = null)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 1. 値の準備
    $user_id = $_SESSION['user_id'] ?? 0;

    // デフォルトはセッションのoffice_idを使用
    $office_id = 0;
    if (isset($_SESSION['office_id']) && is_numeric($_SESSION['office_id'])) {
        $office_id = (int)$_SESSION['office_id'];
    }

    // 引数 $content 内に office_id が明示されている場合は、それを優先する
    // (管理者が他拠点のデータを操作・復元した場合などに、正しい拠点IDを記録するため)
    if (is_array($content) && !empty($content['office_id'])) {
        $office_id = (int)$content['office_id'];
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    // contentが配列などの場合はJSON化
    if (is_array($content) || is_object($content)) {
        $content = json_encode($content, JSON_UNESCAPED_UNICODE);
    } elseif ($content === '') {
        $content = null;
    }

    try {
        // 2. SQLの準備 (既存の content カラムを使用)
        $sql = "INSERT INTO audit_logs (user_id, office_id, phase, target_id, action, content, ip_address, created_at) 
                VALUES (:user_id, :office_id, :phase, :target_id, :action, :content, :ip_address, NOW())";

        $stmt = $dbh->prepare($sql);

        // 3. 値のバインド
        $stmt->bindValue(':user_id',    $user_id,    PDO::PARAM_INT);
        $stmt->bindValue(':office_id',  $office_id,  PDO::PARAM_INT);
        $stmt->bindValue(':phase',      $phase,      PDO::PARAM_STR);
        $stmt->bindValue(':target_id',  $target_id,  PDO::PARAM_INT);
        $stmt->bindValue(':action',     $action,     PDO::PARAM_STR);
        $stmt->bindValue(':content',    $content,    PDO::PARAM_STR);
        $stmt->bindValue(':ip_address', $ip_address, PDO::PARAM_STR);

        // 4. 実行
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
    }
}
