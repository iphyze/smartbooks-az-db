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
    // Currency is required for this specific report logic
    if (!isset($_GET['currency']) || empty(trim($_GET['currency']))) {
        throw new Exception("Missing required parameter: 'currency' is required.", 400);
    }

    // Pagination parameters
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $currency = trim($_GET['currency']);
    $limit    = (int) $_GET['limit'];
    $page     = (int) $_GET['page'];

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    /**
     * Base Condition
     * We only consider 'Pending' invoices for aging reports.
     */
    $baseCondition = "WHERE status = 'Pending' AND currency = ?";
    
    // Types for main data query: 
    // 1 for currency, 2 for limit, 3 for offset
    // But for count, we only need currency.
    $types = "s";
    $params = [$currency];

    /**
     * Count total distinct clients for pagination
     */
    $countQuery = "SELECT COUNT(DISTINCT clients_id) AS total FROM invoice_table $baseCondition";
    
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }

    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    /**
     * Main Query for Aging Data
     * We group by client to aggregate their invoice amounts into aging buckets.
     * Logic derived from reference:
     * 0-30: Days <= 30
     * 31-60: Days > 30 AND <= 60
     * 61-90: Days > 60 AND <= 90
     * 91+: Days > 90
     */
    $dataQuery = "
        SELECT 
            clients_id,
            clients_name,
            currency,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 0 AND 30 
                THEN invoice_amount ELSE 0 
            END) AS bucket_0_30,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 31 AND 60 
                THEN invoice_amount ELSE 0 
            END) AS bucket_31_60,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 61 AND 90 
                THEN invoice_amount ELSE 0 
            END) AS bucket_61_90,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) > 90 
                THEN invoice_amount ELSE 0 
            END) AS bucket_91_plus,
            SUM(invoice_amount) AS total_outstanding
        FROM invoice_table 
        $baseCondition
        GROUP BY clients_id, clients_name, currency
        ORDER BY clients_name ASC
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);

    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Append pagination params
    // 's' for currency, 'i' for limit, 'i' for offset
    $bindTypes = $types . "ii";
    $bindParams = array_merge($params, [$limit, $offset]);

    $dataStmt->bind_param($bindTypes, ...$bindParams);
    $dataStmt->execute();

    $result = $dataStmt->get_result();
    $reportData = $result->fetch_all(MYSQLI_ASSOC);

    $dataStmt->close();

    /**
     * Calculate Grand Totals for the entire filtered set (ignoring pagination)
     * This ensures the frontend can display report totals accurately.
     */
    $totalsQuery = "
        SELECT 
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 0 AND 30 
                THEN invoice_amount ELSE 0 
            END) AS total_bucket_0_30,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 31 AND 60 
                THEN invoice_amount ELSE 0 
            END) AS total_bucket_31_60,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 61 AND 90 
                THEN invoice_amount ELSE 0 
            END) AS total_bucket_61_90,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) > 90 
                THEN invoice_amount ELSE 0 
            END) AS total_bucket_91_plus,
            SUM(invoice_amount) AS grand_total_outstanding
        FROM invoice_table 
        $baseCondition
    ";

    $totalsStmt = $conn->prepare($totalsQuery);
    if (!$totalsStmt) {
        throw new Exception("Failed to prepare totals query: " . $conn->error, 500);
    }
    
    // Use original params (just currency) for the totals query
    $totalsStmt->bind_param($types, ...$params);
    $totalsStmt->execute();
    $totalsResult = $totalsStmt->get_result();
    $grandTotals = $totalsResult->fetch_assoc();
    $totalsStmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Invoice aging report fetched successfully",
        "data" => $reportData,
        "totals" => $grandTotals,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page"  => $page,
            "currency" => $currency
        ]
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}