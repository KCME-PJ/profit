<?php
require_once '../includes/database.php';

/**
 * 月次見通しの更新処理（営業所ごとの時間管理対応）
 *
 * @param array $data POSTデータ
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function updateMonthlyForecast(array $data, PDO $dbh)
{
    if (empty($data['monthly_forecast_id'])) {
        // IDがない場合は新規登録と見なすロジック
        throw new Exception("monthly_forecast_id がありません。データが存在しない場合は、月選択時に自動で登録されます。");
    }
    $monthly_forecast_id = $data['monthly_forecast_id'];
    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];

    // ----------------------------
    // 確定ステータスのチェック
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status FROM monthly_forecast WHERE id = ?");
    $stmtStatusCheck->execute([$monthly_forecast_id]);
    $currentStatus = $stmtStatusCheck->fetchColumn();

    if ($currentStatus === 'fixed') {
        throw new Exception("この見通しはすでに確定済みで、修正できません。");
    }
    // ----------------------------

    try {
        // ----------------------------
        // 1. 親テーブル (monthly_forecast) の更新
        //    - 共通賃率の更新
        // ----------------------------

        // 現状の賃率を取得（更新時は現状維持）
        // NOTE: $currentStatusは上で取得済み
        $stmtHourlyRate = $dbh->prepare("SELECT hourly_rate FROM monthly_forecast WHERE id = ?");
        $stmtHourlyRate->execute([$monthly_forecast_id]);
        $currentRate = $stmtHourlyRate->fetchColumn();

        // 対象データが見つからない場合のチェック
        if ($currentRate === false) {
            throw new Exception("対象の見通しデータが見つかりません。");
        }

        // 賃率はどの営業所も同じ値を使用するため、最初の営業所の値または既存の値を採用する
        $firstOfficeData = reset($officeTimeData);
        $hourly_rate = (float)($firstOfficeData['hourly_rate'] ?? $currentRate ?? 0);

        // 親テーブルの更新（statusは更新時そのまま維持、hourly_rateのみ更新）
        $stmtParent = $dbh->prepare("UPDATE monthly_forecast SET hourly_rate = ? WHERE id = ?");
        $stmtParent->execute([$hourly_rate, $monthly_forecast_id]);

        // ----------------------------
        // 2. 営業所別時間データ (monthly_forecast_time) の更新/追加
        // ----------------------------
        $stmtCheckTime = $dbh->prepare("
            SELECT id FROM monthly_forecast_time 
            WHERE monthly_forecast_id = ? AND office_id = ?
        ");
        $stmtUpdateTime = $dbh->prepare("
            UPDATE monthly_forecast_time SET
                standard_hours = ?, overtime_hours = ?, transferred_hours = ?, 
                fulltime_count = ?, contract_count = ?, dispatch_count = ?
            WHERE id = ?
        ");
        $stmtInsertTime = $dbh->prepare("
            INSERT INTO monthly_forecast_time
            (monthly_forecast_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
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

            $stmtCheckTime->execute([$monthly_forecast_id, $office_id]);
            $existingId = $stmtCheckTime->fetchColumn();

            if ($existingId) {
                // 更新
                $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
            } else {
                // 新規挿入
                $stmtInsertTime->execute([$monthly_forecast_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
            }
        }

        // ----------------------------
        // 3. 勘定科目明細 (monthly_forecast_details) の更新/追加
        // ----------------------------
        // 明細テーブルの親IDは forecast_id
        $forecast_id = $monthly_forecast_id;

        // 既存データをチェックし、更新または挿入を行う
        $stmtCheckDetail = $dbh->prepare("
            SELECT id FROM monthly_forecast_details 
            WHERE forecast_id = ? AND detail_id = ?
        ");
        $stmtUpdateDetail = $dbh->prepare("
            UPDATE monthly_forecast_details
            SET amount = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtInsertDetail = $dbh->prepare("
            INSERT INTO monthly_forecast_details (forecast_id, detail_id, amount)
            VALUES (?, ?, ?)
        ");

        // フォームから送られたデータのみを処理
        if (!empty($amounts)) {
            foreach ($amounts as $detail_id => $amount) {
                $amount = (float)($amount ?? 0);
                $detail_id = (int)$detail_id;

                if ($amount > 0) {
                    $stmtCheckDetail->execute([$forecast_id, $detail_id]);
                    $existingId = $stmtCheckDetail->fetchColumn();

                    if ($existingId) {
                        $stmtUpdateDetail->execute([$amount, $existingId]);
                    } else {
                        $stmtInsertDetail->execute([$forecast_id, $detail_id, $amount]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        throw new Exception("見通しの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 見通し確定処理（ステータス変更＆次の工程(Plan)へデータ反映）
 *
 * @param array $data POSTデータ
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function confirmMonthlyForecast(array $data, PDO $dbh)
{
    $monthly_forecast_id = $data['monthly_forecast_id'] ?? null;
    if (!$monthly_forecast_id) {
        throw new Exception("monthly_forecast_id が存在しません。");
    }

    // 1. まず更新処理を実行し、最新のデータを DB に保存（ここで status='fixed'チェックも実行される）
    // NOTE: updateMonthlyForecast はトランザクション外で実行されるため、単一でエラーを捕捉
    updateMonthlyForecast($data, $dbh);

    try {
        // 2. ステータスを 'fixed' に更新
        $stmt = $dbh->prepare("UPDATE monthly_forecast SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$monthly_forecast_id]);

        // 3. 次の工程(Plan)へデータを反映
        reflectToPlan($monthly_forecast_id, $dbh);
    } catch (Exception $e) {
        // updateMonthlyForecastが成功し、ここでエラーになった場合、更新は反映済みだがステータス更新/Plan反映に失敗
        throw new Exception("見通しの確定中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 見通しデータから予定(Plan)テーブルへデータを反映する処理
 *
 * @param int $monthly_forecast_id 見通しID
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function reflectToPlan(int $monthly_forecast_id, PDO $dbh)
{
    // Planに反映する前に、Planの該当年月のデータを削除し、上書きするロジックを実装する

    // 1. 参照元の年/月/賃率情報を取得
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_forecast WHERE id = ?");
    $stmt->execute([$monthly_forecast_id]);
    $forecastInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$forecastInfo) {
        throw new Exception("参照元の見通しデータが見つかりません。");
    }

    $year = $forecastInfo['year'];
    $month = $forecastInfo['month'];
    $hourly_rate = $forecastInfo['hourly_rate'];

    // ----------------------------------------------------
    // Plan テーブルへの書き込み処理開始
    // ----------------------------------------------------
    $dbh->beginTransaction(); // Planへの反映処理はトランザクションで保護

    try {
        // 1. monthly_plan (親テーブル) への処理
        //    - year/month で既存のレコードIDを取得
        $planId = getMonthlyPlanId($year, $month, $dbh);

        if ($planId) {
            // 既存の Plan データを削除
            $dbh->prepare("DELETE FROM monthly_plan WHERE id = ?")->execute([$planId]);
            // IDをリセット
            $planId = null;
        }

        // 新規 Plan レコードの挿入
        $stmt = $dbh->prepare("
            INSERT INTO monthly_plan (year, month, hourly_rate, status)
            VALUES (?, ?, ?, 'draft')
        ");
        $stmt->execute([$year, $month, $hourly_rate]);
        $planId = $dbh->lastInsertId(); // 新しい Plan IDを取得

        if (!$planId) {
            throw new Exception("予定(Plan)の親レコードの挿入に失敗しました。");
        }

        // 2. monthly_plan_time (営業所別データ) への処理
        //    - monthly_forecast_time からデータをコピー
        $stmtCopyTime = $dbh->prepare("
            INSERT INTO monthly_plan_time 
            (monthly_plan_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
            SELECT 
                ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count
            FROM monthly_forecast_time 
            WHERE monthly_forecast_id = ?
        ");
        $stmtCopyTime->execute([$planId, $monthly_forecast_id]);

        // 3. monthly_plan_details (経費明細) への処理
        //    - monthly_forecast_details からデータをコピー
        $stmtCopyDetails = $dbh->prepare("
            INSERT INTO monthly_plan_details (plan_id, detail_id, amount)
            SELECT ?, detail_id, amount
            FROM monthly_forecast_details
            WHERE forecast_id = ?
        ");
        $stmtCopyDetails->execute([$planId, $monthly_forecast_id]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("予定(Plan)への反映中にエラーが発生しました: " . $e->getMessage());
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
