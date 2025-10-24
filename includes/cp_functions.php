<?php
require_once '../includes/database.php';
require_once '../includes/common_functions.php';

/**
 * CPの新規登録処理（営業所ごとの時間管理対応）
 *
 * @param array $data POSTデータ
 * @param PDO|null $dbh
 * @throws Exception
 */
function registerMonthlyCp(array $data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $year = $data['year'] ?? null;
    $month = $data['month'] ?? null;
    // POSTのルートから直接、共通の賃率を取得する
    $hourly_rate_common = (float)($data['hourly_rate'] ?? 0);

    $officeTimeData = json_decode($data['officeTimeData'] ?? '[]', true); // JSON文字列を配列に変換
    $accountsData = $data['accounts'] ?? []; // array[detail_id] = 金額

    if (empty($year) || empty($month)) {
        throw new Exception('年度と月は必須項目です。');
    }

    // 同年度・同月の登録済みチェック
    $stmtCheck = $dbh->prepare("SELECT COUNT(*) FROM monthly_cp WHERE year = ? AND month = ?");
    $stmtCheck->execute([$year, $month]);
    if ($stmtCheck->fetchColumn() > 0) {
        throw new Exception("この年度と月のデータはすでに登録されています。");
    }

    try {
        $dbh->beginTransaction();

        // monthly_cp 登録（年度・月）
        $stmtCp = $dbh->prepare("INSERT INTO monthly_cp (year, month) VALUES (?, ?)");
        $stmtCp->execute([$year, $month]);
        $monthly_cp_id = $dbh->lastInsertId();

        // monthly_cp_time 登録（営業所ごと）
        $stmtTime = $dbh->prepare("
            INSERT INTO monthly_cp_time 
            (monthly_cp_id, office_id, standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($officeTimeData as $office_id => $time) {
            $rateToInsert = (float)($time['hourly_rate'] ?? $hourly_rate_common);
            $stmtTime->execute([
                $monthly_cp_id,
                $office_id,
                $time['standard_hours'] ?? 0,
                $time['overtime_hours'] ?? 0,
                $time['transferred_hours'] ?? 0,
                $rateToInsert, // ★ 修正後の値
                $time['fulltime_count'] ?? 0,
                $time['contract_count'] ?? 0,
                $time['dispatch_count'] ?? 0
            ]);
        }

        // monthly_cp_details 登録（フォームで送信された accounts[detail_id] 配列対応）
        $stmtDetail = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount) VALUES (?, ?, ?)");
        foreach ($accountsData as $detail_id => $amount) {
            if ($amount !== '' && $amount !== null) {
                $stmtDetail->execute([$monthly_cp_id, $detail_id, $amount]);
            }
        }

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("CPの登録中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * CPの更新処理（全国共通賃率対応版）
 *
 * @param array $post POSTデータ
 * @param PDO $dbh
 * @throws Exception
 */
function updateMonthlyCp(array $post, PDO $dbh)
{
    if (empty($post['monthly_cp_id'])) {
        throw new Exception("monthly_cp_id がありません。");
    }
    $monthly_cp_id = $post['monthly_cp_id'];

    // ----------------------------
    // 確定ステータスのチェック
    // ----------------------------
    $stmtStatus = $dbh->prepare("SELECT status FROM monthly_cp WHERE id = ?");
    $stmtStatus->execute([$monthly_cp_id]);
    $status = $stmtStatus->fetchColumn();

    if ($status === 'fixed') {
        throw new Exception("このCPはすでに確定済みで、修正できません。");
    }

    $officeTimeDataRaw = $post['officeTimeData'] ?? '[]';
    if (is_string($officeTimeDataRaw)) {
        // 文字列の場合はJSONとしてデコード
        $officeTimeData = json_decode($officeTimeDataRaw, true);
    } elseif (is_array($officeTimeDataRaw)) {
        // 既に配列の場合はそのまま使用
        $officeTimeData = $officeTimeDataRaw;
    } else {
        $officeTimeData = [];
    }
    if (!is_array($officeTimeData)) {
        throw new Exception("営業所時間データの形式が不正です。");
    }
    try {
        // ----------------------------
        // 1. 勘定科目明細の更新/追加
        // ----------------------------
        $amounts = $post['amounts'] ?? [];

        // 既存データをチェックし、更新/挿入
        $stmtCheckDetail = $dbh->prepare("
            SELECT id FROM monthly_cp_details 
            WHERE monthly_cp_id = ? AND detail_id = ?
        ");
        $stmtUpdateDetail = $dbh->prepare("
            UPDATE monthly_cp_details
            SET amount = ?,
            WHERE id = ?
        ");
        $stmtInsertDetail = $dbh->prepare("
            INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount)
            VALUES (?, ?, ?)
        ");
        $stmtDeleteDetail = $dbh->prepare("
            DELETE FROM monthly_cp_details
            WHERE monthly_cp_id = ? AND detail_id = ?
        ");

        foreach ($amounts as $detail_id => $amount) {
            $amount = (float)($amount === "" || $amount === null ? 0 : $amount);

            $stmtCheckDetail->execute([$monthly_cp_id, $detail_id]);
            $existingId = $stmtCheckDetail->fetchColumn();

            if ($amount > 0) {
                if ($existingId) {
                    $stmtUpdateDetail->execute([$amount, $existingId]);
                } else {
                    $stmtInsertDetail->execute([$monthly_cp_id, $detail_id, $amount]);
                }
            } elseif ($existingId) {
                // 金額が 0 または空で、既存のレコードがある場合は削除
                $stmtDeleteDetail->execute([$monthly_cp_id, $detail_id]);
            }
        }


        // ----------------------------
        // 2. 営業所別時間データの更新/追加（hourly_rateは全国共通で使用）
        // ----------------------------
        if (!empty($officeTimeData) && is_array($officeTimeData)) {

            $stmtCheckTime = $dbh->prepare("
                SELECT id FROM monthly_cp_time 
                WHERE monthly_cp_id = ? AND office_id = ?
            ");
            $stmtUpdateTime = $dbh->prepare("
                UPDATE monthly_cp_time SET
                    standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ?,
                    fulltime_count = ?, contract_count = ?, dispatch_count = ?
                WHERE id = ?
            ");
            $stmtInsertTime = $dbh->prepare("
                INSERT INTO monthly_cp_time
                (monthly_cp_id, office_id, standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $rate = (float)($time['hourly_rate'] ?? 0); // 賃率は各営業所データに格納

                $stmtCheckTime->execute([$monthly_cp_id, $office_id]);
                $existingId = $stmtCheckTime->fetchColumn();

                if ($existingId) {
                    $stmtUpdateTime->execute([$standard, $overtime, $transfer, $rate, $full, $contract, $dispatch, $existingId]);
                } else {
                    $stmtInsertTime->execute([$monthly_cp_id, $office_id, $standard, $overtime, $transfer, $rate, $full, $contract, $dispatch]);
                }
            }
        }
        return true;
    } catch (Exception $e) {
        throw new Exception("CPの更新中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * monthly_forecast IDを年と月から取得するヘルパー関数
 */
function getMonthlyForecastId(int $year, int $month, PDO $dbh): ?int
{
    $sql = "SELECT id FROM monthly_forecast WHERE year = :year AND month = :month";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':year' => $year, ':month' => $month]);
    $result = $stmt->fetch(PDO::FETCH_COLUMN);
    return $result !== false ? (int)$result : null;
}

/**
 * CPから見通しへ反映する処理
 * @param int $monthly_cp_id 確定するCPデータのID
 * @param PDO $dbh
 * @throws Exception
 */
function reflectToForecast(int $monthly_cp_id, PDO $dbh)
{
    $stmt = $dbh->prepare("SELECT year, month FROM monthly_cp WHERE id = ?");
    $stmt->execute([$monthly_cp_id]);
    $cpInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cpInfo) {
        throw new Exception("monthly_cp_id が不正です");
    }

    $year = $cpInfo['year'];
    $month = $cpInfo['month'];

    try {
        // =========================================================
        // 1. monthly_forecast (親テーブル) への挿入/更新
        // =========================================================

        // 確定したCPデータから共通賃率を取得
        $rateStmt = $dbh->prepare("SELECT hourly_rate FROM monthly_cp_time WHERE monthly_cp_id = ? LIMIT 1");
        $rateStmt->execute([$monthly_cp_id]);
        $hourlyRate = $rateStmt->fetchColumn() ?? 0;

        $forecastSql = "
            INSERT INTO monthly_forecast (year, month, hourly_rate, status)
            VALUES (:year, :month, :hourly_rate, 'draft') /* 修正: CP確定後、Forecastは'draft'で初期化 */
            ON DUPLICATE KEY UPDATE 
                hourly_rate = :hourly_rate_update,
                status = 'draft' /* 修正: CP確定後、Forecastは'draft'で初期化 */
        ";
        $forecastStmt = $dbh->prepare($forecastSql);
        $forecastStmt->execute([
            ':year' => $year,
            ':month' => $month,
            ':hourly_rate' => $hourlyRate,
            ':hourly_rate_update' => $hourlyRate
        ]);

        // 挿入または更新された monthly_forecast の ID を取得
        $monthlyForecastId = $dbh->lastInsertId() ?: getMonthlyForecastId($year, $month, $dbh);
        if (!$monthlyForecastId) {
            throw new Exception("monthly_forecast IDの取得に失敗しました。");
        }

        // =========================================================
        // 2. monthly_forecast_time (子テーブル) への挿入/更新
        // ... (省略: 以前のロジックをそのまま使用)
        // =========================================================

        // 既存のCP時間データ (monthly_cp_time) を取得
        $cpTimeQuery = "
            SELECT 
                office_id, standard_hours, overtime_hours, transferred_hours,
                fulltime_count, contract_count, dispatch_count
            FROM monthly_cp_time
            WHERE monthly_cp_id = :cp_id
        ";
        $cpTimeStmt = $dbh->prepare($cpTimeQuery);
        $cpTimeStmt->execute([':cp_id' => $monthly_cp_id]);
        $cpTimeData = $cpTimeStmt->fetchAll(PDO::FETCH_ASSOC);

        // 挿入・更新を効率的に行うための SQL
        $timeSql = "
            INSERT INTO monthly_forecast_time (
                monthly_forecast_id, office_id, standard_hours, overtime_hours, transferred_hours,
                fulltime_count, contract_count, dispatch_count
            ) VALUES (
                :forecast_id, :office_id, :sh, :oh, :th, :fc, :cc, :dc
            ) ON DUPLICATE KEY UPDATE
                standard_hours = VALUES(standard_hours),
                overtime_hours = VALUES(overtime_hours),
                transferred_hours = VALUES(transferred_hours),
                fulltime_count = VALUES(fulltime_count),
                contract_count = VALUES(contract_count),
                dispatch_count = VALUES(dispatch_count),
                updated_at = NOW()
        ";
        $timeStmt = $dbh->prepare($timeSql);

        foreach ($cpTimeData as $timeRow) {
            $timeStmt->execute([
                ':forecast_id' => $monthlyForecastId,
                ':office_id' => $timeRow['office_id'],
                ':sh' => $timeRow['standard_hours'],
                ':oh' => $timeRow['overtime_hours'],
                ':th' => $timeRow['transferred_hours'],
                ':fc' => $timeRow['fulltime_count'],
                ':cc' => $timeRow['contract_count'],
                ':dc' => $timeRow['dispatch_count']
            ]);
        }

        // =========================================================
        // 3. monthly_forecast_details (経費明細) への挿入/更新
        // =========================================================

        // 既存の明細データを全て削除してから再挿入する
        $dbh->prepare("DELETE FROM monthly_forecast_details WHERE forecast_id = :forecast_id")
            ->execute([':forecast_id' => $monthlyForecastId]);

        $cpDetailQuery = "
            SELECT detail_id, amount
            FROM monthly_cp_details
            WHERE monthly_cp_id = :cp_id
        ";
        $cpDetailStmt = $dbh->prepare($cpDetailQuery);
        $cpDetailStmt->execute([':cp_id' => $monthly_cp_id]);
        $cpDetailData = $cpDetailStmt->fetchAll(PDO::FETCH_ASSOC);
        $detailSql = "
            INSERT INTO monthly_forecast_details (forecast_id, detail_id, amount)
            VALUES (?, ?, ?)
        ";
        $detailStmt = $dbh->prepare($detailSql);

        foreach ($cpDetailData as $row) {
            if ($row['amount'] > 0) {
                $detailStmt->execute([$monthlyForecastId, $row['detail_id'], $row['amount']]);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Forecast反映エラー: " . $e->getMessage());
        throw $e; // 呼び出し元にエラーを再スロー
    }
}

/**
 * CPの確定処理（更新と見通しへの反映を含む）
 *
 * @param array $data POSTデータ
 * @param PDO|null $dbh
 * @throws Exception
 */
function confirmMonthlyCp(array $data, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $monthly_cp_id = $data['monthly_cp_id'] ?? null;
    if (!$monthly_cp_id) {
        throw new Exception("monthly_cp_id が存在しません。");
    }

    $dbh->beginTransaction();

    try {
        // 1. 最新の入力内容でCPを更新（この中でステータスチェックが行われる）
        updateMonthlyCp($data, $dbh);

        // 2. CPのステータスを'fixed'に更新
        $stmt = $dbh->prepare("UPDATE monthly_cp SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$monthly_cp_id]);

        // 3. 確定済みデータをForecastへ反映
        reflectToForecast($monthly_cp_id, $dbh);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollBack();
        throw new Exception("CPの確定中にエラーが発生しました: " . $e->getMessage());
    }
}
