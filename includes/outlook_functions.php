<?php
require_once '../includes/database.php';

/**
 * 月次月末見込みの更新処理（営業所ごとの時間管理対応）
 *
 * @param array $data POSTデータ
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function updateMonthlyOutlook(array $data, PDO $dbh)
{
    if (empty($data['outlook_id'])) {
        // IDがない場合は新規登録と見なすロジック
        throw new Exception("outlook_id がありません。データが存在しない場合は、月選択時に自動で登録されます。");
    }
    $outlook_id = $data['outlook_id'];
    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];

    // ----------------------------
    // ★ 確定ステータスのチェック (Fixedデータは修正不可)
    // ----------------------------
    $stmtStatusCheck = $dbh->prepare("SELECT status FROM monthly_outlook WHERE id = ?");
    $stmtStatusCheck->execute([$outlook_id]);
    $currentStatus = $stmtStatusCheck->fetchColumn();

    if ($currentStatus === 'fixed') {
        throw new Exception("この月末見込みはすでに確定済みで、修正できません。");
    }
    // ----------------------------

    try {
        // ----------------------------
        // 1. 親テーブル (monthly_outlook) の更新
        //    - 共通賃率の更新
        // ----------------------------

        // 現状の賃率を取得
        $stmtHourlyRate = $dbh->prepare("SELECT hourly_rate FROM monthly_outlook WHERE id = ?");
        $stmtHourlyRate->execute([$outlook_id]);
        $currentRate = $stmtHourlyRate->fetchColumn();

        if ($currentRate === false) {
            throw new Exception("対象の月末見込みデータが見つかりません。");
        }

        // 賃率はどの営業所も同じ値を使用するため、最初の営業所の値または既存の値を採用する
        $firstOfficeData = reset($officeTimeData);
        $hourly_rate = (float)($firstOfficeData['hourly_rate'] ?? $currentRate ?? 0);

        // 親テーブルの更新（statusは更新時そのまま維持、hourly_rateのみ更新）
        $stmtParent = $dbh->prepare("UPDATE monthly_outlook SET hourly_rate = ? WHERE id = ?");
        $stmtParent->execute([$hourly_rate, $outlook_id]);

        // ----------------------------
        // 2. 営業所別時間データ (monthly_outlook_time) の更新/追加
        // ----------------------------
        $stmtCheckTime = $dbh->prepare("
            SELECT id FROM monthly_outlook_time 
            WHERE monthly_outlook_id = ? AND office_id = ?
        ");
        $stmtUpdateTime = $dbh->prepare("
            UPDATE monthly_outlook_time SET
                standard_hours = ?, overtime_hours = ?, transferred_hours = ?, 
                fulltime_count = ?, contract_count = ?, dispatch_count = ?
            WHERE id = ?
        ");

        $stmtInsertTime = $dbh->prepare("
            INSERT INTO monthly_outlook_time
            (monthly_outlook_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
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

            $stmtCheckTime->execute([$outlook_id, $office_id]);
            $existingId = $stmtCheckTime->fetchColumn();

            if ($existingId) {
                // 更新
                $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
            } else {
                // 新規挿入
                $stmtInsertTime->execute([$outlook_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
            }
        }

        // ----------------------------
        // 3. 勘定科目明細 (monthly_outlook_details) の更新/追加
        // ----------------------------
        $detail_parent_id = $outlook_id;

        // 既存データをチェックし、更新または挿入を行う
        $stmtCheckDetail = $dbh->prepare("
            SELECT id FROM monthly_outlook_details 
            WHERE outlook_id = ? AND detail_id = ?
        ");
        $stmtUpdateDetail = $dbh->prepare("
            UPDATE monthly_outlook_details
            SET amount = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtInsertDetail = $dbh->prepare("
            INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount)
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
        throw new Exception("月末見込みの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 月末見込み確定処理（ステータス変更＆次の工程(Result)へデータ反映）
 *
 * @param array $data POSTデータ（outlook_idを含む）
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function confirmMonthlyOutlook(array $data, PDO $dbh)
{
    $outlook_id = $data['outlook_id'] ?? null;
    if (!$outlook_id) {
        throw new Exception("outlook_id が存在しません。");
    }

    // 1. まず更新処理を実行し、最新のデータを DB に保存（ここで status='fixed'チェックも実行される）
    updateMonthlyOutlook($data, $dbh);

    try {
        // 2. ステータスを 'fixed' に更新
        $stmt = $dbh->prepare("UPDATE monthly_outlook SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$outlook_id]);

        // 3. 次の工程(Result)へデータを反映
        reflectToResult($outlook_id, $dbh);
    } catch (Exception $e) {
        throw new Exception("月末見込みの確定中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 月末見込みデータから概算実績(Result)テーブルへデータを反映する処理
 *
 * @param int $outlook_id 月末見込みID
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function reflectToResult(int $outlook_id, PDO $dbh)
{
    // Resultに反映する前に、Resultの該当年月のデータを削除し、上書きするロジックを実装する

    // 1. 参照元の年/月/賃率情報を取得
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_outlook WHERE id = ?");
    $stmt->execute([$outlook_id]);
    $outlookInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$outlookInfo) {
        throw new Exception("参照元の月末見込みデータが見つかりません。");
    }

    $year = $outlookInfo['year'];
    $month = $outlookInfo['month'];
    $hourly_rate = $outlookInfo['hourly_rate'];

    // ----------------------------------------------------
    // Result テーブルへの書き込み処理開始
    // ----------------------------------------------------
    // 呼び出し元 (outlook_update.php) のトランザクションに依存する

    try {
        // 1. monthly_result (親テーブル) への処理
        //    - year/month で既存のレコードIDを取得
        $resultId = getMonthlyResultId($year, $month, $dbh); // 新しいヘルパー関数を呼び出し

        if ($resultId) {
            // 既存の Result データを削除
            $dbh->prepare("DELETE FROM monthly_result WHERE id = ?")->execute([$resultId]);
            $resultId = null;
        }

        // 新規 Result レコードの挿入
        $stmt = $dbh->prepare("
            INSERT INTO monthly_result (year, month, hourly_rate, status)
            VALUES (?, ?, ?, 'draft')
        ");
        $stmt->execute([$year, $month, $hourly_rate]);
        $resultId = $dbh->lastInsertId(); // 新しい Result IDを取得

        if (!$resultId) {
            throw new Exception("概算実績(Result)の親レコードの挿入に失敗しました。");
        }

        // 2. monthly_result_time (営業所別データ) への処理
        //    - monthly_outlook_time からデータをコピー
        $stmtCopyTime = $dbh->prepare("
            INSERT INTO monthly_result_time 
            (monthly_result_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
            SELECT 
                ?, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count
            FROM monthly_outlook_time 
            WHERE monthly_outlook_id = ?
        ");
        $stmtCopyTime->execute([$resultId, $outlook_id]);

        // 3. monthly_result_details (経費明細) への処理
        //    - monthly_outlook_details からデータをコピー
        $stmtCopyDetails = $dbh->prepare("
            INSERT INTO monthly_result_details (result_id, detail_id, amount)
            SELECT ?, detail_id, amount
            FROM monthly_outlook_details
            WHERE outlook_id = ?
        ");
        $stmtCopyDetails->execute([$resultId, $outlook_id]);
        // トランザクションは呼び出し元 (outlook_update.php) がコミットを行うため、ここではコミットしない
    } catch (Exception $e) {
        // トランザクションは呼び出し元 (outlook_update.php) がロールバックを行うため、ここではロールバックしない
        throw new Exception("概算実績(Result)への反映中にエラーが発生しました: " . $e->getMessage());
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

/**
 * 指定年月の monthly_result ID を取得するヘルパー関数
 */
function getMonthlyResultId(int $year, int $month, PDO $dbh): ?int
{
    $stmt = $dbh->prepare("SELECT id FROM monthly_result WHERE year = ? AND month = ? LIMIT 1");
    $stmt->execute([$year, $month]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}
