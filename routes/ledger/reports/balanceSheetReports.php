<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData              = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    // ── Validate inputs ───────────────────────────────────────────────────────────
    $requiredParams = ['datefrom', 'dateto', 'currency', 'zerobal'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim($_GET[$param]))) {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $currency = trim($_GET['currency']);
    $zerobal  = trim($_GET['zerobal']);

    // ── Period year (Balance Sheet is cumulative up to year-end) ─────────────────
    $transacYear = (int) date('Y', strtotime($dateto));
    $period      = ($transacYear === (int) date('Y')) ? (int) date('Y') : $transacYear;

    // ── Currency / rate column ────────────────────────────────────────────────────
    $allowedCurrencies = [
        'NGN' => 'ngn_rate',
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate',
    ];
    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency specified.", 400);
    }
    $rateCol = $allowedCurrencies[$currency];

    // ════════════════════════════════════════════════════════════════════════════
    // QUERY 1 — CURRENT YEAR EARNINGS (P&L)
    // ════════════════════════════════════════════════════════════════════════════

    $plCategories = [
        'Revenue'        => ['sub_class' => 'Revenue',                 'type' => 'Revenue',                                  'flip' => false],
        'CostOfServices' => ['sub_class' => 'Cost of Services',         'type' => 'Cost of Services',                        'flip' => false],
        'Administrative' => ['sub_class' => 'Administrative Expenses',  'type' => 'Administrative Expenses',                 'flip' => true],
        'Selling'        => ['sub_class' => 'Selling Expenses',         'type' => 'Selling Expenses',                        'flip' => true],
        'OtherIncome'    => ['sub_class' => 'Revenue',                  'type' => 'Other Income',                            'flip' => false],
        'Depreciation'   => ['sub_class' => 'Depreciation Expenses',    'type' => 'Depreciation, Amortization & Impairment', 'flip' => true],
        'FinanceCost'    => ['sub_class' => 'Finance Cost',             'type' => 'Finance Cost',                            'flip' => true],
        'Taxation'       => ['sub_class' => 'Taxation',                 'type' => 'Income & Other Taxes',                    'flip' => true],
    ];

    $plWhere = [];
    foreach ($plCategories as $c) {
        $sc = $conn->real_escape_string($c['sub_class']);
        $tp = $conn->real_escape_string($c['type']);
        $plWhere[] = "(ledger_sub_class = '$sc' AND ledger_type = '$tp')";
    }

    $plSQL = "
        SELECT
            ledger_sub_class,
            ledger_type,
            SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
            SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit
        FROM main_journal_table
        WHERE journal_date BETWEEN ? AND ?
          AND (" . implode(' OR ', $plWhere) . ")
        GROUP BY ledger_sub_class, ledger_type
    ";

    $stmtPL = $conn->prepare($plSQL);
    if (!$stmtPL) throw new Exception("DB Error (P&L): " . $conn->error);
    $stmtPL->bind_param("ss", $datefrom, $dateto);
    $stmtPL->execute();

    $plTotals = array_fill_keys(array_keys($plCategories), 0.0);
    foreach ($stmtPL->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        foreach ($plCategories as $key => $c) {
            // Use trim() here as well to ensure safe comparison
            if (trim($row['ledger_sub_class']) === $c['sub_class'] && trim($row['ledger_type']) === $c['type']) {
                $bal = (float)$row['total_debit'] - (float)$row['total_credit'];
                $plTotals[$key] += $c['flip'] ? ($bal * -1) : $bal;
                break;
            }
        }
    }
    $stmtPL->close();

    $plEbitda          = $plTotals['Revenue'] - $plTotals['CostOfServices'] - $plTotals['Administrative'] - $plTotals['Selling'] + $plTotals['OtherIncome'];
    $plOperatingProfit = $plEbitda - $plTotals['Depreciation'];
    $plPBT             = $plOperatingProfit - $plTotals['FinanceCost'];
    $currentYearEarnings = $plPBT - $plTotals['Taxation'];

    // ════════════════════════════════════════════════════════════════════════════
    // QUERY 2 — BALANCE SHEET LEDGER LIST
    // ════════════════════════════════════════════════════════════════════════════

    $ledgerTable = ($zerobal === 'Yes') ? 'ledger_table' : 'main_journal_table';

    $stmtLedger = $conn->prepare("
        SELECT DISTINCT ledger_name, ledger_number, ledger_sub_class, ledger_type
        FROM $ledgerTable
        WHERE
            (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'Intangible Assets')
         OR (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'Tangible Assets')
         OR (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'Depreciation, Amortization & Impairment')
         OR (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'CWIP')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Service Customers')
         OR (ledger_sub_class = 'Contra Asset'          AND ledger_type = 'Allowances for Doubtful Debts')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Strategic Partners')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Agents')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Short Term Investments')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Bank Accounts')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Petty Cash')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Offshore Bank Accounts')
         OR (ledger_sub_class = 'Equity'                AND ledger_type = 'Capital')
         OR (ledger_sub_class = 'Equity'                AND ledger_type = 'Retained Earnings')
         OR (ledger_sub_class = 'Non-Current Liability' AND ledger_type = 'Deferred Tax Payable')
         OR (ledger_sub_class = 'Non-Current Liability' AND ledger_type = 'Loans and Similar Debts')
         OR (ledger_sub_class = 'Current Liability'     AND ledger_type = 'Suppliers / Creditors')
         OR (ledger_sub_class = 'Current Liability'     AND ledger_type = 'Payroll and Similar Accounts')
         OR (ledger_sub_class = 'Current Liability'     AND ledger_type = 'Outsourcing Agents')
         OR (ledger_sub_class = 'Taxation'              AND ledger_type != 'Income & Other Taxes')
        ORDER BY ledger_number ASC
    ");
    if (!$stmtLedger) throw new Exception("DB Error (ledger list): " . $conn->error);
    $stmtLedger->execute();

    $ledgerMap     = [];
    $govTaxLedgers = [];

    // FIX: Apply trim() to keys to ensure they match the hardcoded configuration array exactly
    foreach ($stmtLedger->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $subClass = trim($row['ledger_sub_class']);
        $type     = trim($row['ledger_type']);
        $num      = trim($row['ledger_number']);
        $name     = trim($row['ledger_name']);

        if ($subClass === 'Taxation' && $type !== 'Income & Other Taxes') {
            $govTaxLedgers[] = ['ledger_number' => $num, 'ledger_name' => $name];
        } else {
            $ledgerMap[$subClass][$type][] = [
                'ledger_number' => $num,
                'ledger_name'   => $name,
            ];
        }
    }
    $stmtLedger->close();

    // ════════════════════════════════════════════════════════════════════════════
    // QUERY 3 — CUMULATIVE BALANCES up to year $period
    // ════════════════════════════════════════════════════════════════════════════

    $stmtBal = $conn->prepare("
        SELECT
            ledger_number, ledger_name,
            SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
            SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit
        FROM main_journal_table
        WHERE YEAR(journal_date) <= ?
        GROUP BY ledger_number
    ");
    if (!$stmtBal) throw new Exception("DB Error (balances): " . $conn->error);
    $stmtBal->bind_param("i", $period);
    $stmtBal->execute();

    $balanceMap = [];
    foreach ($stmtBal->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $balanceMap[trim($row['ledger_number'])] = [ // Trim ledger number just in case
            'total_debit'  => (float)$row['total_debit'],
            'total_credit' => (float)$row['total_credit'],
        ];
    }
    $stmtBal->close();

    // ════════════════════════════════════════════════════════════════════════════
    // BUILD REPORT
    // ════════════════════════════════════════════════════════════════════════════

    $categories = [
        'IntangibleAssets'       => ['title' => 'Intangible Assets',                                                     'sub_class' => 'Non-Current Asset',    'type' => 'Intangible Assets',                       'flip' => false, 'group' => 'non_current_assets'],
        'TangibleAssets'         => ['title' => 'Tangible Assets',                                                       'sub_class' => 'Non-Current Asset',    'type' => 'Tangible Assets',                         'flip' => false, 'group' => 'non_current_assets'],
        'DepreciationAsset'      => ['title' => 'Less: Depreciation, Amortization & Impairment of Non - Current Assets', 'sub_class' => 'Non-Current Asset',    'type' => 'Depreciation, Amortization & Impairment', 'flip' => true,  'group' => 'non_current_assets'],
        'CWIP'                   => ['title' => 'Non - Current Assets Work in Progress (CWIP)',                          'sub_class' => 'Non-Current Asset',    'type' => 'CWIP',                                    'flip' => false, 'group' => 'non_current_assets'],
        'ServiceCustomers'       => ['title' => 'Service Customers',                                                     'sub_class' => 'Current Asset',        'type' => 'Service Customers',                       'flip' => false, 'group' => 'current_assets'],
        'AllowanceDoubtfulDebts' => ['title' => 'Less: Allowances for Doubtful Debts',                                   'sub_class' => 'Contra Asset',         'type' => 'Allowances for Doubtful Debts',           'flip' => true,  'group' => 'current_assets'],
        'StrategicPartners'      => ['title' => 'Strategic Partners',                                                    'sub_class' => 'Current Asset',        'type' => 'Strategic Partners',                      'flip' => false, 'group' => 'current_assets'],
        'Agents'                 => ['title' => 'Agents',                                                                'sub_class' => 'Current Asset',        'type' => 'Agents',                                  'flip' => false, 'group' => 'current_assets'],
        'ShortTermInvestments'   => ['title' => 'Short Term Investments',                                                 'sub_class' => 'Current Asset',        'type' => 'Short Term Investments',                  'flip' => false, 'group' => 'current_assets'],
        'BankAccounts'           => ['title' => 'Bank Accounts',                                                         'sub_class' => 'Current Asset',        'type' => 'Bank Accounts',                           'flip' => false, 'group' => 'current_assets'],
        'PettyCash'              => ['title' => 'Petty Cash',                                                            'sub_class' => 'Current Asset',        'type' => 'Petty Cash',                              'flip' => false, 'group' => 'current_assets'],
        'OffshoreBankAccounts'   => ['title' => 'Offshore Bank Accounts',                                                'sub_class' => 'Current Asset',        'type' => 'Offshore Bank Accounts',                  'flip' => false, 'group' => 'current_assets'],
        'Capital'                => ['title' => 'Capital',                                                               'sub_class' => 'Equity',               'type' => 'Capital',                                 'flip' => false, 'group' => 'equity'],
        'RetainedEarnings'       => ['title' => 'Retained Earnings',                                                     'sub_class' => 'Equity',               'type' => 'Retained Earnings',                       'flip' => false, 'group' => 'equity'],
        'DeferredTaxPayable'     => ['title' => 'Deferred Tax Payable',                                                  'sub_class' => 'Non-Current Liability', 'type' => 'Deferred Tax Payable',                   'flip' => false, 'group' => 'non_current_liabilities'],
        'LoansAndSimilarDebts'   => ['title' => 'Loans and Similar Debts',                                               'sub_class' => 'Non-Current Liability', 'type' => 'Loans and Similar Debts',                'flip' => false, 'group' => 'non_current_liabilities'],
        'SuppliersCreditors'     => ['title' => 'Suppliers / Creditors',                                                 'sub_class' => 'Current Liability',    'type' => 'Suppliers / Creditors',                   'flip' => false, 'group' => 'current_liabilities'],
        'PayrollSimilarAccounts' => ['title' => 'Payroll and Similar Accounts',                                          'sub_class' => 'Current Liability',    'type' => 'Payroll and Similar Accounts',             'flip' => false, 'group' => 'current_liabilities'],
        'OutsourcingAgents'      => ['title' => 'Outsourcing Agents',                                                    'sub_class' => 'Current Liability',    'type' => 'Outsourcing Agents',                      'flip' => false, 'group' => 'current_liabilities'],
        'GovernmentTax'          => ['title' => 'Amounts Payable / Receivable to and from the Govt Agencies',            'sub_class' => 'Taxation',             'type' => 'Income & Other Taxes',                    'flip' => false, 'group' => 'current_liabilities'],
    ];

    $reportData = [];
    $totals     = array_fill_keys(array_keys($categories), 0.0);

    foreach ($categories as $key => $config) {
        $records = [];

        $ledgers = ($key === 'GovernmentTax')
            ? $govTaxLedgers
            : ($ledgerMap[$config['sub_class']][$config['type']] ?? []);

        foreach ($ledgers as $ledger) {
            $num  = $ledger['ledger_number'];
            $name = $ledger['ledger_name'];

            $bal    = $balanceMap[$num] ?? ['total_debit' => 0.0, 'total_credit' => 0.0];
            $raw    = $bal['total_debit'] - $bal['total_credit'];

            if ($key === 'RetainedEarnings' && ($num === '11000002' || $num === '11000003')) {
                $secVal = $raw * -1;
            } elseif ($config['flip']) {
                $secVal = $raw * -1;
            } else {
                $secVal = $raw;
            }

            $totals[$key] += $secVal;

            $records[] = [
                'ledger_name'   => $name,
                'ledger_number' => $num,
                'balance'       => $raw,
                'section_value' => $secVal,
            ];
        }

        $reportData[$key] = [
            'title'   => $config['title'],
            'group'   => $config['group'],
            'records' => $records,
            'total'   => $totals[$key],
        ];
    }

    // ── Summaries ────────────────────────────────────────────────────────────────

    $totalNonCurrentAssets    = $totals['IntangibleAssets'] + $totals['TangibleAssets']
                              + $totals['DepreciationAsset'] + $totals['CWIP'];

    $netServiceCustomers      = $totals['ServiceCustomers'] + $totals['AllowanceDoubtfulDebts'];

    $totalCurrentAssets       = $netServiceCustomers
                              + $totals['StrategicPartners']    + $totals['Agents']
                              + $totals['ShortTermInvestments'] + $totals['BankAccounts']
                              + $totals['PettyCash']            + $totals['OffshoreBankAccounts'];

    $totalAssets              = $totalNonCurrentAssets + $totalCurrentAssets;

    $totalEquity              = $totals['Capital'] + $totals['RetainedEarnings'] + $currentYearEarnings;

    $totalNonCurrentLiability = $totals['LoansAndSimilarDebts'] + $totals['DeferredTaxPayable'];

    $totalCurrentLiabilities  = $totals['SuppliersCreditors']    + $totals['PayrollSimilarAccounts']
                              + $totals['OutsourcingAgents']      + $totals['GovernmentTax'];

    $totalLiabilities         = $totalNonCurrentLiability + $totalCurrentLiabilities;

    $totalEquityLiabilities   = $totalEquity + $totalLiabilities;

    http_response_code(200);

    echo json_encode([
        "status"  => "Success",
        "message" => "Balance Sheet report fetched successfully",
        "data"    => $reportData,
        "summary" => [
            "total_non_current_assets"    => $totalNonCurrentAssets,
            "net_service_customers"       => $netServiceCustomers,
            "total_current_assets"        => $totalCurrentAssets,
            "total_assets"                => $totalAssets,
            "current_year_earnings"       => $currentYearEarnings,
            "total_equity"                => $totalEquity,
            "total_non_current_liability" => $totalNonCurrentLiability,
            "total_current_liabilities"   => $totalCurrentLiabilities,
            "total_liabilities"           => $totalLiabilities,
            "total_equity_liabilities"    => $totalEquityLiabilities,
        ],
        "meta" => [
            "currency" => $currency,
            "period"   => $period,
            "datefrom" => $datefrom,
            "dateto"   => $dateto,
            "zerobal"  => $zerobal,
        ],
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}