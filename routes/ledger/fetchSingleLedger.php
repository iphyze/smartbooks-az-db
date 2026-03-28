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
     * Validate ledger_number input
     */
    if (!isset($_GET['ledger_number']) || empty($_GET['ledger_number'])) {
        throw new Exception("Missing required parameter: 'ledger_number'.", 400);
    }

    $ledger_number = $_GET['ledger_number'];

    /**
     * Fetch Ledger Record
     */
    $stmt = $conn->prepare("
        SELECT 
            id,
            ledger_name,
            ledger_number,
            ledger_class,
            ledger_class_code,
            ledger_sub_class,
            ledger_type,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM ledger_table 
        WHERE ledger_number = ?
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    // Using 's' for ledger_number as it is often a string or large integer code
    $stmt->bind_param("s", $ledger_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $ledgerData = $result->fetch_assoc();
    $stmt->close();

    if (!$ledgerData) {
        throw new Exception("Ledger with number {$ledger_number} not found.", 404);
    }

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Ledger fetched successfully",
        "data" => $ledgerData
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}