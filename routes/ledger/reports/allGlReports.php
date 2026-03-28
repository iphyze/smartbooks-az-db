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

    /**
     * Data Query (Grouped by Ledger)
     * 1. We select from ledger_table (l) to ensure ALL ledgers are listed.
     * 2. We LEFT JOIN main_journal_table (m) to get transactions.
     * 3. We move the date filter into the ON clause so ledgers without transactions 
     *    in the period are still returned (with NULL amounts).
     * 4. We use COALESCE to convert NULL sums to 0.
     */
    $dataQuery = "
        SELECT 
            l.ledger_name,
            l.ledger_number,
            COALESCE(SUM(m.debit_ngn / NULLIF(m.$rateCol, 0)), 0) as total_debit,
            COALESCE(SUM(m.credit_ngn / NULLIF(m.$rateCol, 0)), 0) as total_credit,
            COALESCE(SUM((m.debit_ngn - m.credit_ngn) / NULLIF(m.$rateCol, 0)), 0) as balance
        FROM ledger_table l
        LEFT JOIN main_journal_table m 
            ON l.ledger_name = m.ledger_name 
            AND m.journal_date BETWEEN ? AND ?
        GROUP BY l.ledger_name, l.ledger_number
        ORDER BY l.ledger_name ASC
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Bind only dates
    $dataStmt->bind_param("ss", $datefrom, $dateto);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $reportData = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "General Ledger report fetched successfully",
        "data" => $reportData
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}