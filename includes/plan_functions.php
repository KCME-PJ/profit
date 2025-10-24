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
        throw new Exception("plan_id がありません。データが存在しない場合は、月選択時に自動で登録されます。");
    }
    $plan_id = $data['plan_id'];
    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];

    // ----------------------------
    // 確定ステータスのチェック (Fixedデータは修正不可)
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status FROM monthly_plan WHERE id = ?");
    $stmtStatusCheck->execute([$plan_id]);
    $currentStatus = $stmtStatusCheck->fetchColumn();

    if ($currentStatus === 'fixed') {
        throw new Exception("この予定はすでに確定済みで、修正できません。");
    }
    // ----------------------------

    try {
        // ----------------------------
        // 1. 親テーブル (monthly_plan) の更新
        //    - 共通賃率の更新
        // ----------------------------

        // 現状の賃率を取得
        $stmtHourlyRate = $dbh->prepare("SELECT hourly_rate FROM monthly_plan WHERE id = ?");
        $stmtHourlyRate->execute([$plan_id]);
        $currentRate = $stmtHourlyRate->fetchColumn();

        if ($currentRate === false) {
            throw new Exception("対象の予定データが見つかりません。");
        }

        // 賃率はどの営業所も同じ値を使用するため、最初の営業所の値または既存の値を採用する
        $firstOfficeData = reset($officeTimeData);
        $hourly_rate = (float)($firstOfficeData['hourly_rate'] ?? $currentRate ?? 0);

        // 親テーブルの更新（statusは更新時そのまま維持、hourly_rateのみ更新）
        $stmtParent = $dbh->prepare("UPDATE monthly_plan SET hourly_rate = ? WHERE id = ?");
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
                fulltime_count = ?, contract_count = ?, dispatch_count = ?
            WHERE id = ?
        ");
        $stmtInsertTime = $dbh->prepare("
            INSERT INTO monthly_plan_time
            (monthly_plan_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
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

            if ($existingId) {
                // 更新
                $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
            } else {
                // 新規挿入
                $stmtInsertTime->execute([$plan_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
            }
        }

        // ----------------------------
        // 3. 勘定科目明細 (monthly_plan_details) の更新/追加
        // ----------------------------
        // 明細テーブルの親IDは plan_id
        $detail_parent_id = $plan_id;

        // 既存データをチェックし、更新または挿入を行う
        $stmtCheckDetail = $dbh->prepare("
            SELECT id FROM monthly_plan_details 
            WHERE plan_id = ? AND detail_id = ?
        ");
        $stmtUpdateDetail = $dbh->prepare("
            UPDATE monthly_plan_details
            SET amount = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtInsertDetail = $dbh->prepare("
            INSERT INTO monthly_plan_details (plan_id, detail_id, amount)
            VALUES (?, ?, ?)
        ");

        // フォームから送られたデータのみを処理
        if (!empty($amounts)) {
            foreach ($amounts as $detail_id => $amount) {
                $amount = (float)($amount ?? 0);
                $detail_id = (int)$detail_id;

                if ($amount > 0) {
                    $stmtCheckDetail->execute([$detail_parent_id, $detail_id]);
                    $existingId = $stmtCheckDetail->fetchColumn();

                    if ($existingId) {
                        $stmtUpdateDetail->execute([$amount, $existingId]);
                    } else {
                        $stmtInsertDetail->execute([$detail_parent_id, $detail_id, $amount]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        throw new Exception("予定の更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 予定確定処理（ステータス変更＆次の工程(Outlook)へデータ反映）
 *
 * @param array $data POSTデータ（plan_idを含む）
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function confirmMonthlyPlan(array $data, PDO $dbh)
{
    $plan_id = $data['plan_id'] ?? null;
    if (!$plan_id) {
        throw new Exception("plan_id が存在しません。");
    }

    // 1. まず更新処理を実行し、最新のデータを DB に保存（ここで status='fixed'チェックも実行される）
    updateMonthlyPlan($data, $dbh);

    try {
        // 2. ステータスを 'fixed' に更新
        $stmt = $dbh->prepare("UPDATE monthly_plan SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$plan_id]);

        // 3. 次の工程(Outlook)へデータを反映
        reflectToOutlook($plan_id, $dbh);
    } catch (Exception $e) {
        throw new Exception("予定の確定中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 予定データから月末見込み(Outlook)テーブルへデータを反映する処理
 *
 * @param int $plan_id 予定ID
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function reflectToOutlook(int $plan_id, PDO $dbh)
{
    // Outlookに反映する前に、Outlookの該当年月のデータを削除し、上書きするロジックを実装する

    // 1. 参照元の年/月/賃率情報を取得
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_plan WHERE id = ?");
    $stmt->execute([$plan_id]);
    $planInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$planInfo) {
        throw new Exception("参照元の予定データが見つかりません。");
    }

    $year = $planInfo['year'];
    $month = $planInfo['month'];
    $hourly_rate = $planInfo['hourly_rate'];

    // ----------------------------------------------------
    // Outlook テーブルへの書き込み処理開始
    // ----------------------------------------------------
    // 呼び出し元 (plan_update.php) のトランザクションに依存する

    try {
        // 1. monthly_outlook (親テーブル) への処理
        //    - year/month で既存のレコードIDを取得
        $outlookId = getMonthlyOutlookId($year, $month, $dbh);

        if ($outlookId) {
            $dbh->prepare("DELETE FROM monthly_outlook WHERE id = ?")->execute([$outlookId]);
            $outlookId = null;
        }

        // 新規 Outlook レコードの挿入
        $stmt = $dbh->prepare("
            INSERT INTO monthly_outlook (year, month, hourly_rate, status)
            VALUES (?, ?, ?, 'draft')
        ");
        $stmt->execute([$year, $month, $hourly_rate]);
        $outlookId = $dbh->lastInsertId(); // 新しい Outlook IDを取得

        if (!$outlookId) {
            throw new Exception("月末見込み(Outlook)の親レコードの挿入に失敗しました。");
        }

        // 2. monthly_outlook_time (営業所別データ) への処理
        //    - monthly_plan_time からデータをコピー
        $stmtCopyTime = $dbh->prepare("
            INSERT INTO monthly_outlook_time 
            (monthly_outlook_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
            SELECT 
                ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count
            FROM monthly_plan_time 
            WHERE monthly_plan_id = ?
        ");
        $stmtCopyTime->execute([$outlookId, $plan_id]);

        // 3. monthly_outlook_details (経費明細) への処理
        //    - monthly_plan_details からデータをコピー
        $stmtCopyDetails = $dbh->prepare("
            INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount)
            SELECT ?, detail_id, amount
            FROM monthly_plan_details
            WHERE plan_id = ?
        ");
        $stmtCopyDetails->execute([$outlookId, $plan_id]);
    } catch (Exception $e) {
        throw new Exception("月末見込み(Outlook)への反映中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 指定年月の monthly_plan ID を取得するヘルパー関数
 */
function getMonthlyPlanId(int $year, int $month, PDO $dbh): ?int
{
    $stmt = $dbh->prepare("SELECT id FROM monthly_plan WHERE year = ? AND month = ? LIMIT 1");
    $stmt->execute([$year, $month]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
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
