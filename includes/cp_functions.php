<?php
require_once '../includes/database.php';
require_once '../includes/common_functions.php';

/**
 * CPの新規登録処理（営業所ごとの時間管理対応）
 * * NOTE: トランザクション管理は呼び出し元で行う
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
    $hourly_rate_common = (float)($data['hourly_rate'] ?? 0);

    $officeTimeData = json_decode($data['officeTimeData'] ?? '[]', true);
    $accountsData = $data['accounts'] ?? [];
    $revenuesData = $data['revenues'] ?? [];

    if (empty($year) || empty($month)) {
        throw new Exception('年度と月は必須項目です。');
    }

    $stmtCheck = $dbh->prepare("SELECT COUNT(*) FROM monthly_cp WHERE year = ? AND month = ?");
    $stmtCheck->execute([$year, $month]);
    if ($stmtCheck->fetchColumn() > 0) {
        throw new Exception("この年度と月のデータはすでに登録されています。");
    }

    try {
        // 1. monthly_cp 登録
        $stmtCp = $dbh->prepare("INSERT INTO monthly_cp (year, month) VALUES (?, ?)");
        $stmtCp->execute([$year, $month]);
        $monthly_cp_id = $dbh->lastInsertId();

        // 2. monthly_cp_time 登録
        $stmtTime = $dbh->prepare("
            INSERT INTO monthly_cp_time 
            (monthly_cp_id, office_id, standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count, type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'cp')
        ");

        if (is_array($officeTimeData)) {
            foreach ($officeTimeData as $office_id => $time) {
                $rateToInsert = $hourly_rate_common;
                $stmtTime->execute([
                    $monthly_cp_id,
                    $office_id,
                    $time['standard_hours'] ?? 0,
                    $time['overtime_hours'] ?? 0,
                    $time['transferred_hours'] ?? 0,
                    $rateToInsert,
                    $time['fulltime_count'] ?? 0,
                    $time['contract_count'] ?? 0,
                    $time['dispatch_count'] ?? 0
                ]);
            }
        }

        // 3. 経費明細登録
        $stmtDetail = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount, type) VALUES (?, ?, ?, 'cp')");
        if (is_array($accountsData)) {
            foreach ($accountsData as $detail_id => $amount) {
                $amountValue = (float)($amount ?? 0);
                if ($amountValue != 0) {
                    $stmtDetail->execute([$monthly_cp_id, $detail_id, $amountValue]);
                }
            }
        }

        // 4. 収入明細登録
        $stmtRevenue = $dbh->prepare("INSERT INTO monthly_cp_revenues (monthly_cp_id, revenue_item_id, amount) VALUES (?, ?, ?)");
        if (is_array($revenuesData)) {
            foreach ($revenuesData as $revenue_item_id => $amount) {
                $amountValue = (float)($amount ?? 0);
                if ($amountValue != 0) {
                    $stmtRevenue->execute([$monthly_cp_id, $revenue_item_id, $amountValue]);
                }
            }
        }
    } catch (Exception $e) {
        // ★修正: ロールバック(rollBack)を削除 (呼び出し元でキャッチしてロールバックする)
        throw new Exception("CPの登録中にエラーが発生しました: " . $e->getMessage());
    }
}

/**
 * CPの更新処理（全国共通賃率対応版）
 * * NOTE: トランザクション管理は呼び出し元で行う
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

    // 確定チェック
    $stmtStatus = $dbh->prepare("SELECT status FROM monthly_cp WHERE id = ?");
    $stmtStatus->execute([$monthly_cp_id]);
    $status = $stmtStatus->fetchColumn();

    if ($status === 'fixed') {
        throw new Exception("このCPはすでに確定済みで、修正できません。");
    }

    $officeTimeDataRaw = $post['officeTimeData'] ?? '[]';
    $officeTimeData = is_string($officeTimeDataRaw) ? json_decode($officeTimeDataRaw, true) : $officeTimeDataRaw;
    if (!is_array($officeTimeData)) {
        $officeTimeData = [];
    }

    // 賃率決定ロジック (隠しフィールド対応)
    if (isset($post['hourly_rate']) && $post['hourly_rate'] !== '') {
        $hourly_rate_common = (float)$post['hourly_rate'];
    } else {
        $stmtRate = $dbh->prepare("SELECT hourly_rate FROM monthly_cp_time WHERE monthly_cp_id = ? AND type = 'cp' LIMIT 1");
        $stmtRate->execute([$monthly_cp_id]);
        $hourly_rate_common = (float)($stmtRate->fetchColumn() ?? 0);
    }

    try {
        $amounts = $post['amounts'] ?? [];
        $revenues = $post['revenues'] ?? [];

        // 1. 経費明細更新
        $stmtCheckDetail = $dbh->prepare("SELECT id FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ? AND type = 'cp'");
        $stmtUpdateDetail = $dbh->prepare("UPDATE monthly_cp_details SET amount = ? WHERE id = ?");
        $stmtInsertDetail = $dbh->prepare("INSERT INTO monthly_cp_details (monthly_cp_id, detail_id, amount, type) VALUES (?, ?, ?, 'cp')");
        $stmtDeleteDetail = $dbh->prepare("DELETE FROM monthly_cp_details WHERE monthly_cp_id = ? AND detail_id = ? AND type = 'cp'");

        foreach ($amounts as $detail_id => $amount) {
            $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
            $detail_id = (int)$detail_id;

            $stmtCheckDetail->execute([$monthly_cp_id, $detail_id]);
            $existingId = $stmtCheckDetail->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateDetail->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertDetail->execute([$monthly_cp_id, $detail_id, $amountValue]);
                }
            } elseif ($existingId) {
                $stmtDeleteDetail->execute([$monthly_cp_id, $detail_id]);
            }
        }

        // 2. 収入明細更新
        $stmtCheckRev = $dbh->prepare("SELECT id FROM monthly_cp_revenues WHERE monthly_cp_id = ? AND revenue_item_id = ?");
        $stmtUpdateRev = $dbh->prepare("UPDATE monthly_cp_revenues SET amount = ? WHERE id = ?");
        $stmtInsertRev = $dbh->prepare("INSERT INTO monthly_cp_revenues (monthly_cp_id, revenue_item_id, amount) VALUES (?, ?, ?)");
        $stmtDeleteRev = $dbh->prepare("DELETE FROM monthly_cp_revenues WHERE monthly_cp_id = ? AND revenue_item_id = ?");

        foreach ($revenues as $revenue_item_id => $amount) {
            $amountValue = (float)($amount === "" || $amount === null ? 0 : $amount);
            $revenue_item_id = (int)$revenue_item_id;

            $stmtCheckRev->execute([$monthly_cp_id, $revenue_item_id]);
            $existingId = $stmtCheckRev->fetchColumn();

            if ($amountValue != 0) {
                if ($existingId) {
                    $stmtUpdateRev->execute([$amountValue, $existingId]);
                } else {
                    $stmtInsertRev->execute([$monthly_cp_id, $revenue_item_id, $amountValue]);
                }
            } elseif ($existingId) {
                $stmtDeleteRev->execute([$monthly_cp_id, $revenue_item_id]);
            }
        }

        // 3. 時間データ更新
        if (!empty($officeTimeData)) {
            $stmtCheckTime = $dbh->prepare("SELECT id FROM monthly_cp_time WHERE monthly_cp_id = ? AND office_id = ? AND type = 'cp'");
            $stmtUpdateTime = $dbh->prepare("
                UPDATE monthly_cp_time SET
                    standard_hours = ?, overtime_hours = ?, transferred_hours = ?, hourly_rate = ?,
                    fulltime_count = ?, contract_count = ?, dispatch_count = ?
                WHERE id = ?
            ");
            $stmtInsertTime = $dbh->prepare("
                INSERT INTO monthly_cp_time
                (monthly_cp_id, office_id, standard_hours, overtime_hours, transferred_hours, hourly_rate, fulltime_count, contract_count, dispatch_count, type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'cp')
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

                $rate = $hourly_rate_common;

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
        // 1. Forecast親テーブル
        $rateStmt = $dbh->prepare("SELECT hourly_rate FROM monthly_cp_time WHERE monthly_cp_id = ? AND type = 'cp' LIMIT 1");
        $rateStmt->execute([$monthly_cp_id]);
        $hourlyRate = $rateStmt->fetchColumn() ?? 0;

        $forecastSql = "
            INSERT INTO monthly_forecast (year, month, hourly_rate, status)
            VALUES (:year, :month, :hourly_rate, 'draft')
            ON DUPLICATE KEY UPDATE 
                hourly_rate = VALUES(hourly_rate),
                status = 'draft'
        ";
        $forecastStmt = $dbh->prepare($forecastSql);
        $forecastStmt->execute([
            ':year' => $year,
            ':month' => $month,
            ':hourly_rate' => $hourlyRate
        ]);

        $monthlyForecastId = $dbh->lastInsertId() ?: getMonthlyForecastId($year, $month, $dbh);
        if (!$monthlyForecastId) {
            throw new Exception("monthly_forecast IDの取得に失敗しました。");
        }

        // 2. 時間データ
        $cpTimeQuery = "
            SELECT 
                office_id, standard_hours, overtime_hours, transferred_hours,
                fulltime_count, contract_count, dispatch_count
            FROM monthly_cp_time
            WHERE monthly_cp_id = :cp_id AND type = 'cp'
        ";
        $cpTimeStmt = $dbh->prepare($cpTimeQuery);
        $cpTimeStmt->execute([':cp_id' => $monthly_cp_id]);
        $cpTimeData = $cpTimeStmt->fetchAll(PDO::FETCH_ASSOC);

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

        // 3. 経費詳細
        $dbh->prepare("DELETE FROM monthly_forecast_details WHERE forecast_id = :forecast_id")
            ->execute([':forecast_id' => $monthlyForecastId]);

        $cpDetailQuery = "SELECT detail_id, amount FROM monthly_cp_details WHERE monthly_cp_id = :cp_id AND type = 'cp'";
        $cpDetailStmt = $dbh->prepare($cpDetailQuery);
        $cpDetailStmt->execute([':cp_id' => $monthly_cp_id]);
        $cpDetailData = $cpDetailStmt->fetchAll(PDO::FETCH_ASSOC);

        $detailSql = "INSERT INTO monthly_forecast_details (forecast_id, detail_id, amount) VALUES (?, ?, ?)";
        $stmtDetail = $dbh->prepare($detailSql);

        foreach ($cpDetailData as $row) {
            $amountValue = (float)($row['amount'] ?? 0);
            if ($amountValue != 0) {
                $stmtDetail->execute([$monthlyForecastId, $row['detail_id'], $amountValue]);
            }
        }

        // 4. 収入詳細
        $dbh->prepare("DELETE FROM monthly_forecast_revenues WHERE forecast_id = :forecast_id")
            ->execute([':forecast_id' => $monthlyForecastId]);

        $cpRevenueQuery = "SELECT revenue_item_id, amount FROM monthly_cp_revenues WHERE monthly_cp_id = :cp_id";
        $cpRevenueStmt = $dbh->prepare($cpRevenueQuery);
        $cpRevenueStmt->execute([':cp_id' => $monthly_cp_id]);
        $cpRevenueData = $cpRevenueStmt->fetchAll(PDO::FETCH_ASSOC);

        $revenueSql = "INSERT INTO monthly_forecast_revenues (forecast_id, revenue_item_id, amount) VALUES (?, ?, ?)";
        $stmtRevenue = $dbh->prepare($revenueSql);

        foreach ($cpRevenueData as $row) {
            $amountValue = (float)($row['amount'] ?? 0);
            if ($amountValue != 0) {
                $stmtRevenue->execute([$monthlyForecastId, $row['revenue_item_id'], $amountValue]);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Forecast反映エラー: " . $e->getMessage());
        throw $e;
    }
}

/**
 * CPの確定処理（更新と見通しへの反映を含む）
 * * NOTE: トランザクション管理は呼び出し元で行う
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

    try {

        // 1. CP更新
        updateMonthlyCp($data, $dbh);

        // 2. ステータス変更
        $stmt = $dbh->prepare("UPDATE monthly_cp SET status = 'fixed' WHERE id = ?");
        $stmt->execute([$monthly_cp_id]);

        // 3. Reflect to Forecast
        reflectToForecast($monthly_cp_id, $dbh);
    } catch (Exception $e) {
        throw new Exception("CPの確定中にエラーが発生しました: " . $e->getMessage());
    }
}
