<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    /**
     * Validate Inputs
     */
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

    /**
     * Whitelist currency
     */
    $allowedCurrencies = [
        'NGN' => 'ngn_rate',
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate'
    ];
    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency specified.", 400);
    }
    $rateCol = $allowedCurrencies[$currency];

    /**
     * Define Categories & Logic
     * 'multiplier' handles the specific accounting logic from the legacy code:
     *  - Revenue/COS/OtherIncome: Standard Debit - Credit.
     *  - Admin/Selling/Dep/Fin/Tax: (Debit - Credit) * -1.
     */
    
    $categories = [
        'Revenue' => [
            'title' => 'Revenue',
            'condition' => "ledger_sub_class = 'Revenue' AND ledger_type = 'Revenue'",
            'multiplier' => 1
        ],
        'CostOfServices' => [
            'title' => 'Cost of Services',
            'condition' => "ledger_sub_class = 'Cost of Services' AND ledger_type = 'Cost of Services'",
            'multiplier' => 1
        ],
        'Administrative' => [
            'title' => 'Administrative Expenses',
            'condition' => "ledger_sub_class = 'Administrative Expenses' AND ledger_type = 'Administrative Expenses'",
            'multiplier' => -1
        ],
        'Selling' => [
            'title' => 'Selling Expenses',
            'condition' => "ledger_sub_class = 'Selling Expenses' AND ledger_type = 'Selling Expenses'",
            'multiplier' => -1
        ],
        'OtherIncome' => [
            'title' => 'Other Income',
            'condition' => "ledger_sub_class = 'Revenue' AND ledger_type = 'Other Income'",
            'multiplier' => 1
        ],
        'Depreciation' => [
            'title' => 'Depreciation & Amortization',
            'condition' => "ledger_sub_class = 'Depreciation Expenses' AND ledger_type = 'Depreciation, Amortization & Impairment (Expenses)'",
            'multiplier' => -1
        ],
        'FinanceCost' => [
            'title' => 'Finance Cost',
            'condition' => "ledger_sub_class = 'Finance Cost' AND ledger_type = 'Finance Cost'",
            'multiplier' => -1
        ],
        'Taxation' => [
            'title' => 'Income & Other Taxes',
            'condition' => "ledger_sub_class = 'Taxation' AND ledger_type = 'Income & Other Taxes'",
            'multiplier' => -1
        ]
    ];

    /**
     * 1. Data Query
     */
    if ($zerobal === 'Yes') {
        // All ledgers (LEFT JOIN) - filtered by Ledger Table classification
        // Note: We filter by ledger_sub_class and ledger_type in the WHERE clause
        $dataQuery = "
            SELECT 
                l.ledger_name,
                l.ledger_number,
                l.ledger_sub_class,
                l.ledger_type,
                COALESCE(SUM(m.debit_ngn  / NULLIF(m.$rateCol, 0)), 0) AS total_debit,
                COALESCE(SUM(m.credit_ngn / NULLIF(m.$rateCol, 0)), 0) AS total_credit
            FROM ledger_table l
            LEFT JOIN main_journal_table m 
                ON l.ledger_name = m.ledger_name 
                AND m.journal_date BETWEEN ? AND ?
            WHERE 1=1 
            GROUP BY l.ledger_name, l.ledger_number, l.ledger_sub_class, l.ledger_type
            ORDER BY l.ledger_number ASC
        ";

        $stmt = $conn->prepare($dataQuery);
        if (!$stmt) throw new Exception("DB Error: " . $conn->error);
        $stmt->bind_param("ss", $datefrom, $dateto);

    } else {
        // Only ledgers with transactions
        $dataQuery = "
            SELECT 
                ledger_name,
                ledger_number,
                ledger_sub_class,
                ledger_type,
                SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
                SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit
            FROM main_journal_table
            WHERE journal_date BETWEEN ? AND ?
            GROUP BY ledger_name, ledger_number, ledger_sub_class, ledger_type
            ORDER BY ledger_number ASC
        ";

        $stmt = $conn->prepare($dataQuery);
        if (!$stmt) throw new Exception("DB Error: " . $conn->error);
        $stmt->bind_param("ss", $datefrom, $dateto);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    // Initialize Response Structure
    $reportData = [];
    foreach ($categories as $key => $config) {
        $reportData[$key] = [
            'title' => $config['title'],
            'records' => [],
            'total' => 0
        ];
    }

    $totals = [
        'Revenue' => 0, 'CostOfServices' => 0, 'Administrative' => 0,
        'Selling' => 0, 'OtherIncome' => 0, 'Depreciation' => 0,
        'FinanceCost' => 0, 'Taxation' => 0
    ];

    // Process and Group Data
    while ($row = $result->fetch_assoc()) {
        $subClass = $row['ledger_sub_class'];
        $type = $row['ledger_type'];
        
        $balance = (float)$row['total_debit'] - (float)$row['total_credit'];

        // Find matching category
        foreach ($categories as $key => $config) {
            // Check strict match
            $matchSubClass = (strpos($config['condition'], "ledger_sub_class = '" . $subClass . "'") !== false);
            $matchType = (strpos($config['condition'], "ledger_type = '" . $type . "'") !== false);

            if ($matchSubClass && $matchType) {
                // Calculate display balance based on specific multiplier logic
                $reportBalance = $balance * $config['multiplier'];

                $reportData[$key]['records'][] = [
                    'ledger_name'   => $row['ledger_name'],
                    'ledger_number' => $row['ledger_number'],
                    'balance'       => $reportBalance
                ];

                // Accumulate total
                $reportData[$key]['total'] += $reportBalance;
                break;
            }
        }
    }
    $stmt->close();

    /**
     * 2. Calculate Summaries (EBITDA, PAT, etc.)
     * Logic from legacy: 
     * EBITDA = Rev - Cos - Admin - Selling + OtherIncome
     * Op Profit = EBITDA - Depreciation
     * PBT = Op Profit - Finance Cost
     * PAT = PBT - Tax
     */
    
    $totalRevenue   = $reportData['Revenue']['total'];
    $totalCos       = $reportData['CostOfServices']['total'];
    $totalAdmin     = $reportData['Administrative']['total'];
    $totalSelling   = $reportData['Selling']['total'];
    $totalOtherInc  = $reportData['OtherIncome']['total'];
    $totalDep       = $reportData['Depreciation']['total'];
    $totalFin       = $reportData['FinanceCost']['total'];
    $totalTax       = $reportData['Taxation']['total'];

    // Note on logic: 
    // Expenses were multiplied by -1 during accumulation.
    // So TotalAdmin is negative.
    // EBITDA Formula: Rev - Cos - Admin...
    // If Admin is negative, -(-Admin) = +Admin.
    // This logic holds correctly for P&L structure.

    $ebitda = $totalRevenue - $totalCos - $totalAdmin - $totalSelling + $totalOtherInc;
    $operatingProfit = $ebitda - $totalDep;
    $profitBeforeTax = $operatingProfit - $totalFin;
    $profitAfterTax  = $profitBeforeTax - $totalTax;

    $summary = [
        'ebitda' => $ebitda,
        'operating_profit' => $operatingProfit,
        'profit_before_tax' => $profitBeforeTax,
        'profit_after_tax' => $profitAfterTax
    ];

    http_response_code(200);

    echo json_encode([
        "status"  => "Success",
        "message" => "Profit and Loss report fetched successfully",
        "data"    => $reportData,
        "summary" => $summary,
        "meta"    => [
            "currency" => $currency,
            "datefrom" => $datefrom,
            "dateto"   => $dateto,
            "zerobal"  => $zerobal,
        ],
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage(),
    ]);
}