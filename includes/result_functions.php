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
        throw new Exception("result_id がありません。データが存在しない場合は、月選択時に自動で登録されます。");
    }
    $result_id = $data['result_id'];
    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];

    // 確定ステータスのチェック (Fixedデータは修正不可)
    $stmtStatusCheck = $dbh->prepare("SELECT status FROM monthly_result WHERE id = ?");
    $stmtStatusCheck->execute([$result_id]);
    $currentStatus = $stmtStatusCheck->fetchColumn();

    if ($currentStatus === 'fixed') {
        throw new Exception("この概算実績はすでに確定済みで、修正できません。");
    }

    try {
        // 1. 親テーブル (monthly_result) の更新
        $stmtHourlyRate = $dbh->prepare("SELECT hourly_rate FROM monthly_result WHERE id = ?");
        $stmtHourlyRate->execute([$result_id]);
        $currentRate = $stmtHourlyRate->fetchColumn();

        if ($currentRate === false) {
            throw new Exception("対象の概算実績データが見つかりません。");
        }

        $firstOfficeData = reset($officeTimeData);
        $hourly_rate = (float)($firstOfficeData['hourly_rate'] ?? $currentRate ?? 0);

        $stmtParent = $dbh->prepare("UPDATE monthly_result SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
        $stmtParent->execute([$hourly_rate, $result_id]);

        // 2. 営業所別時間データ (monthly_result_time) の更新/追加
        $stmtCheckTime = $dbh->prepare("
            SELECT id FROM monthly_result_time 
            WHERE monthly_result_id = ? AND office_id = ?
        ");
        $stmtUpdateTime = $dbh->prepare("
            UPDATE monthly_result_time SET
                standard_hours = ?, overtime_hours = ?, transferred_hours = ?, 
                fulltime_count = ?, contract_count = ?, dispatch_count = ?
            WHERE id = ?
        "); // ★ 修正: updated_at = NOW() を削除 (DB自動更新に任せる)

        $stmtInsertTime = $dbh->prepare("
            INSERT INTO monthly_result_time
            (monthly_result_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count)
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

            $stmtCheckTime->execute([$result_id, $office_id]);
            $existingId = $stmtCheckTime->fetchColumn();

            if ($existingId) {
                $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
            } else {
                $stmtInsertTime->execute([$result_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
            }
        }

        // ----------------------------
        // 3. 勘定科目明細 (monthly_result_details) の更新/追加
        // ----------------------------
        $detail_parent_id = $result_id;

        $stmtCheckDetail = $dbh->prepare("
            SELECT id FROM monthly_result_details 
            WHERE result_id = ? AND detail_id = ?
        ");
        $stmtUpdateDetail = $dbh->prepare("
            UPDATE monthly_result_details
            SET amount = ?
            WHERE id = ?
        "); // ★ 修正: updated_at = NOW() を削除 (DB自動更新に任せる)

        $stmtInsertDetail = $dbh->prepare("
            INSERT INTO monthly_result_details (result_id, detail_id, amount)
            VALUES (?, ?, ?)
        ");
        // ★ 追加: DELETE文
        $stmtDeleteDetail = $dbh->prepare("
            DELETE FROM monthly_result_details
            WHERE result_id = ? AND detail_id = ?
        ");

        if (!empty($amounts)) {
            foreach ($amounts as $detail_id => $amount) {
                $amount = (float)($amount ?? 0);
                $detail_id = (int)$detail_id;

                $stmtCheckDetail->execute([$detail_parent_id, $detail_id]);
                $existingId = $stmtCheckDetail->fetchColumn();

                if ($amount > 0) {
                    if ($existingId) {
                        $stmtUpdateDetail->execute([$amount, $existingId]);
                    } else {
                        $stmtInsertDetail->execute([$detail_parent_id, $detail_id, $amount]);
                    }
                } elseif ($existingId) {
                    // ★ 追加: 金額が 0 または空で、既存のレコードがある場合は削除
                    $stmtDeleteDetail->execute([$detail_parent_id, $detail_id]);
                }
            }
        }
    } catch (Exception $e) {
        throw new Exception("概算実績の更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 概算実績確定処理（ステータス変更のみ）
 *
 * @param array $data POSTデータ（result_idを含む）
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function confirmMonthlyResult(array $data, PDO $dbh)
{
    $result_id = $data['result_id'] ?? null;
    if (!$result_id) {
        throw new Exception("result_id が存在しません。");
    }

    // 1. まず更新処理を実行し、最新のデータを DB に保存（ここで status='fixed'チェックも実行される）
    updateMonthlyResult($data, $dbh);
    try {
        // 2. ステータスを 'fixed' に更新
        $stmt = $dbh->prepare("UPDATE monthly_result SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$result_id]);

        // 3. Resultは最終工程のため、次の工程への反映 (reflectTo*) はない

    } catch (Exception $e) {
        throw new Exception("概算実績の確定中にエラーが発生しました: " . $e->getMessage());
    }
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
