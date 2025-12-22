<?php
require_once '../includes/database.php';

/**
 * 月次概算実績の更新処理（営業所ごとの時間管理対応）
 *
 * @param array $data POSTデータ
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function updateMonthlyResult(array $data, PDO $dbh)
{
    if (empty($data['result_id'])) {
        throw new Exception("result_id がありません。");
    }
    $result_id = $data['result_id'];
    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];

    // ----------------------------
    // 確定ステータスのチェック
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status, hourly_rate FROM monthly_result WHERE id = ?");
    $stmtStatusCheck->execute([$result_id]);
    $currentData = $stmtStatusCheck->fetch(PDO::FETCH_ASSOC);

    if (!$currentData) {
        throw new Exception("対象のデータが見つかりません。");
    }
    if (($currentData['status'] ?? '') === 'fixed') {
        throw new Exception("この概算実績はすでに確定済みで、修正できません。");
    }

    // 賃率の決定ロジック
    // 入力値が存在すればそれを使い、なければ(disabled等)既存の値を維持する
    if (isset($data['hourly_rate']) && $data['hourly_rate'] !== null) {
        $hourly_rate = (float)$data['hourly_rate'];
    } else {
        $hourly_rate = (float)$currentData['hourly_rate'];
    }

    try {
        // ----------------------------
        // 1. 親テーブル (monthly_result) の更新 (共通賃率)
        // ----------------------------
        $stmtParent = $dbh->prepare("UPDATE monthly_result SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
        $stmtParent->execute([$hourly_rate, $result_id]);

        // ----------------------------
        // 2. 営業所別時間データ (monthly_result_time) の更新/追加
        // ----------------------------
        $stmtCheckTime = $dbh->prepare("
            SELECT id FROM monthly_result_time 
            WHERE monthly_result_id = ? AND office_id = ?
        ");

        $stmtUpdateTime = $dbh->prepare("
            UPDATE monthly_result_time SET
                standard_hours = ?, overtime_hours = ?, transferred_hours = ?, 
                fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtInsertTime = $dbh->prepare("
            INSERT INTO monthly_result_time
            (monthly_result_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtDeleteTime = $dbh->prepare("
            DELETE FROM monthly_result_time
            WHERE monthly_result_id = ? AND office_id = ?
        ");

        foreach ($officeTimeData as $office_id => $time) {
            $office_id = (int)$office_id;
            if ($office_id <= 0) continue;

            $standard = (float)($time['standard_hours'] ?? 0);
            $overtime = (float)($time['overtime_hours'] ?? 0);
            $transfer = (float)($time['transferred_hours'] ?? 0);
            $full = (int)($time['fulltime_count'] ?? 0);
            $contract = (int)($time['contract_count'] ?? 0);
            $dispatch = (int)($time['dispatch_count'] ?? 0);

            $stmtCheckTime->execute([$result_id, $office_id]);
            $existingId = $stmtCheckTime->fetchColumn();

            // 0件の場合削除
            if ($standard == 0 && $overtime == 0 && $transfer == 0 && $full == 0 && $contract == 0 && $dispatch == 0) {
                if ($existingId) {
                    $stmtDeleteTime->execute([$result_id, $office_id]);
                }
            } else {
                if ($existingId) {
                    $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
                } else {
                    $stmtInsertTime->execute([$result_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
                }
            }
        }

        // ----------------------------
        // 3. 勘定科目明細 (経費) の更新/追加
        // ----------------------------
        $stmtCheckDetail = $dbh->prepare("
            SELECT id FROM monthly_result_details 
            WHERE result_id = ? AND detail_id = ?
        ");

        $stmtUpdateDetail = $dbh->prepare("
            UPDATE monthly_result_details
            SET amount = ?
            WHERE id = ?
        ");
        $stmtInsertDetail = $dbh->prepare("
            INSERT INTO monthly_result_details (result_id, detail_id, amount)
            VALUES (?, ?, ?)
        ");
        $stmtDeleteDetail = $dbh->prepare("
            DELETE FROM monthly_result_details
            WHERE result_id = ? AND detail_id = ?
        ");

        if (!empty($amounts)) {
            foreach ($amounts as $detail_id => $amount) {
                $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
                $detail_id = (int)$detail_id;

                $stmtCheckDetail->execute([$result_id, $detail_id]);
                $existingId = $stmtCheckDetail->fetchColumn();

                if ($amountValue != 0) {
                    if ($existingId) {
                        $stmtUpdateDetail->execute([$amountValue, $existingId]);
                    } else {
                        $stmtInsertDetail->execute([$result_id, $detail_id, $amountValue]);
                    }
                } elseif ($existingId) {
                    // 金額が 0 の場合のみ、既存のレコードを削除
                    $stmtDeleteDetail->execute([$result_id, $detail_id]);
                }
            }
        }


        // ----------------------------
        // 4. 収入明細 (monthly_result_revenues) の更新/追加
        // ----------------------------
        $stmtCheckRev = $dbh->prepare("
            SELECT id FROM monthly_result_revenues 
            WHERE result_id = ? AND revenue_item_id = ?
        ");
        $stmtUpdateRev = $dbh->prepare("
            UPDATE monthly_result_revenues SET amount = ? WHERE id = ?
        ");
        $stmtInsertRev = $dbh->prepare("
            INSERT INTO monthly_result_revenues (result_id, revenue_item_id, amount)
            VALUES (?, ?, ?)
        ");
        $stmtDeleteRev = $dbh->prepare("
            DELETE FROM monthly_result_revenues
            WHERE result_id = ? AND revenue_item_id = ?
        ");

        if (!empty($revenues)) {
            foreach ($revenues as $revenue_item_id => $amount) {
                $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
                $revenue_item_id = (int)$revenue_item_id;

                $stmtCheckRev->execute([$result_id, $revenue_item_id]);
                $existingId = $stmtCheckRev->fetchColumn();

                if ($amountValue != 0) { // マイナス対応
                    if ($existingId) {
                        $stmtUpdateRev->execute([$amountValue, $existingId]);
                    } else {
                        $stmtInsertRev->execute([$result_id, $revenue_item_id, $amountValue]);
                    }
                } elseif ($existingId) {
                    // 金額が 0 (または空) の場合は削除
                    $stmtDeleteRev->execute([$result_id, $revenue_item_id]);
                }
            }
        }
    } catch (Exception $e) {
        throw new Exception("概算実績の更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 概算実績確定処理（ステータス変更のみ）
 * ※Resultは最終工程のため、次の工程へのコピー処理は不要
 */
function confirmMonthlyResult(array $data, PDO $dbh)
{
    $result_id = $data['result_id'] ?? null;
    if (!$result_id) {
        throw new Exception("result_id が存在しません。");
    }

    // 1. まず更新処理を実行し、最新のデータを DB に保存
    updateMonthlyResult($data, $dbh);

    try {
        // 2. ステータスを 'fixed' に更新
        $stmt = $dbh->prepare("UPDATE monthly_result SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$result_id]);
    } catch (Exception $e) {
        throw new Exception("概算実績の確定中にエラーが発生しました: " . $e->getMessage());
    }
}
