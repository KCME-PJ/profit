<?php
require_once '../includes/database.php';

/**
 * 月次予定の更新処理（営業所ごとの時間管理対応）
 *
 * @param array $data POSTデータ
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function updateMonthlyPlan(array $data, PDO $dbh)
{
    if (empty($data['plan_id'])) {
        throw new Exception("plan_id がありません。");
    }
    $plan_id = $data['plan_id'];
    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];

    // ----------------------------
    // 確定ステータスのチェック
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status, hourly_rate FROM monthly_plan WHERE id = ?");
    $stmtStatusCheck->execute([$plan_id]);
    $currentData = $stmtStatusCheck->fetch(PDO::FETCH_ASSOC);

    if (!$currentData) {
        throw new Exception("対象のデータが見つかりません。");
    }
    if (($currentData['status'] ?? '') === 'fixed') {
        throw new Exception("この予定はすでに確定済みで、修正できません。");
    }

    // ★修正ポイント1: 賃率の決定ロジック
    // 入力値が存在すればそれを使い、なければ(disabled等)既存の値を維持する
    if (isset($data['hourly_rate']) && $data['hourly_rate'] !== '') {
        $hourly_rate = (float)$data['hourly_rate'];
    } else {
        $hourly_rate = (float)$currentData['hourly_rate'];
    }

    try {
        // ----------------------------
        // 1. 親テーブル (monthly_plan) の更新 (共通賃率)
        // ----------------------------
        $stmtParent = $dbh->prepare("UPDATE monthly_plan SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
        $stmtParent->execute([$hourly_rate, $plan_id]);

        // ----------------------------
        // 2. 営業所別時間データ (monthly_plan_time) の更新/追加
        // ----------------------------
        $stmtCheckTime = $dbh->prepare("
            SELECT id FROM monthly_plan_time 
            WHERE monthly_plan_id = ? AND office_id = ?
        ");

        $stmtUpdateTime = $dbh->prepare("
            UPDATE monthly_plan_time SET
                standard_hours = ?, overtime_hours = ?, transferred_hours = ?, 
                fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtInsertTime = $dbh->prepare("
            INSERT INTO monthly_plan_time
            (monthly_plan_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtDeleteTime = $dbh->prepare("
            DELETE FROM monthly_plan_time
            WHERE monthly_plan_id = ? AND office_id = ?
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

            $stmtCheckTime->execute([$plan_id, $office_id]);
            $existingId = $stmtCheckTime->fetchColumn();

            // 0件の場合削除
            if ($standard == 0 && $overtime == 0 && $transfer == 0 && $full == 0 && $contract == 0 && $dispatch == 0) {
                if ($existingId) {
                    $stmtDeleteTime->execute([$plan_id, $office_id]);
                }
            } else {
                if ($existingId) {
                    $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
                } else {
                    $stmtInsertTime->execute([$plan_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
                }
            }
        }

        // ----------------------------
        // 3. 勘定科目明細 (経費) の更新/追加
        // ----------------------------
        $stmtCheckDetail = $dbh->prepare("
            SELECT id FROM monthly_plan_details 
            WHERE plan_id = ? AND detail_id = ?
        ");

        $stmtUpdateDetail = $dbh->prepare("
            UPDATE monthly_plan_details
            SET amount = ?
            WHERE id = ?
        ");
        $stmtInsertDetail = $dbh->prepare("
            INSERT INTO monthly_plan_details (plan_id, detail_id, amount)
            VALUES (?, ?, ?)
        ");
        $stmtDeleteDetail = $dbh->prepare("
            DELETE FROM monthly_plan_details
            WHERE plan_id = ? AND detail_id = ?
        ");

        if (!empty($amounts)) {
            foreach ($amounts as $detail_id => $amount) {
                $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
                $detail_id = (int)$detail_id;

                $stmtCheckDetail->execute([$plan_id, $detail_id]);
                $existingId = $stmtCheckDetail->fetchColumn();

                if ($amountValue != 0) {
                    if ($existingId) {
                        $stmtUpdateDetail->execute([$amountValue, $existingId]);
                    } else {
                        $stmtInsertDetail->execute([$plan_id, $detail_id, $amountValue]);
                    }
                } elseif ($existingId) {
                    // 金額が 0 の場合のみ、既存のレコードを削除
                    $stmtDeleteDetail->execute([$plan_id, $detail_id]);
                }
            }
        }


        // ----------------------------
        // 4. 収入明細 (monthly_plan_revenues) の更新/追加
        // ----------------------------
        $stmtCheckRev = $dbh->prepare("
            SELECT id FROM monthly_plan_revenues 
            WHERE plan_id = ? AND revenue_item_id = ?
        ");
        $stmtUpdateRev = $dbh->prepare("
            UPDATE monthly_plan_revenues SET amount = ? WHERE id = ?
        ");
        $stmtInsertRev = $dbh->prepare("
            INSERT INTO monthly_plan_revenues (plan_id, revenue_item_id, amount)
            VALUES (?, ?, ?)
        ");
        $stmtDeleteRev = $dbh->prepare("
            DELETE FROM monthly_plan_revenues
            WHERE plan_id = ? AND revenue_item_id = ?
        ");

        if (!empty($revenues)) {
            foreach ($revenues as $revenue_item_id => $amount) {
                $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
                $revenue_item_id = (int)$revenue_item_id;

                $stmtCheckRev->execute([$plan_id, $revenue_item_id]);
                $existingId = $stmtCheckRev->fetchColumn();

                if ($amountValue != 0) { // マイナス対応
                    if ($existingId) {
                        $stmtUpdateRev->execute([$amountValue, $existingId]);
                    } else {
                        $stmtInsertRev->execute([$plan_id, $revenue_item_id, $amountValue]);
                    }
                } elseif ($existingId) {
                    // 金額が 0 (または空) の場合は削除
                    $stmtDeleteRev->execute([$plan_id, $revenue_item_id]);
                }
            }
        }
    } catch (Exception $e) {
        throw new Exception("予定の更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 予定確定処理（ステータス変更＆次の工程(Outlook)へデータ反映）
 */
function confirmMonthlyPlan(array $data, PDO $dbh)
{
    $plan_id = $data['plan_id'] ?? null;
    if (!$plan_id) {
        throw new Exception("plan_id が存在しません。");
    }

    // 更新処理を呼び出し（ここでDBのhourly_rateが正しくセットされる）
    updateMonthlyPlan($data, $dbh);

    try {
        $stmt = $dbh->prepare("UPDATE monthly_plan SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$plan_id]);

        reflectToOutlook($plan_id, $dbh);
    } catch (Exception $e) {
        throw new Exception("予定の確定中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 予定データから月末見込み(Outlook)テーブルへデータを反映する処理
 */
function reflectToOutlook(int $plan_id, PDO $dbh)
{
    // 1. コピー元の年・月を取得
    $stmt = $dbh->prepare("SELECT year, month FROM monthly_plan WHERE id = ?");
    $stmt->execute([$plan_id]);
    $planInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$planInfo) {
        throw new Exception("参照元の予定データが見つかりません。");
    }
    $year = $planInfo['year'];
    $month = $planInfo['month'];

    try {
        // 2. 既存の Outlook があれば削除 (ID再生成のため)
        $outlookId = getMonthlyOutlookId($year, $month, $dbh);
        if ($outlookId) {
            $dbh->prepare("DELETE FROM monthly_outlook WHERE id = ?")->execute([$outlookId]);
            $outlookId = null;
        }

        // 3. ★修正ポイント2: 親レコードのコピー (INSERT ... SELECT を使用して確実に値をコピー)
        $stmt = $dbh->prepare("
            INSERT INTO monthly_outlook (year, month, hourly_rate, status)
            SELECT year, month, hourly_rate, 'draft'
            FROM monthly_plan
            WHERE id = ?
        ");
        $stmt->execute([$plan_id]);

        $outlookId = $dbh->lastInsertId();
        if (!$outlookId) {
            throw new Exception("月末見込み(Outlook)の親レコードの挿入に失敗しました。");
        }

        // 4. 子データのコピー (時間データ)
        $stmtCopyTime = $dbh->prepare("
            INSERT INTO monthly_outlook_time 
            (monthly_outlook_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
            SELECT 
                ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count
            FROM monthly_plan_time 
            WHERE monthly_plan_id = ?
        ");
        $stmtCopyTime->execute([$outlookId, $plan_id]);

        // 5. 子データのコピー (経費詳細)
        $stmtCopyDetails = $dbh->prepare("
            INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount)
            SELECT ?, detail_id, amount
            FROM monthly_plan_details
            WHERE plan_id = ?
        ");
        $stmtCopyDetails->execute([$outlookId, $plan_id]);

        // 6. 子データのコピー (収入詳細)
        $stmtCopyRevenues = $dbh->prepare("
            INSERT INTO monthly_outlook_revenues (outlook_id, revenue_item_id, amount)
            SELECT ?, revenue_item_id, amount
            FROM monthly_plan_revenues
            WHERE plan_id = ?
        ");
        $stmtCopyRevenues->execute([$outlookId, $plan_id]);
    } catch (Exception $e) {
        throw new Exception("月末見込み(Outlook)への反映中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 指定年月の monthly_outlook ID を取得するヘルパー関数
 */
function getMonthlyOutlookId(int $year, int $month, PDO $dbh): ?int
{
    $stmt = $dbh->prepare("SELECT id FROM monthly_outlook WHERE year = ? AND month = ? LIMIT 1");
    $stmt->execute([$year, $month]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}
