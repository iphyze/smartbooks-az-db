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
     * Note: Removed 'page' and 'limit' as this is a full report fetch.
     */
    $requiredParams = ['datefrom', 'dateto', 'currency'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim($_GET[$param]))) {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $currency = trim($_GET['currency']);

    // Search & Zero Balance Params
    $search  = isset($_GET['search'])  ? trim($_GET['search'])       : null;
    $zerobal = isset($_GET['zerobal']) ? trim($_GET['zerobal'])      : 'Yes';

    /**
     * Whitelist currency → rate column
     */
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

    // Search condition
    $searchCondition = "";
    $searchParams    = [];
    $searchTypes     = "";

    if ($search) {
        $searchCondition = " AND (ledger_name LIKE ? OR ledger_number LIKE ?)";
        $likeSearch      = "%" . $search . "%";
        $searchParams    = [$likeSearch, $likeSearch];
        $searchTypes     = "ss";
    }

    /**
     * Sort Order Logic (Kept to ensure data comes ordered from DB)
     */
    $classSortOrderAliased = "
        CASE l.ledger_class
            WHEN 'Asset'     THEN 1
            WHEN 'Equity'    THEN 2
            WHEN 'Revenue'   THEN 3
            WHEN 'Liability' THEN 4
            WHEN 'Expense'   THEN 5
            ELSE 6
        END
    ";

    $classSortOrderPlain = "
        CASE ledger_class
            WHEN 'Asset'     THEN 1
            WHEN 'Equity'    THEN 2
            WHEN 'Revenue'   THEN 3
            WHEN 'Liability' THEN 4
            WHEN 'Expense'   THEN 5
            ELSE 6
        END
    ";

    /**
     * 1. Data Query (Pagination Removed)
     */
    if ($zerobal === 'Yes') {
        // All ledgers (LEFT JOIN)
        $dataQuery = "
            SELECT
                l.ledger_name,
                l.ledger_number,
                l.ledger_class,
                COALESCE(SUM(m.debit_ngn  / NULLIF(m.$rateCol, 0)), 0) AS total_debit,
                COALESCE(SUM(m.credit_ngn / NULLIF(m.$rateCol, 0)), 0) AS total_credit,
                COALESCE(SUM((m.debit_ngn - m.credit_ngn) / NULLIF(m.$rateCol, 0)), 0) AS balance
            FROM ledger_table l
            LEFT JOIN main_journal_table m
                ON  l.ledger_name = m.ledger_name
                AND m.journal_date BETWEEN ? AND ?
            WHERE 1=1 $searchCondition
            GROUP BY l.ledger_name, l.ledger_number, l.ledger_class
            ORDER BY $classSortOrderAliased, l.ledger_number ASC
        ";

        $dataStmt = $conn->prepare($dataQuery);
        if (!$dataStmt) throw new Exception("Failed to prepare data query: " . $conn->error, 500);

        $bindTypes  = "ss" . $searchTypes;
        $bindParams = array_merge([$datefrom, $dateto], $searchParams);
        $dataStmt->bind_param($bindTypes, ...$bindParams);

    } else {
        // Only ledgers with transactions
        $dataQuery = "
            SELECT
                ledger_name,
                ledger_number,
                ledger_class,
                SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
                SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit,
                SUM((debit_ngn - credit_ngn) / NULLIF($rateCol, 0)) AS balance
            FROM main_journal_table
            WHERE journal_date BETWEEN ? AND ? $searchCondition
            GROUP BY ledger_number, ledger_name, ledger_class
            ORDER BY $classSortOrderPlain, ledger_number ASC
        ";

        $dataStmt = $conn->prepare($dataQuery);
        if (!$dataStmt) throw new Exception("Failed to prepare data query: " . $conn->error, 500);

        $bindTypes  = "ss" . $searchTypes;
        $bindParams = array_merge([$datefrom, $dateto], $searchParams);
        $dataStmt->bind_param($bindTypes, ...$bindParams);
    }

    $dataStmt->execute();
    $result = $dataStmt->get_result();

    /**
     * Group data by ledger_class & Calculate Subtotals
     */
    $groupedData = [
        'Asset'     => ['records' => [], 'sub_total_debit' => 0, 'sub_total_credit' => 0],
        'Equity'    => ['records' => [], 'sub_total_debit' => 0, 'sub_total_credit' => 0],
        'Revenue'   => ['records' => [], 'sub_total_debit' => 0, 'sub_total_credit' => 0],
        'Liability' => ['records' => [], 'sub_total_debit' => 0, 'sub_total_credit' => 0],
        'Expense'   => ['records' => [], 'sub_total_debit' => 0, 'sub_total_credit' => 0]
    ];

    $totalRecords = 0;

    while ($row = $result->fetch_assoc()) {
        $class = $row['ledger_class'];
        
        // Format numbers to 2 decimal places for cleaner frontend display
        $row['total_debit']  = (float) $row['total_debit'];
        $row['total_credit'] = (float) $row['total_credit'];
        $row['balance']      = (float) $row['balance'];

        // If the class exists in our predefined array
        if (isset($groupedData[$class])) {
            $groupedData[$class]['records'][] = $row;
            
            // Add to subtotals
            $groupedData[$class]['sub_total_debit']  += $row['total_debit'];
            $groupedData[$class]['sub_total_credit'] += $row['total_credit'];
        } else {
            // Handle unexpected classes dynamically
            if (!isset($groupedData[$class])) {
                $groupedData[$class] = ['records' => [], 'sub_total_debit' => 0, 'sub_total_credit' => 0];
            }
            $groupedData[$class]['records'][] = $row;
            $groupedData[$class]['sub_total_debit']  += $row['total_debit'];
            $groupedData[$class]['sub_total_credit'] += $row['total_credit'];
        }
        
        $totalRecords++;
    }

    $dataStmt->close();

    /**
     * 2. Grand Totals
     */
    $totalsQuery = "
        SELECT
            SUM(debit_ngn  / NULLIF($rateCol, 0)) AS grand_total_debit,
            SUM(credit_ngn / NULLIF($rateCol, 0)) AS grand_total_credit,
            SUM((debit_ngn - credit_ngn) / NULLIF($rateCol, 0)) AS grand_total_balance
        FROM main_journal_table
        WHERE journal_date BETWEEN ? AND ?
    ";

    $totalsStmt = $conn->prepare($totalsQuery);
    if (!$totalsStmt) {
        throw new Exception("Failed to prepare totals query: " . $conn->error, 500);
    }

    $totalsStmt->bind_param("ss", $datefrom, $dateto);
    $totalsStmt->execute();
    $grandTotals = $totalsStmt->get_result()->fetch_assoc();
    $totalsStmt->close();

    http_response_code(200);

    echo json_encode([
        "status"  => "Success",
        "message" => "Trial Balance report fetched successfully",
        "data"    => $groupedData,
        "totals"  => [
            "grand_total_debit"  => (float) $grandTotals['grand_total_debit'],
            "grand_total_credit" => (float) $grandTotals['grand_total_credit'],
            "grand_total_balance"=> (float) $grandTotals['grand_total_balance']
        ],
        "meta"    => [
            "total_records" => $totalRecords,
            "currency"      => $currency,
            "datefrom"      => $datefrom,
            "dateto"        => $dateto,
            "zerobal"       => $zerobal,
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