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
    if (!isset($_GET['currency']) || empty(trim($_GET['currency']))) {
        throw new Exception("Missing required parameter: 'currency' is required.", 400);
    }

    $currency = trim($_GET['currency']);

    /**
     * Main Query for Aging Data
     * We group by client to aggregate their invoice amounts into aging buckets.
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
        WHERE status = 'Pending' AND currency = ?
        GROUP BY clients_id, clients_name, currency
        ORDER BY clients_name ASC
    ";

    $dataStmt = $conn->prepare($dataQuery);

    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $dataStmt->bind_param("s", $currency);
    $dataStmt->execute();

    $result = $dataStmt->get_result();
    $reportData = $result->fetch_all(MYSQLI_ASSOC);

    $dataStmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Invoice aging report fetched successfully",
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