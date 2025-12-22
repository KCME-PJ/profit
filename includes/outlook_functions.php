<?php
require_once '../includes/database.php';

/**
 * 月末見込みの更新処理（営業所ごとの時間管理対応）
 *
 * @param array $data POSTデータ
 * @param PDO $dbh DBハンドル
 * @throws Exception
 */
function updateMonthlyOutlook(array $data, PDO $dbh)
{
    if (empty($data['outlook_id'])) {
        throw new Exception("outlook_id がありません。");
    }
    $outlook_id = $data['outlook_id'];
    $officeTimeData = $data['officeTimeData'] ?? [];
    $amounts = $data['amounts'] ?? [];
    $revenues = $data['revenues'] ?? [];

    // 確定ステータスのチェック
    $stmtStatusCheck = $dbh->prepare("SELECT status, hourly_rate FROM monthly_outlook WHERE id = ?");
    $stmtStatusCheck->execute([$outlook_id]);
    $currentData = $stmtStatusCheck->fetch(PDO::FETCH_ASSOC);

    if (!$currentData) {
        throw new Exception("対象のデータが見つかりません。");
    }
    if (($currentData['status'] ?? '') === 'fixed') {
        throw new Exception("この見込みはすでに確定済みで、修正できません。");
    }

    // 賃率の決定ロジック
    // 入力値が存在すればそれを使い、なければ(null)既存の値を維持する
    if (isset($data['hourly_rate']) && $data['hourly_rate'] !== null) {
        $hourly_rate = (float)$data['hourly_rate'];
    } else {
        $hourly_rate = (float)$currentData['hourly_rate'];
    }

    try {
        // 1. 親テーブル (monthly_outlook) の更新 (共通賃率)
        $stmtParent = $dbh->prepare("UPDATE monthly_outlook SET hourly_rate = ?, updated_at = NOW() WHERE id = ?");
        $stmtParent->execute([$hourly_rate, $outlook_id]);

        // 2. 営業所別時間データ (monthly_outlook_time) の更新/追加
        $stmtCheckTime = $dbh->prepare("SELECT id FROM monthly_outlook_time WHERE monthly_outlook_id = ? AND office_id = ?");
        $stmtUpdateTime = $dbh->prepare("UPDATE monthly_outlook_time SET standard_hours = ?, overtime_hours = ?, transferred_hours = ?, fulltime_count = ?, contract_count = ?, dispatch_count = ?, updated_at = NOW() WHERE id = ?");
        $stmtInsertTime = $dbh->prepare("INSERT INTO monthly_outlook_time (monthly_outlook_id, office_id, standard_hours, overtime_hours, transferred_hours, fulltime_count, contract_count, dispatch_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtDeleteTime = $dbh->prepare("DELETE FROM monthly_outlook_time WHERE monthly_outlook_id = ? AND office_id = ?");

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

            // 0件の場合削除
            if ($standard == 0 && $overtime == 0 && $transfer == 0 && $full == 0 && $contract == 0 && $dispatch == 0) {
                if ($existingId) {
                    $stmtDeleteTime->execute([$outlook_id, $office_id]);
                }
            } else {
                if ($existingId) {
                    $stmtUpdateTime->execute([$standard, $overtime, $transfer, $full, $contract, $dispatch, $existingId]);
                } else {
                    $stmtInsertTime->execute([$outlook_id, $office_id, $standard, $overtime, $transfer, $full, $contract, $dispatch]);
                }
            }
        }

        // 3. 勘定科目明細 (経費) の更新/追加
        $stmtCheckDetail = $dbh->prepare("SELECT id FROM monthly_outlook_details WHERE outlook_id = ? AND detail_id = ?");
        $stmtUpdateDetail = $dbh->prepare("UPDATE monthly_outlook_details SET amount = ? WHERE id = ?");
        $stmtInsertDetail = $dbh->prepare("INSERT INTO monthly_outlook_details (outlook_id, detail_id, amount) VALUES (?, ?, ?)");
        $stmtDeleteDetail = $dbh->prepare("DELETE FROM monthly_outlook_details WHERE outlook_id = ? AND detail_id = ?");

        if (!empty($amounts)) {
            foreach ($amounts as $detail_id => $amount) {
                $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
                $detail_id = (int)$detail_id;

                $stmtCheckDetail->execute([$outlook_id, $detail_id]);
                $existingId = $stmtCheckDetail->fetchColumn();

                if ($amountValue != 0) {
                    if ($existingId) {
                        $stmtUpdateDetail->execute([$amountValue, $existingId]);
                    } else {
                        $stmtInsertDetail->execute([$outlook_id, $detail_id, $amountValue]);
                    }
                } elseif ($existingId) {
                    $stmtDeleteDetail->execute([$outlook_id, $detail_id]);
                }
            }
        }

        // 4. 収入明細 (monthly_outlook_revenues) の更新/追加
        $stmtCheckRev = $dbh->prepare("SELECT id FROM monthly_outlook_revenues WHERE outlook_id = ? AND revenue_item_id = ?");
        $stmtUpdateRev = $dbh->prepare("UPDATE monthly_outlook_revenues SET amount = ? WHERE id = ?");
        $stmtInsertRev = $dbh->prepare("INSERT INTO monthly_outlook_revenues (outlook_id, revenue_item_id, amount) VALUES (?, ?, ?)");
        $stmtDeleteRev = $dbh->prepare("DELETE FROM monthly_outlook_revenues WHERE outlook_id = ? AND revenue_item_id = ?");

        if (!empty($revenues)) {
            foreach ($revenues as $revenue_item_id => $amount) {
                $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
                $revenue_item_id = (int)$revenue_item_id;

                $stmtCheckRev->execute([$outlook_id, $revenue_item_id]);
                $existingId = $stmtCheckRev->fetchColumn();

                if ($amountValue != 0) {
                    if ($existingId) {
                        $stmtUpdateRev->execute([$amountValue, $existingId]);
                    } else {
                        $stmtInsertRev->execute([$outlook_id, $revenue_item_id, $amountValue]);
                    }
                } elseif ($existingId) {
                    $stmtDeleteRev->execute([$outlook_id, $revenue_item_id]);
                }
            }
        }
    } catch (Exception $e) {
        throw new Exception("月末見込みの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * 月末見込み確定処理（ステータス変更＆次の工程(Result)へデータ反映）
 */
function confirmMonthlyOutlook(array $data, PDO $dbh)
{
    $outlook_id = $data['outlook_id'] ?? null;
    if (!$outlook_id) {
        throw new Exception("outlook_id が存在しません。");
    }

    // 1. まず更新処理を実行し、最新のデータを DB に保存
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
 */
function reflectToResult(int $outlook_id, PDO $dbh)
{
    // 1. 参照元の年/月/賃率情報を取得
    $stmt = $dbh->prepare("SELECT year, month, hourly_rate FROM monthly_outlook WHERE id = ?");
    $stmt->execute([$outlook_id]);
    $outlookInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$outlookInfo) {
        throw new Exception("参照元の月末見込みデータが見つかりません。");
    }

    $year = $outlookInfo['year'];
    $month = $outlookInfo['month'];

    try {
        // 1. monthly_result (親テーブル) への処理
        $resultId = getMonthlyResultId($year, $month, $dbh);

        if ($resultId) {
            // 既存の Result データを削除
            $dbh->prepare("DELETE FROM monthly_result WHERE id = ?")->execute([$resultId]);
            $resultId = null;
        }

        // 新規 Result レコードの挿入
        $stmt = $dbh->prepare("
            INSERT INTO monthly_result (year, month, hourly_rate, status)
            SELECT year, month, hourly_rate, 'draft'
            FROM monthly_outlook
            WHERE id = ?
        ");
        $stmt->execute([$outlook_id]);

        $resultId = $dbh->lastInsertId();

        if (!$resultId) {
            throw new Exception("概算実績(Result)の親レコードの挿入に失敗しました。");
        }

        // 2. monthly_result_time (営業所別データ) への処理
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
        $stmtCopyDetails = $dbh->prepare("
            INSERT INTO monthly_result_details (result_id, detail_id, amount)
            SELECT ?, detail_id, amount
            FROM monthly_outlook_details
            WHERE outlook_id = ?
        ");
        $stmtCopyDetails->execute([$resultId, $outlook_id]);

        // 4. monthly_result_revenues (収入明細) への処理
        $stmtCopyRevenues = $dbh->prepare("
            INSERT INTO monthly_result_revenues (result_id, revenue_item_id, amount)
            SELECT ?, revenue_item_id, amount
            FROM monthly_outlook_revenues
            WHERE outlook_id = ?
        ");
        $stmtCopyRevenues->execute([$resultId, $outlook_id]);
    } catch (Exception $e) {
        throw new Exception("概算実績(Result)への反映中にエラーが発生しました: " . $e->getMessage());
    }
}

function getMonthlyResultId(int $year, int $month, PDO $dbh): ?int
{
    $stmt = $dbh->prepare("SELECT id FROM monthly_result WHERE year = ? AND month = ? LIMIT 1");
    $stmt->execute([$year, $month]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}
