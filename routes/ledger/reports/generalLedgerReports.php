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
    $requiredParams = ['datefrom', 'dateto', 'currency'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim($_GET[$param]))) {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $currency = trim($_GET['currency']); // Reporting Currency (NGN, USD, etc.)

    // Pagination & Search
    $page   = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    /**
     * Determine Rate Column based on selected Currency
     * Matches the logic from the legacy code: debit_ngn / rate
     */
    $rateCol = 'ngn_rate'; // Default
    switch ($currency) {
        case 'NGN': $rateCol = 'ngn_rate'; break;
        case 'USD': $rateCol = 'usd_rate'; break;
        case 'EUR': $rateCol = 'eur_rate'; break;
        case 'GBP': $rateCol = 'gbp_rate'; break;
    }

    // Base Condition for Date Range
    $baseCondition = "WHERE m.journal_date BETWEEN ? AND ?";
    $types = "ss";
    $params = [$datefrom, $dateto];

    // Add Search Condition
    if ($search) {
        $baseCondition .= " AND (m.ledger_name LIKE ? OR m.ledger_number LIKE ?)";
        $types .= "ss";
        $likeSearch = "%" . $search . "%";
        array_push($params, $likeSearch, $likeSearch);
    }

    /**
     * 1. Count Query (Distinct Ledgers)
     */
    $countQuery = "
        SELECT COUNT(DISTINCT m.ledger_number) AS total 
        FROM main_journal_table m
        $baseCondition
    ";

    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }

    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    /**
     * 2. Data Query (Grouped by Ledger)
     * We perform the currency conversion inside the SUM: debit_ngn / rate_col
     * We use NULLIF to prevent division by zero errors.
     */
    $dataQuery = "
        SELECT 
            COALESCE(MAX(l.ledger_name), MAX(m.ledger_name)) AS ledger_name,
            m.ledger_number,
            SUM(m.debit_ngn / NULLIF(m.$rateCol, 0)) AS total_debit,
            SUM(m.credit_ngn / NULLIF(m.$rateCol, 0)) AS total_credit,
            SUM((m.debit_ngn - m.credit_ngn) / NULLIF(m.$rateCol, 0)) AS balance
        FROM main_journal_table m
        LEFT JOIN ledger_table l
            ON l.ledger_number = m.ledger_number
        $baseCondition
        GROUP BY m.ledger_number
        ORDER BY ledger_name ASC
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Append Pagination Params
    $bindTypes = $types . "ii";
    $bindParams = array_merge($params, [$limit, $offset]);

    $dataStmt->bind_param($bindTypes, ...$bindParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $reportData = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    /**
     * 3. Grand Totals (Ignoring Pagination)
     */
    $totalsQuery = "
        SELECT 
            SUM(m.debit_ngn / NULLIF(m.$rateCol, 0)) AS grand_total_debit,
            SUM(m.credit_ngn / NULLIF(m.$rateCol, 0)) AS grand_total_credit,
            SUM((m.debit_ngn - m.credit_ngn) / NULLIF(m.$rateCol, 0)) AS grand_total_balance
        FROM main_journal_table m
        $baseCondition
    ";

    $totalsStmt = $conn->prepare($totalsQuery);
    if (!$totalsStmt) {
        throw new Exception("Failed to prepare totals query: " . $conn->error, 500);
    }

    $totalsStmt->bind_param($types, ...$params);
    $totalsStmt->execute();
    $grandTotals = $totalsStmt->get_result()->fetch_assoc();
    $totalsStmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "General Ledger report fetched successfully",
        "data" => $reportData,
        "totals" => $grandTotals,
        "meta" => [
            "total" => (int) $totalRecords,
            "page"  => $page,
            "limit" => $limit,
            "search" => $search,
            "currency" => $currency,
            "datefrom" => $datefrom,
            "dateto" => $dateto
        ]
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => publicErrorMessage($e)
    ]);
}