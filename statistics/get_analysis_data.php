<?php
// statistics/get_analysis_data.php

require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json');

try {
    $dbh = getDb();

    // --- パラメータ取得 ---
    $monthsParam = $_GET['months'] ?? [];
    $officeId = $_GET['office'] ?? 'all';

    if (empty($monthsParam) || !is_array($monthsParam)) {
        throw new Exception("対象年月が選択されていません。");
    }

    // --- 初期化 ---
    $accounts = $dbh->query("SELECT id, name FROM accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $summaryRows = [
        ['id' => 'revenue_total', 'name' => '収入合計', 'type' => 'calc'],
        ['id' => 'expense_total', 'name' => '経費合計', 'type' => 'calc'],
    ];

    foreach ($accounts as $acc) {
        $summaryRows[] = ['id' => 'acc_' . $acc['id'], 'name' => $acc['name'], 'type' => 'account', 'db_id' => $acc['id']];
    }

    $fixedRows = [
        ['id' => 'gross_profit', 'name' => '差引収益', 'type' => 'calc'],
        ['id' => 'total_hours', 'name' => '総時間', 'type' => 'time'],
        ['id' => 'standard_hours', 'name' => '定時間', 'type' => 'time'],
        ['id' => 'overtime_hours', 'name' => '残業時間', 'type' => 'time'],
        ['id' => 'transferred_hours', 'name' => '振替時間', 'type' => 'time'],
        ['id' => 'labor_cost', 'name' => '労務費', 'type' => 'calc'],
        ['id' => 'pre_tax_profit', 'name' => '税引前利益', 'type' => 'calc'],
        ['id' => 'headcount_total', 'name' => '人員(正+契)', 'type' => 'headcount'],
        ['id' => 'headcount_full', 'name' => '正社員', 'type' => 'headcount'],
        ['id' => 'headcount_contract', 'name' => '契約社員', 'type' => 'headcount'],
        ['id' => 'headcount_dispatch', 'name' => '派遣社員', 'type' => 'headcount'],
    ];
    $summaryRows = array_merge($summaryRows, $fixedRows);

    $aggregated = [];
    foreach ($summaryRows as $row) {
        $aggregated[$row['id']] = [
            'cp' => 0,
            'plan' => 0,
            'result' => 0,
            'labor_cost_cp' => 0,
            'labor_cost_plan' => 0,
            'labor_cost_result' => 0
        ];
    }

    // 詳細データ保持配列
    $detailsBuffer = [];

    // --- データ取得 & 集計ループ ---
    $sources = ['cp', 'plan', 'result'];

    foreach ($sources as $sourceType) {
        $tablePrefix = "monthly_" . $sourceType;

        $officeFilterTime = "";
        $officeFilterDetails = "";
        $officeFilterRevenue = ""; // 収入用のフィルターを追加
        $paramsBase = [];

        if ($officeId !== 'all') {
            $officeFilterTime = " AND t.office_id = :oid ";
            $officeFilterDetails = " AND det.office_id = :oid ";
            $officeFilterRevenue = " AND ri.office_id = :oid "; // 収入項目の営業所紐付けでフィルタ
            $paramsBase[':oid'] = $officeId;
        }

        foreach ($monthsParam as $ymStr) {
            $parts = explode('-', $ymStr);
            if (count($parts) !== 2) continue;
            $year = $parts[0];
            $month = $parts[1];

            // 1. 親テーブルID取得
            $sqlParent = "SELECT id, hourly_rate FROM " . $tablePrefix . " WHERE year = :year AND month = :month AND status = 'fixed'";
            if ($sourceType === 'cp') {
                $sqlParent = "SELECT id, 0 as hourly_rate FROM " . $tablePrefix . " WHERE year = :year AND month = :month AND status = 'fixed'";
            }

            $stmt = $dbh->prepare($sqlParent);
            $stmt->execute([':year' => $year, ':month' => $month]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$parent) continue;

            $parentId = $parent['id'];
            $baseHourlyRate = $parent['hourly_rate'];

            // FK名
            $fkName = ($sourceType === 'cp') ? 'monthly_cp_id' : $sourceType . '_id';

            $paramsDetails = array_merge([':pid' => $parentId], $paramsBase);
            $monthLabel = formatMonthJP($year, $month);

            // --- [追加] 営業所別集計用の一時配列初期化 ---
            // キー: office_id, 値: ['name'=>..., 'code'=>..., 'rev'=>0, 'exp'=>0, 'labor'=>0]
            $officeMonthStats = [];

            // --- A. 経費詳細集計 (勘定科目別) ---
            $sqlDet = "
                SELECT a.id as acc_id, a.name as acc_name, d.detail_id, det.name as detail_name, SUM(d.amount) as amount
                FROM " . $tablePrefix . "_details d
                JOIN details det ON d.detail_id = det.id
                JOIN accounts a ON det.account_id = a.id
                WHERE d." . $fkName . " = :pid " . $officeFilterDetails . "
                GROUP BY a.id, a.name, d.detail_id, det.name
            ";
            $stmtDet = $dbh->prepare($sqlDet);
            $stmtDet->execute($paramsDetails);
            $rowsDet = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rowsDet as $row) {
                $accKey = 'acc_' . $row['acc_id'];
                if (isset($aggregated[$accKey])) $aggregated[$accKey][$sourceType] += $row['amount'];
                $aggregated['expense_total'][$sourceType] += $row['amount'];

                // 詳細データ（勘定科目別）
                $uniqueKey = $accKey . '_' . $row['detail_id'] . '_' . $ymStr;
                if (!isset($detailsBuffer[$accKey][$uniqueKey])) {
                    $detailsBuffer[$accKey][$uniqueKey] = [
                        'detail_name' => $row['detail_name'],
                        'month_name' => $monthLabel,
                        'sort_key' => $ymStr . '_' . $row['detail_name'],
                        'cp' => 0,
                        'plan' => 0,
                        'result' => 0
                    ];
                }
                $detailsBuffer[$accKey][$uniqueKey][$sourceType] += $row['amount'];
            }

            // --- [追加] 経費の営業所別集計 (計算用) ---
            // 勘定科目別とは別に、営業所IDでグルーピングして取得
            $sqlExpOffice = "
                SELECT det.office_id, o.name as office_name, o.identifier, SUM(d.amount) as amount
                FROM " . $tablePrefix . "_details d
                JOIN details det ON d.detail_id = det.id
                LEFT JOIN offices o ON det.office_id = o.id
                WHERE d." . $fkName . " = :pid " . $officeFilterDetails . "
                GROUP BY det.office_id, o.name, o.identifier
            ";
            $stmtExpOffice = $dbh->prepare($sqlExpOffice);
            $stmtExpOffice->execute($paramsDetails);
            while ($row = $stmtExpOffice->fetch(PDO::FETCH_ASSOC)) {
                $oid = $row['office_id'] ?? 0;
                if (!isset($officeMonthStats[$oid])) {
                    $officeMonthStats[$oid] = initOfficeStats($row['office_name'], $row['identifier']);
                }
                $officeMonthStats[$oid]['exp'] += $row['amount'];
            }

            // --- B. 収入詳細集計 (項目別) ---
            // ※既存コードでは収入に $officeFilterDetails が適用されていなかったので修正して適用
            $sqlRev = "
                SELECT ri.id as item_id, ri.name as item_name, SUM(r.amount) as amount
                FROM " . $tablePrefix . "_revenues r
                JOIN revenue_items ri ON r.revenue_item_id = ri.id
                WHERE r." . $fkName . " = :pid " . $officeFilterRevenue . "
                GROUP BY ri.id, ri.name
            ";
            $stmtRev = $dbh->prepare($sqlRev);
            $stmtRev->execute($paramsDetails);
            $rowsRev = $stmtRev->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rowsRev as $row) {
                $aggregated['revenue_total'][$sourceType] += $row['amount'];

                $revKey = 'revenue_total';
                $uniqueKey = $revKey . '_' . $row['item_id'] . '_' . $ymStr;
                if (!isset($detailsBuffer[$revKey][$uniqueKey])) {
                    $detailsBuffer[$revKey][$uniqueKey] = [
                        'detail_name' => $row['item_name'],
                        'month_name' => $monthLabel,
                        'sort_key' => $ymStr . '_' . $row['item_name'],
                        'cp' => 0,
                        'plan' => 0,
                        'result' => 0
                    ];
                }
                $detailsBuffer[$revKey][$uniqueKey][$sourceType] += $row['amount'];
            }

            // --- [追加] 収入の営業所別集計 (計算用) ---
            $sqlRevOffice = "
                SELECT ri.office_id, o.name as office_name, o.identifier, SUM(r.amount) as amount
                FROM " . $tablePrefix . "_revenues r
                JOIN revenue_items ri ON r.revenue_item_id = ri.id
                LEFT JOIN offices o ON ri.office_id = o.id
                WHERE r." . $fkName . " = :pid " . $officeFilterRevenue . "
                GROUP BY ri.office_id, o.name, o.identifier
            ";
            $stmtRevOffice = $dbh->prepare($sqlRevOffice);
            $stmtRevOffice->execute($paramsDetails);
            while ($row = $stmtRevOffice->fetch(PDO::FETCH_ASSOC)) {
                $oid = $row['office_id'] ?? 0;
                if (!isset($officeMonthStats[$oid])) {
                    $officeMonthStats[$oid] = initOfficeStats($row['office_name'], $row['identifier']);
                }
                $officeMonthStats[$oid]['rev'] += $row['amount'];
            }


            // --- C. 時間・人員集計 (Time) ---
            $rateCol = ($sourceType === 'cp') ? 't.hourly_rate' : ':base_rate';
            $timeFkColumn = 'monthly_' . $sourceType . '_id';

            // 1. 詳細表示用：営業所ごとの集計
            $sqlTimeDetails = "
                SELECT 
                    t.office_id,
                    o.name as office_name,
                    o.identifier as office_code,
                    SUM(t.standard_hours) as std,
                    SUM(t.overtime_hours) as ovt,
                    SUM(t.transferred_hours) as trans,
                    SUM(t.fulltime_count) as ful,
                    SUM(t.contract_count) as cont,
                    SUM(t.dispatch_count) as disp,
                    SUM( (t.standard_hours + t.overtime_hours + t.transferred_hours) * " . $rateCol . " ) as labor_cost
                FROM " . $tablePrefix . "_time t
                LEFT JOIN offices o ON t.office_id = o.id
                WHERE t." . $timeFkColumn . " = :pid 
                " . ($officeId !== 'all' ? " AND t.office_id = :oid " : "") . "
                GROUP BY t.office_id, o.name, o.identifier
                ORDER BY o.identifier
            ";

            $paramsTimeDetails = array_merge([':pid' => $parentId], $paramsBase);
            if ($sourceType !== 'cp') {
                $paramsTimeDetails[':base_rate'] = $baseHourlyRate;
            }

            $stmtTimeDetails = $dbh->prepare($sqlTimeDetails);
            $stmtTimeDetails->execute($paramsTimeDetails);
            $timeDetailsData = $stmtTimeDetails->fetchAll(PDO::FETCH_ASSOC);

            // 詳細データをバッファに格納 (営業所単位)
            foreach ($timeDetailsData as $dRow) {
                $dTotalH = $dRow['std'] + $dRow['ovt'] + $dRow['trans'];
                $dTotalHead = $dRow['ful'] + $dRow['cont'];
                $officeName = $dRow['office_name'] ?? '不明な営業所';
                $officeCode = $dRow['office_code'] ?? '99999';
                $oid = $dRow['office_id'];

                // [追加] 労務費を営業所別集計配列にセット
                if (!isset($officeMonthStats[$oid])) {
                    $officeMonthStats[$oid] = initOfficeStats($officeName, $officeCode);
                }
                $officeMonthStats[$oid]['labor'] += $dRow['labor_cost'];

                $keysToBreakdown = [
                    'total_hours' => $dTotalH,
                    'standard_hours' => $dRow['std'],
                    'overtime_hours' => $dRow['ovt'],
                    'transferred_hours' => $dRow['trans'],
                    'labor_cost' => $dRow['labor_cost'],
                    'headcount_total' => $dTotalHead,
                    'headcount_full' => $dRow['ful'],
                    'headcount_contract' => $dRow['cont'],
                    'headcount_dispatch' => $dRow['disp'],
                ];

                foreach ($keysToBreakdown as $k => $val) {
                    $uniqueKey = $k . '_' . $ymStr . '_' . $oid;

                    if (!isset($detailsBuffer[$k][$uniqueKey])) {
                        $detailsBuffer[$k][$uniqueKey] = [
                            'detail_name' => $officeName,
                            'month_name' => $monthLabel,
                            'sort_key' => $ymStr . '_' . $officeCode,
                            'cp' => 0,
                            'plan' => 0,
                            'result' => 0
                        ];
                    }
                    $detailsBuffer[$k][$uniqueKey][$sourceType] += $val;
                }
            }


            // 2. 集計サマリ用（全体の合算）
            // ... (既存ロジックそのまま維持) ...
            $sqlTime = "
                SELECT 
                    SUM(t.standard_hours) as std,
                    SUM(t.overtime_hours) as ovt,
                    SUM(t.transferred_hours) as trans,
                    SUM(t.fulltime_count) as ful,
                    SUM(t.contract_count) as cont,
                    SUM(t.dispatch_count) as disp,
                    SUM( (t.standard_hours + t.overtime_hours + t.transferred_hours) * " . $rateCol . " ) as labor_cost
                FROM " . $tablePrefix . "_time t
                WHERE t." . $timeFkColumn . " = :pid 
                " . $officeFilterTime . "
            ";

            $paramsTime = array_merge([':pid' => $parentId], $paramsBase);
            if ($sourceType !== 'cp') {
                $paramsTime[':base_rate'] = $baseHourlyRate;
            }
            $stmtTime = $dbh->prepare($sqlTime);
            $stmtTime->execute($paramsTime);
            $timeData = $stmtTime->fetch(PDO::FETCH_ASSOC);

            if ($timeData) {
                $totalH = $timeData['std'] + $timeData['ovt'] + $timeData['trans'];
                $totalHead = $timeData['ful'] + $timeData['cont'];

                $aggregated['total_hours'][$sourceType] += $totalH;
                $aggregated['standard_hours'][$sourceType] += $timeData['std'];
                $aggregated['overtime_hours'][$sourceType] += $timeData['ovt'];
                $aggregated['transferred_hours'][$sourceType] += $timeData['trans'];
                $aggregated['headcount_total'][$sourceType] += $totalHead;
                $aggregated['headcount_full'][$sourceType] += $timeData['ful'];
                $aggregated['headcount_contract'][$sourceType] += $timeData['cont'];
                $aggregated['headcount_dispatch'][$sourceType] += $timeData['disp'];
                $aggregated['labor_cost'][$sourceType] += $timeData['labor_cost'];
            }

            // --- [追加] 営業所ごとの差引収益・税引前利益・経費合計を計算してバッファへ ---
            foreach ($officeMonthStats as $oid => $stats) {
                $rev = $stats['rev'];
                $exp = $stats['exp'];
                $labor = $stats['labor'];

                // 差引収益 = 収入 - 経費 (労務費除く)
                $gross = $rev - $exp;
                // 税引前利益 = 差引収益 - 労務費
                $preTax = $gross - $labor;

                $targetKeys = [
                    'expense_total' => $exp,        // 経費合計
                    'gross_profit' => $gross,       // 差引収益
                    'pre_tax_profit' => $preTax     // 税引前利益
                ];

                foreach ($targetKeys as $k => $val) {
                    // ユニークキー: 項目_年月_営業所ID
                    $uniqueKey = $k . '_' . $ymStr . '_' . $oid;

                    if (!isset($detailsBuffer[$k][$uniqueKey])) {
                        $detailsBuffer[$k][$uniqueKey] = [
                            'detail_name' => $stats['name'],
                            'month_name' => $monthLabel,
                            'sort_key' => $ymStr . '_' . $stats['code'],
                            'cp' => 0,
                            'plan' => 0,
                            'result' => 0
                        ];
                    }
                    $detailsBuffer[$k][$uniqueKey][$sourceType] += $val;
                }
            }
        }
    }

    // --- 5. 計算項目の処理 (全体のサマリ計算) ---
    foreach (['cp', 'plan', 'result'] as $t) {
        $aggregated['gross_profit'][$t] = $aggregated['revenue_total'][$t] - $aggregated['expense_total'][$t];
        $labor = $aggregated['labor_cost'][$t] ?? 0;
        $aggregated['pre_tax_profit'][$t] = $aggregated['gross_profit'][$t] - $labor;
    }

    // --- 6. 出力 ---
    $responseSummary = [];
    $responseDetails = [];

    foreach ($summaryRows as $row) {
        $id = $row['id'];
        $vals = $aggregated[$id];
        $resRow = calcDiffRatio($vals, $row['name'], $id);
        $responseSummary[] = $resRow;

        if (isset($detailsBuffer[$id])) {
            $detailList = [];
            foreach ($detailsBuffer[$id] as $uniqueKey => $dVals) {
                $calc = calcDiffRatio($dVals, $dVals['detail_name'], $uniqueKey);
                $calc['month_name'] = $dVals['month_name'];
                $calc['detail_name'] = $dVals['detail_name'];
                $calc['sort_key'] = $dVals['sort_key'];
                $detailList[] = $calc;
            }

            // 詳細ソート (年月順 > 営業所identifier順、または項目名順)
            usort($detailList, function ($a, $b) {
                return strcmp($a['sort_key'], $b['sort_key']);
            });

            $responseDetails[$id] = $detailList;
        }
    }

    echo json_encode([
        'summary' => $responseSummary,
        'details' => $responseDetails
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// --- ヘルパー関数 ---

function initOfficeStats($name, $code)
{
    return [
        'name' => $name ?? '不明',
        'code' => $code ?? '99999',
        'rev' => 0,
        'exp' => 0,
        'labor' => 0
    ];
}

function calcDiffRatio($vals, $name, $id)
{
    $cp = (float)$vals['cp'];
    $plan = (float)$vals['plan'];
    $result = (float)$vals['result'];

    $plan_diff = $result - $plan;
    $plan_ratio = ($plan > 0) ? ($result / $plan) * 100 : null;

    $cp_diff = $result - $cp;
    $cp_ratio = ($cp > 0) ? ($result / $cp) * 100 : null;

    return [
        'id' => $id,
        'name' => $name,
        'cp' => $cp,
        'plan' => $plan,
        'result' => $result,
        'plan_diff' => $plan_diff,
        'plan_ratio' => $plan_ratio,
        'cp_diff' => $cp_diff,
        'cp_ratio' => $cp_ratio
    ];
}

function formatMonthJP($y, $m)
{
    return "{$y}年{$m}月";
}
