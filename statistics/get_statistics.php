<?php
// statistics/get_statistics.php

require_once __DIR__ . '/../includes/database.php';
header('Content-Type: application/json');

try {
    $dbh = getDb();

    // --- 1. フィルターパラメータの取得 ---
    $year = (int)($_GET['year'] ?? 0);
    $baseType = $_GET['base'] ?? 'cp';
    $compareType = $_GET['compare'] ?? 'cp';
    $officeId = $_GET['office'] ?? 'all';
    $periodType = $_GET['period'] ?? 'full_year';

    // (基準月の設定)
    $currentDay = new DateTime("now");
    $currentMonth = (int)$currentDay->format('n');

    // マスタデータの取得
    $stmtAccounts = $dbh->query("SELECT id, name FROM accounts");
    $accountsList = $stmtAccounts->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmtRevCat = $dbh->query("SELECT id, name FROM revenue_categories");
    $revenueCategoriesList = $stmtRevCat->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmtRevItems = $dbh->query("SELECT id, name FROM revenue_items");
    $revenueItemsList = $stmtRevItems->fetchAll(PDO::FETCH_KEY_PAIR);

    // --- 2. データ取得ロジック ---
    function fetchData($dataType, $targetYear, $currentMonth, $officeId, $dbh, $accountsList, $revenueCategoriesList, $revenueItemsList)
    {
        $months = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];
        $monthlyData = [];

        // 営業所フィルタ (子テーブル用)
        $params = [];
        $officeFilterTime = "";
        $officeFilterDetails = "";
        $officeFilterRevenues = ""; // 収入用フィルタ定義

        if ($officeId !== 'all') {
            $officeFilterTime = " AND t.office_id = :office_id ";
            $officeFilterDetails = " AND det.office_id = :office_id ";
            $officeFilterRevenues = " AND ri.office_id = :office_id "; // 収入用フィルタ

            $params[':office_id'] = (int)$officeId;
        }

        $currentMonth_sort = $currentMonth < 4 ? $currentMonth + 12 : $currentMonth;

        foreach ($months as $month) {
            $month_sort = $month < 4 ? $month + 12 : $month;

            // データソース決定
            if ($dataType === 'annual_forecast') {
                if ($month_sort < $currentMonth_sort) {
                    $tablesToTry = ['result', 'outlook', 'plan', 'forecast', 'cp'];
                } elseif ($month_sort == $currentMonth_sort) {
                    $tablesToTry = ['outlook', 'plan', 'forecast', 'cp'];
                } else {
                    $tablesToTry = ['plan', 'forecast', 'cp'];
                }
            } else {
                $tablesToTry = [$dataType];
            }

            $foundData = null;
            foreach ($tablesToTry as $tablePrefix) {
                $parentTable = "monthly_" . $tablePrefix;
                $timeTable = $parentTable . "_time";
                $detailsTable = $parentTable . "_details";
                $revenuesTable = $parentTable . "_revenues";

                $idColumn = "monthly_" . $tablePrefix . "_id";

                if ($tablePrefix === 'cp') {
                    $detailsFk = 'monthly_cp_id';
                    $revenuesFk = 'monthly_cp_id';
                } elseif ($tablePrefix === 'forecast') {
                    $detailsFk = 'forecast_id';
                    $revenuesFk = 'forecast_id';
                } else {
                    $detailsFk = $tablePrefix . '_id';
                    $revenuesFk = $tablePrefix . '_id';
                }

                $statusesToTry = ['fixed'];

                foreach ($statusesToTry as $status) {
                    // 親データ取得
                    // 全フェーズ共通で親テーブルから hourly_rate を取得
                    $sqlParent = "SELECT id, hourly_rate FROM $parentTable 
                                    WHERE year = :year AND month = :month AND status = :status";

                    $stmtParent = $dbh->prepare($sqlParent);
                    // 親検索には year, month, status のみ渡す
                    $stmtParent->execute([':year' => $targetYear, ':month' => $month, ':status' => $status]);
                    $parentData = $stmtParent->fetch(PDO::FETCH_ASSOC);

                    if ($parentData) {
                        $parentId = $parentData['id'];
                        $hourlyRate = (float)($parentData['hourly_rate'] ?? 0);

                        // Time集計
                        // 全フェーズ共通で親の賃率(:hourly_rate)を使用
                        $rateCol = ':hourly_rate';

                        // パラメータ結合 (親ID + 営業所ID)
                        $paramsTime = $params;
                        $paramsTime[':parent_id'] = $parentId;
                        $paramsTime[':hourly_rate'] = $hourlyRate;

                        $typeFilterTime = ($tablePrefix === 'cp') ? " AND t.type = 'cp'" : "";

                        $sqlTime = "SELECT 
                                        SUM(t.standard_hours) AS total_standard,
                                        SUM(t.overtime_hours) AS total_overtime,
                                        SUM(t.transferred_hours) AS total_transferred,
                                        AVG($rateCol) AS avg_rate
                                    FROM $timeTable t
                                    WHERE t.$idColumn = :parent_id $officeFilterTime $typeFilterTime";

                        $stmtTime = $dbh->prepare($sqlTime);
                        $stmtTime->execute($paramsTime);
                        $timeSum = $stmtTime->fetch(PDO::FETCH_ASSOC);

                        // 経費集計
                        $paramsDetails = $params;
                        $paramsDetails[':parent_id'] = $parentId;
                        $typeFilterDetails = ($tablePrefix === 'cp') ? " AND d.type = 'cp'" : "";

                        $sqlDetails = "SELECT a.id AS account_id, SUM(d.amount) AS total_amount
                                        FROM $detailsTable d
                                        JOIN details det ON d.detail_id = det.id
                                        JOIN accounts a ON det.account_id = a.id
                                        WHERE d.$detailsFk = :parent_id $officeFilterDetails $typeFilterDetails 
                                        GROUP BY a.id, a.name";

                        $stmtDetails = $dbh->prepare($sqlDetails);
                        $stmtDetails->execute($paramsDetails);
                        $detailsSum = $stmtDetails->fetchAll(PDO::FETCH_KEY_PAIR);

                        // 収入集計
                        $paramsRevenues = $params;
                        $paramsRevenues[':parent_id'] = $parentId;

                        $sqlRevenues = "SELECT ri.id AS item_id, SUM(r.amount) AS total_amount
                                        FROM $revenuesTable r
                                        JOIN revenue_items ri ON r.revenue_item_id = ri.id
                                        WHERE r.$revenuesFk = :parent_id $officeFilterRevenues
                                        GROUP BY ri.id, ri.name";

                        $stmtRevenues = $dbh->prepare($sqlRevenues);
                        $stmtRevenues->execute($paramsRevenues);
                        $revenueSum = $stmtRevenues->fetchAll(PDO::FETCH_KEY_PAIR);

                        // 計算 (小数点誤差対策: round使用)
                        $totalHours = round(
                            (float)($timeSum['total_standard'] ?? 0) +
                                (float)($timeSum['total_overtime'] ?? 0) +
                                (float)($timeSum['total_transferred'] ?? 0),
                            2
                        );
                        $avgRate = (float)($timeSum['avg_rate'] ?? $hourlyRate);
                        $laborCost = round($totalHours * $avgRate);
                        $expenseTotal = (float)array_sum($detailsSum);
                        $revenueTotal = (float)array_sum($revenueSum);
                        $grandTotal = $revenueTotal - $expenseTotal;
                        $preTaxProfit = $grandTotal - $laborCost;

                        // 詳細マップ
                        $detailsMapped = [];
                        if ($detailsSum) {
                            foreach ($detailsSum as $accountId => $amount) {
                                $accountName = $accountsList[$accountId] ?? "不明(ID:{$accountId})";
                                $detailsMapped[$accountName] = (float)$amount;
                            }
                        }

                        $revenueDetailsMapped = [];
                        if ($revenueSum) {
                            foreach ($revenueSum as $itemId => $amount) {
                                $itemName = $revenueItemsList[$itemId] ?? "不明(ID:{$itemId})";
                                $revenueDetailsMapped[$itemName] = (float)$amount;
                            }
                        }

                        $foundData = [
                            'source' => $tablePrefix . " ($status)",
                            'grandTotal' => $grandTotal,
                            'preTaxProfit' => $preTaxProfit,
                            'revenueTotal' => $revenueTotal,
                            'expenseTotal' => $expenseTotal,
                            'laborCost' => $laborCost,
                            'totalHours' => $totalHours,
                            'details' => $detailsMapped,
                            'revenueDetails' => $revenueDetailsMapped,
                        ];
                        break;
                    }
                }
                if ($foundData) break;
            }
            $monthlyData[$month] = $foundData;
        }
        return $monthlyData;
    }

    // --- 3. 集計関数 ---

    // 期間定義ヘルパー
    function getPeriodLabelsAndMonths($periodType)
    {
        if ($periodType === 'half_year') {
            return [
                'labels' => ['上期', '下期'],
                'groups' => [['4', '5', '6', '7', '8', '9'], ['10', '11', '12', '1', '2', '3']]
            ];
        } elseif ($periodType === 'quarter') {
            return [
                'labels' => ['Q1', 'Q2', 'Q3', 'Q4'],
                'groups' => [['4', '5', '6'], ['7', '8', '9'], ['10', '11', '12'], ['1', '2', '3']]
            ];
        } else {
            return [
                'labels' => ['4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月', '1月', '2月', '3月'],
                'groups' => [[4], [5], [6], [7], [8], [9], [10], [11], [12], [1], [2], [3]]
            ];
        }
    }

    // 汎用単一項目集計
    function aggregateDataByPeriod($monthlyData, $periodType, $key)
    {
        $setting = getPeriodLabelsAndMonths($periodType);
        $result = [];
        foreach ($setting['groups'] as $months) {
            $sum = 0;
            foreach ($months as $m) {
                $sum += ($monthlyData[(int)$m][$key] ?? 0);
            }
            $result[] = $sum;
        }
        return ['labels' => $setting['labels'], 'data' => $result];
    }

    // 経費・労務費集計
    function aggregateExpenseDataByPeriod($monthlyData, $periodType)
    {
        $setting = getPeriodLabelsAndMonths($periodType);
        $laborData = [];
        $expenseData = [];
        foreach ($setting['groups'] as $months) {
            $lSum = 0;
            $eSum = 0;
            foreach ($months as $m) {
                $lSum += ($monthlyData[(int)$m]['laborCost'] ?? 0);
                $eSum += ($monthlyData[(int)$m]['expenseTotal'] ?? 0);
            }
            $laborData[] = $lSum;
            $expenseData[] = $eSum;
        }
        return ['labels' => $setting['labels'], 'laborData' => $laborData, 'expenseData' => $expenseData];
    }

    // 収入項目の積み上げ用集計 (合計0非表示)
    function aggregateRevenueStackData($monthlyData, $periodType)
    {
        $setting = getPeriodLabelsAndMonths($periodType);

        $itemTotals = [];
        foreach ($monthlyData as $m => $data) {
            if (!$data) continue;
            foreach ($data['revenueDetails'] as $name => $amount) {
                if (!isset($itemTotals[$name])) $itemTotals[$name] = 0;
                $itemTotals[$name] += $amount;
            }
        }
        arsort($itemTotals);
        $top5Names = array_keys(array_slice($itemTotals, 0, 5));

        $datasets = [];
        foreach ($top5Names as $name) $datasets[$name] = [];
        $datasets['その他'] = [];

        foreach ($setting['groups'] as $months) {
            $periodSums = array_fill_keys($top5Names, 0);
            $periodSums['その他'] = 0;

            foreach ($months as $m) {
                $details = $monthlyData[(int)$m]['revenueDetails'] ?? [];
                foreach ($details as $name => $amount) {
                    if (in_array($name, $top5Names)) {
                        $periodSums[$name] += $amount;
                    } else {
                        $periodSums['その他'] += $amount;
                    }
                }
            }

            foreach ($top5Names as $name) $datasets[$name][] = $periodSums[$name];
            $datasets['その他'][] = $periodSums['その他'];
        }

        $finalDatasets = [];
        foreach ($datasets as $name => $data) {
            if (array_sum($data) == 0) continue;
            $finalDatasets[] = [
                'label' => $name,
                'data' => $data
            ];
        }

        return ['labels' => $setting['labels'], 'datasets' => $finalDatasets];
    }

    // 生産性集計
    function aggregateProductivityData($monthlyData, $periodType)
    {
        $setting = getPeriodLabelsAndMonths($periodType);
        $hoursData = [];
        $hourlyProfitData = [];

        foreach ($setting['groups'] as $months) {
            $sumHours = 0;
            $sumGross = 0;
            foreach ($months as $m) {
                $sumHours += ($monthlyData[(int)$m]['totalHours'] ?? 0);
                $sumGross += ($monthlyData[(int)$m]['grandTotal'] ?? 0);
            }
            $hoursData[] = $sumHours;
            $hourlyProfitData[] = ($sumHours > 0) ? round($sumGross / $sumHours) : 0;
        }
        return ['labels' => $setting['labels'], 'hoursData' => $hoursData, 'hourlyProfitData' => $hourlyProfitData];
    }

    // --- 4. データ取得実行 ---
    $baseMonthlyData = fetchData($baseType, $year, $currentMonth, $officeId, $dbh, $accountsList, $revenueCategoriesList, $revenueItemsList);

    $compareMonthlyData = null;
    if ($compareType !== 'none') {
        $compareYear = ($compareType === 'previous_year_result') ? $year - 1 : $year;
        $compareDataType = ($compareType === 'previous_year_result') ? 'result' : $compareType;
        try {
            $compareCurrentMonth = ($compareDataType === 'annual_forecast') ? 12 : $currentMonth;
            $compareMonthlyData = fetchData($compareDataType, $compareYear, $compareCurrentMonth, $officeId, $dbh, $accountsList, $revenueCategoriesList, $revenueItemsList);
        } catch (Exception $e) {
            $compareMonthlyData = null;
        }
    }

    // --- 5. レスポンス構築 ---
    // (レスポンス構築部分は変更なし)
    $baseGross = aggregateDataByPeriod($baseMonthlyData, $periodType, 'grandTotal');
    $compGross = $compareMonthlyData ? aggregateDataByPeriod($compareMonthlyData, $periodType, 'grandTotal') : null;
    $basePreTax = aggregateDataByPeriod($baseMonthlyData, $periodType, 'preTaxProfit');
    $compPreTax = $compareMonthlyData ? aggregateDataByPeriod($compareMonthlyData, $periodType, 'preTaxProfit') : null;

    $mainChartData = [
        'labels' => $baseGross['labels'],
        'baseDataGross' => $baseGross['data'],
        'compareDataGross' => $compGross['data'] ?? [],
        'baseDataPreTax' => $basePreTax['data'],
        'compareDataPreTax' => $compPreTax['data'] ?? [],
        'baseLabel' => $baseType,
        'compareLabel' => $compareType,
    ];

    $baseExp = aggregateExpenseDataByPeriod($baseMonthlyData, $periodType);
    $compExp = $compareMonthlyData ? aggregateExpenseDataByPeriod($compareMonthlyData, $periodType) : null;
    $expenseTrendChartData = [
        'labels' => $baseExp['labels'],
        'baseLaborData' => $baseExp['laborData'],
        'baseExpenseData' => $baseExp['expenseData'],
        'compareLaborData' => $compExp['laborData'] ?? [],
        'compareExpenseData' => $compExp['expenseData'] ?? [],
        'baseLabel' => $baseType,
        'compareLabel' => $compareType,
    ];

    $baseRevStack = aggregateRevenueStackData($baseMonthlyData, $periodType);
    $compRevTotal = $compareMonthlyData ? aggregateDataByPeriod($compareMonthlyData, $periodType, 'revenueTotal') : null;

    $revenueStackChartData = [
        'labels' => $baseRevStack['labels'],
        'datasets' => $baseRevStack['datasets'],
        'compareTotalData' => $compRevTotal['data'] ?? [],
        'baseLabel' => $baseType,
        'compareLabel' => $compareType
    ];

    $baseProd = aggregateProductivityData($baseMonthlyData, $periodType);
    $compProd = $compareMonthlyData ? aggregateProductivityData($compareMonthlyData, $periodType) : null;

    $productivityChartData = [
        'labels' => $baseProd['labels'],
        'baseHoursData' => $baseProd['hoursData'],
        'baseHourlyData' => $baseProd['hourlyProfitData'],
        'compareHoursData' => $compProd['hoursData'] ?? [],
        'compareHourlyData' => $compProd['hourlyProfitData'] ?? [],
        'baseLabel' => $baseType,
        'compareLabel' => $compareType
    ];

    $kpi = [
        'revenueTotal' => ['value' => 0, 'diff' => null],
        'expenseTotal' => ['value' => 0, 'diff' => null],
        'grossProfit'  => ['value' => 0, 'diff' => null],
        'totalHours'   => ['value' => 0, 'diff' => null],
        'hourlyProfit' => ['value' => 0, 'diff' => null],
        'preTaxProfit' => ['value' => 0, 'diff' => null],
    ];
    $kpiCompare = ['revenueTotal' => 0, 'expenseTotal' => 0, 'grossProfit' => 0, 'totalHours' => 0, 'preTaxProfit' => 0];
    $targetMonth = null;

    foreach ([4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3] as $m) {
        if ($d = $baseMonthlyData[$m] ?? null) {
            $kpi['revenueTotal']['value'] += $d['revenueTotal'];
            $kpi['expenseTotal']['value'] += $d['expenseTotal'];
            $kpi['grossProfit']['value']  += $d['grandTotal'];
            $kpi['totalHours']['value']   += $d['totalHours'];
            $kpi['preTaxProfit']['value'] += $d['preTaxProfit'];
            $targetMonth = $m;
        }
        if ($d = $compareMonthlyData[$m] ?? null) {
            $kpiCompare['revenueTotal'] += $d['revenueTotal'];
            $kpiCompare['expenseTotal'] += $d['expenseTotal'];
            $kpiCompare['grossProfit']  += $d['grandTotal'];
            $kpiCompare['totalHours']   += $d['totalHours'];
            $kpiCompare['preTaxProfit'] += $d['preTaxProfit'];
        }
    }
    $kpi['target_month'] = $targetMonth;
    if ($kpi['totalHours']['value'] > 0) {
        $kpi['hourlyProfit']['value'] = $kpi['grossProfit']['value'] / $kpi['totalHours']['value'];
    }
    $compHourly = 0;
    if ($kpiCompare['totalHours'] > 0) {
        $compHourly = $kpiCompare['grossProfit'] / $kpiCompare['totalHours'];
    }
    if ($compareType !== 'none') {
        foreach (array_keys($kpi) as $k) {
            if ($k === 'target_month') {
                continue;
            }
            $b = $kpi[$k]['value'];
            $c = ($k === 'hourlyProfit') ? $compHourly : ($kpiCompare[$k] ?? 0);
            if ($c != 0) $kpi[$k]['diff'] = ($b - $c) / abs($c);
        }
    }

    $officeName = '全社合計';
    if ($officeId !== 'all') {
        $stmt = $dbh->prepare("SELECT name FROM offices WHERE id = ?");
        $stmt->execute([(int)$officeId]);
        $officeName = $stmt->fetchColumn();
    }
    $periodName = ($periodType === 'half_year') ? '半期' : (($periodType === 'quarter') ? 'クオーター' : '通期');
    $compareLabelMap = ['previous_year_result' => '前年実績', 'plan' => '予定', 'cp' => 'CP', 'none' => 'なし'];

    echo json_encode([
        'kpi' => $kpi,
        'mainChart' => $mainChartData,
        'expenseTrendChart' => $expenseTrendChartData,
        'revenueStackChart' => $revenueStackChartData,
        'productivityChart' => $productivityChartData,
        'filters' => [
            'officeName' => $officeName,
            'periodName' => $periodName,
            'compareName' => $compareLabelMap[$compareType] ?? $compareType
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
