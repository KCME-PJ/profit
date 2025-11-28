<?php
// get_statistics.php

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

    // (基準月の設定 - 2024年11月1日を想定)
    $currentDay = new DateTime("2024-11-01");
    $currentMonth = (int)$currentDay->format('n'); // 11月

    // 勘定科目マスターを先に取得
    $stmtAccounts = $dbh->query("SELECT id, name FROM accounts");
    $accountsList = $stmtAccounts->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

    // 収入カテゴリマスター
    $stmtRevCat = $dbh->query("SELECT id, name FROM revenue_categories");
    $revenueCategoriesList = $stmtRevCat->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

    // 収入「項目」マスターを取得
    $stmtRevItems = $dbh->query("SELECT id, name FROM revenue_items");
    $revenueItemsList = $stmtRevItems->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

    // --- 2. データ取得ロジック (fetchData) ---
    function fetchData($dataType, $targetYear, $currentMonth, $officeId, $dbh, $accountsList, $revenueCategoriesList, $revenueItemsList)
    {
        $months = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];
        $monthlyData = [];
        $missingMonths = [];

        // 営業所による絞り込み
        $params = [];
        $officeFilterTime = "";
        $officeFilterDetails = "";
        $officeFilterRevenues = ""; // 収入用の営業所フィルター

        if ($officeId !== 'all') {
            $officeFilterTime = " AND t.office_id = :office_id ";
            // 経費は office_id を details テーブルから引く (JOINが必要)
            $officeFilterDetails = " AND det.office_id = :office_id ";

            // 収入は office_id を持たないため、フィルター不要 (全社集計)
            $officeFilterRevenues = "";

            $params[':office_id'] = (int)$officeId;
        }

        $currentMonth_sort = $currentMonth < 4 ? $currentMonth + 12 : $currentMonth;

        // 月ごとにループ
        foreach ($months as $month) {
            $tablesToTry = [];
            $month_sort = $month < 4 ? $month + 12 : $month;

            if ($dataType === 'annual_forecast') {
                if ($month_sort < $currentMonth_sort) { // 4月～10月
                    $tablesToTry = ['result', 'outlook', 'plan', 'forecast', 'cp'];
                } elseif ($month_sort == $currentMonth_sort) { // 11月
                    $tablesToTry = ['outlook', 'plan', 'forecast', 'cp'];
                } else { // 12月～3月
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

                $idColumn = "monthly_" . $tablePrefix . "_id"; // time 用

                // details, revenues 用の FK
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
                if ($tablePrefix === 'forecast') {
                    $statusesToTry = ['fixed', 'draft'];
                }

                foreach ($statusesToTry as $status) {
                    // 1. 親テーブルから データを検索
                    $rateColParent = ($tablePrefix === 'cp') ? "0 AS hourly_rate" : "hourly_rate";
                    $sqlParent = "SELECT id, $rateColParent FROM $parentTable 
                                    WHERE year = :year AND month = :month AND status = :status";
                    $stmtParent = $dbh->prepare($sqlParent);
                    $stmtParent->execute([':year' => $targetYear, ':month' => $month, ':status' => $status]);
                    $parentData = $stmtParent->fetch(PDO::FETCH_ASSOC);

                    if ($parentData) {
                        $parentId = $parentData['id'];
                        $hourlyRate = (float)($parentData['hourly_rate'] ?? 0);

                        // 2. 時間・人数データを集計
                        $rateCol = ($tablePrefix === 'cp') ? 't.hourly_rate' : ':hourly_rate';

                        $paramsTime = $params;
                        $paramsTime[':parent_id'] = $parentId;
                        if ($tablePrefix !== 'cp') {
                            $paramsTime[':hourly_rate'] = $hourlyRate;
                        }

                        $typeFilterTime = ($tablePrefix === 'cp') ? " AND t.type = 'cp'" : "";

                        $sqlTime = "SELECT 
                                        SUM(t.standard_hours) AS total_standard,
                                        SUM(t.overtime_hours) AS total_overtime,
                                        SUM(t.transferred_hours) AS total_transferred,
                                        AVG($rateCol) AS avg_rate, 
                                        SUM(t.fulltime_count) AS total_fulltime,
                                        SUM(t.contract_count) AS total_contract,
                                        SUM(t.dispatch_count) AS total_dispatch
                                    FROM $timeTable t
                                    WHERE t.$idColumn = :parent_id $officeFilterTime $typeFilterTime";

                        $stmtTime = $dbh->prepare($sqlTime);
                        $stmtTime->execute($paramsTime);
                        $timeSum = $stmtTime->fetch(PDO::FETCH_ASSOC);

                        // 3. 経費データを集計
                        $paramsDetails = $params;
                        $paramsDetails[':parent_id'] = $parentId;

                        $typeFilterDetails = ($tablePrefix === 'cp') ? " AND d.type = 'cp'" : "";

                        $sqlDetails = "SELECT 
                                        a.id AS account_id, 
                                        SUM(d.amount) AS total_amount
                                        FROM $detailsTable d
                                        JOIN details det ON d.detail_id = det.id
                                        JOIN accounts a ON det.account_id = a.id
                                        WHERE d.$detailsFk = :parent_id $officeFilterDetails $typeFilterDetails 
                                        GROUP BY a.id, a.name";

                        $stmtDetails = $dbh->prepare($sqlDetails);
                        $stmtDetails->execute($paramsDetails);
                        $detailsSum = $stmtDetails->fetchAll(PDO::FETCH_KEY_PAIR); // [accountId => amount]

                        // 4. 収入データを集計 (項目ベース)
                        $paramsRevenues = $params;
                        $paramsRevenues[':parent_id'] = $parentId;

                        $sqlRevenues = "SELECT 
                                        ri.id AS item_id,
                                        SUM(r.amount) AS total_amount
                                        FROM $revenuesTable r
                                        JOIN revenue_items ri ON r.revenue_item_id = ri.id
                                        WHERE r.$revenuesFk = :parent_id $officeFilterRevenues
                                        GROUP BY ri.id, ri.name";

                        $stmtRevenues = $dbh->prepare($sqlRevenues);
                        $stmtRevenues->execute([':parent_id' => $parentId]);
                        $revenueSum = $stmtRevenues->fetchAll(PDO::FETCH_KEY_PAIR);


                        // 5. 計算
                        $totalHours = (float)($timeSum['total_standard'] ?? 0) + (float)($timeSum['total_overtime'] ?? 0) + (float)($timeSum['total_transferred'] ?? 0);
                        $avgRate = (float)($timeSum['avg_rate'] ?? $hourlyRate);
                        $laborCost = round($totalHours * $avgRate); // 労務費

                        $expenseTotal = (float)array_sum($detailsSum); // 労務費以外の経費
                        $revenueTotal = (float)array_sum($revenueSum); // 収入合計

                        // 差引収益 = 収入 - 経費 (労務費を含まない)
                        $grandTotal = $revenueTotal - $expenseTotal;

                        // 経費の内訳を作成
                        $detailsMapped = [];
                        if ($detailsSum) {
                            foreach ($detailsSum as $accountId => $amount) {
                                $accountName = $accountsList[$accountId] ?? "不明(ID:{$accountId})";
                                if (!isset($detailsMapped[$accountName])) {
                                    $detailsMapped[$accountName] = 0;
                                }
                                $detailsMapped[$accountName] += (float)$amount;
                            }
                        }

                        // 収入の内訳を作成
                        $revenueDetailsMapped = [];
                        if ($revenueSum) {
                            foreach ($revenueSum as $itemId => $amount) {
                                $itemName = $revenueItemsList[$itemId] ?? "不明(ID:{$itemId})";
                                if (!isset($revenueDetailsMapped[$itemName])) {
                                    $revenueDetailsMapped[$itemName] = 0;
                                }
                                $revenueDetailsMapped[$itemName] += (float)$amount;
                            }
                        }

                        $foundData = [
                            'source' => $tablePrefix . " ($status)",
                            'grandTotal' => $grandTotal, // 差引収益
                            'revenueTotal' => $revenueTotal, // 収入合計
                            'expenseTotal' => $expenseTotal, // 労務費以外の経費
                            'laborCost' => $laborCost, // 労務費
                            'totalHours' => $totalHours,
                            'details' => $detailsMapped, // 経費内訳
                            'revenueDetails' => $revenueDetailsMapped, // 収入内訳
                        ];

                        break;
                    }
                } // end foreach $statusesToTry

                if ($foundData) {
                    break;
                }
            } // end foreach $tablesToTry

            if ($foundData) {
                $monthlyData[$month] = $foundData;
            } else {
                $missingMonths[] = $month;
                $monthlyData[$month] = null;
            }
        } // end foreach $months

        // (データ欠損チェック)
        if (!empty($missingMonths)) {
            $trulyMissing = [];
            foreach ($missingMonths as $m) {
                $stmtCpFixed = $dbh->prepare("SELECT id FROM monthly_cp WHERE year = :year AND month = :month AND status = 'fixed'");
                $stmtCpFixed->execute([':year' => $targetYear, ':month' => $m]);
                if (!$stmtCpFixed->fetch()) {
                    $stmtCpDraft = $dbh->prepare("SELECT id FROM monthly_cp WHERE year = :year AND month = :month AND status = 'draft'");
                    $stmtCpDraft->execute([':year' => $targetYear, ':month' => $m]);
                    if (!$stmtCpDraft->fetch()) {
                        $trulyMissing[] = $m;
                    }
                }
            }
            if (!empty($trulyMissing)) {
                throw new Exception(implode('月、', $trulyMissing) . "月の確定データ（またはCPのDraftデータ）が見つかりません。");
            }
        }

        return $monthlyData;
    }

    /**
     * 月別データを指定された集計単位 (half_year, quarter, full_year) に再集計する (差引収益用)
     */
    function aggregateDataByPeriod($monthlyData, $periodType)
    {
        $labels = [];
        $aggregatedData = [];

        // periodType に応じて集計定義
        $periods = [];
        if ($periodType === 'half_year') {
            $labels = ['上期', '下期'];
            $periods['上期'] = [4, 5, 6, 7, 8, 9];
            $periods['下期'] = [10, 11, 12, 1, 2, 3];
        } elseif ($periodType === 'quarter') {
            $labels = ['Q1', 'Q2', 'Q3', 'Q4'];
            $periods['Q1'] = [4, 5, 6];
            $periods['Q2'] = [7, 8, 9];
            $periods['Q3'] = [10, 11, 12];
            $periods['Q4'] = [1, 2, 3];
        } else { // full_year (月別)
            $labels = ['4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月', '1月', '2月', '3月'];
            $months = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

            // 月別の場合、集計は不要
            foreach ($labels as $i => $label) {
                $month = $months[$i];
                $aggregatedData[] = $monthlyData[$month]['grandTotal'] ?? 0;
            }
            return ['labels' => $labels, 'data' => $aggregatedData];
        }

        // 半期・クオーターの集計
        foreach ($periods as $periodLabel => $monthsInPeriod) {
            $sum = 0;
            foreach ($monthsInPeriod as $month) {
                $sum += ($monthlyData[$month]['grandTotal'] ?? 0);
            }
            $aggregatedData[] = $sum;
        }

        return ['labels' => $labels, 'data' => $aggregatedData];
    }

    // 経費推移グラフ用の集計関数
    function aggregateExpenseDataByPeriod($monthlyData, $periodType)
    {
        $labels = [];
        $aggregatedLabor = []; // 労務費
        $aggregatedExpense = []; // その他経費

        // periodType に応じて集計定義
        $periods = [];
        if ($periodType === 'half_year') {
            $labels = ['上期', '下期'];
            $periods['上期'] = [4, 5, 6, 7, 8, 9];
            $periods['下期'] = [10, 11, 12, 1, 2, 3];
        } elseif ($periodType === 'quarter') {
            $labels = ['Q1', 'Q2', 'Q3', 'Q4'];
            $periods['Q1'] = [4, 5, 6];
            $periods['Q2'] = [7, 8, 9];
            $periods['Q3'] = [10, 11, 12];
            $periods['Q4'] = [1, 2, 3];
        } else { // full_year (月別)
            $labels = ['4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月', '1月', '2月', '3月'];
            $months = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

            // 月別の場合、集計は不要
            foreach ($labels as $i => $label) {
                $month = $months[$i];
                $aggregatedLabor[] = $monthlyData[$month]['laborCost'] ?? 0;
                $aggregatedExpense[] = $monthlyData[$month]['expenseTotal'] ?? 0;
            }
            return ['labels' => $labels, 'laborData' => $aggregatedLabor, 'expenseData' => $aggregatedExpense];
        }

        // 半期・クオーターの集計
        foreach ($periods as $periodLabel => $monthsInPeriod) {
            $sumLabor = 0;
            $sumExpense = 0;
            foreach ($monthsInPeriod as $month) {
                $sumLabor += ($monthlyData[$month]['laborCost'] ?? 0);
                $sumExpense += ($monthlyData[$month]['expenseTotal'] ?? 0);
            }
            $aggregatedLabor[] = $sumLabor;
            $aggregatedExpense[] = $sumExpense;
        }

        return ['labels' => $labels, 'laborData' => $aggregatedLabor, 'expenseData' => $aggregatedExpense];
    }

    // --- 3. 基準データと比較データの両方を取得 ---
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

    // --- 4. JSONレスポンスの構築 ---

    // 4.1 メインチャート (差引収益)
    $baseAggregated = aggregateDataByPeriod($baseMonthlyData, $periodType);
    $compareAggregated = $compareMonthlyData ? aggregateDataByPeriod($compareMonthlyData, $periodType) : null;

    $mainChartData = [
        'labels' => $baseAggregated['labels'],
        'baseData' => $baseAggregated['data'], // 差引収益の集計
        'compareData' => $compareAggregated['data'] ?? [],
        'baseLabel' => $baseType,
        'compareLabel' => $compareType,
    ];

    // 4.2 経費推移チャート
    $baseExpenseAggregated = aggregateExpenseDataByPeriod($baseMonthlyData, $periodType);
    $compareExpenseAggregated = $compareMonthlyData ? aggregateExpenseDataByPeriod($compareMonthlyData, $periodType) : null;

    $compareTotalData = [];
    if ($compareExpenseAggregated) {
        $compareTotalData = array_map(function ($labor, $expense) {
            return $labor + $expense;
        }, $compareExpenseAggregated['laborData'], $compareExpenseAggregated['expenseData']);
    }

    $expenseTrendChartData = [
        'labels' => $baseExpenseAggregated['labels'],
        'baseLaborData' => $baseExpenseAggregated['laborData'],
        'baseExpenseData' => $baseExpenseAggregated['expenseData'],
        'compareTotalData' => $compareTotalData,
        'baseLabel' => $baseType,
        'compareLabel' => $compareType,
    ];

    // 4.3 KPI と サブチャートデータ
    $kpi = [
        'grossProfit'  => ['value' => 0, 'diff' => null], // 差引収益
        'revenueTotal' => ['value' => 0, 'diff' => null], // 収入合計
        'expenseTotal' => ['value' => 0, 'diff' => null], // 労務費以外の経費
        'laborCost'    => ['value' => 0, 'diff' => null], // 労務費
        'totalHours'   => ['value' => 0, 'diff' => null],
    ];
    $subChartData = []; // 経費
    $revenueSubChartData = []; // 収入
    $kpiCompare = $kpi;
    $monthsNumeric = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

    foreach ($monthsNumeric as $month) {
        $baseMonthData = $baseMonthlyData[$month] ?? null;
        $compareMonthData = $compareMonthlyData[$month] ?? null;

        if ($baseMonthData) {
            $kpi['grossProfit']['value'] += $baseMonthData['grandTotal'];
            $kpi['revenueTotal']['value'] += $baseMonthData['revenueTotal'];
            $kpi['expenseTotal']['value'] += $baseMonthData['expenseTotal'];
            $kpi['laborCost']['value'] += $baseMonthData['laborCost'];
            $kpi['totalHours']['value'] += $baseMonthData['totalHours'];

            foreach ($baseMonthData['details'] as $accountName => $amount) {
                if (!isset($subChartData[$accountName])) {
                    $subChartData[$accountName] = 0;
                }
                $subChartData[$accountName] += (float)$amount;
            }

            foreach ($baseMonthData['revenueDetails'] as $itemName => $amount) {
                if (!isset($revenueSubChartData[$itemName])) {
                    $revenueSubChartData[$itemName] = 0;
                }
                $revenueSubChartData[$itemName] += (float)$amount;
            }
        }

        if ($compareMonthData) {
            $kpiCompare['grossProfit']['value'] += $compareMonthData['grandTotal'];
            $kpiCompare['revenueTotal']['value'] += $compareMonthData['revenueTotal'];
            $kpiCompare['expenseTotal']['value'] += $compareMonthData['expenseTotal'];
            $kpiCompare['laborCost']['value'] += $compareMonthData['laborCost'];
            $kpiCompare['totalHours']['value'] += $compareMonthData['totalHours'];
        }
    }

    // (KPI差異計算)
    if ($compareType !== 'none') {
        foreach ($kpi as $key => $values) {
            $baseV = $values['value'];
            $compareV = $kpiCompare[$key]['value'];
            if ($compareV != 0) {
                $kpi[$key]['diff'] = ($baseV - $compareV) / abs($compareV);
            }
        }
    }

    // (サブチャート整形 - 経費)
    arsort($subChartData);
    $subChartLabels = array_keys($subChartData);
    $subChartValues = array_values($subChartData);
    if (count($subChartValues) > 5) {
        $top5Values = array_slice($subChartValues, 0, 5);
        $otherValues = array_slice($subChartValues, 5);
        $top5Labels = array_slice($subChartLabels, 0, 5);
        $top5Values[] = array_sum($otherValues);
        $top5Labels[] = 'その他';
        $subChartLabels = $top5Labels;
        $subChartValues = $top5Values;
    }

    // (サブチャート整形 - 収入)
    arsort($revenueSubChartData);
    $revenueSubChartLabels = array_keys($revenueSubChartData);
    $revenueSubChartValues = array_values($revenueSubChartData);
    if (count($revenueSubChartValues) > 5) {
        $revTop5Values = array_slice($revenueSubChartValues, 0, 5);
        $revOtherValues = array_slice($revenueSubChartValues, 5);
        $revTop5Labels = array_slice($revenueSubChartLabels, 0, 5);
        $revTop5Values[] = array_sum($revOtherValues);
        $revTop5Labels[] = 'その他';
        $revenueSubChartLabels = $revTop5Labels;
        $revenueSubChartValues = $revTop5Values;
    }

    // (営業所名取得)
    $officeName = '全社合計';
    if ($officeId !== 'all') {
        $stmtOffice = $dbh->prepare("SELECT name FROM offices WHERE id = ?");
        $stmtOffice->execute([(int)$officeId]);
        $officeName = $stmtOffice->fetchColumn() ?: '不明';
    }

    // (集計単位のラベル名)
    $periodName = '通期';
    if ($periodType === 'half_year') $periodName = '半期';
    if ($periodType === 'quarter') $periodName = 'クオーター';

    // 最終レスポンスの構成
    $response = [
        'kpi' => $kpi,
        'mainChart' => $mainChartData, // 差引収益
        'expenseTrendChart' => $expenseTrendChartData,
        'expenseSubChart' => [
            'labels' => $subChartLabels,
            'data' => $subChartValues,
        ],
        'revenueSubChart' => [
            'labels' => $revenueSubChartLabels,
            'data' => $revenueSubChartValues,
        ],
        'filters' => [
            'officeName' => $officeName,
            'periodName' => $periodName,
            'compareName' => $compareType
        ]
    ];

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
