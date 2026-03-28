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
     * Validate journal_id input
     */
    if (!isset($_GET['journal_id']) || empty($_GET['journal_id'])) {
        throw new Exception("Missing required parameter: 'journal_id'.", 400);
    }

    $journal_id = (int) $_GET['journal_id'];

    if ($journal_id <= 0) {
        throw new Exception("Invalid 'journal_id' provided.", 400);
    }

    /**
     * 1. Fetch Journal Header
     */
    $stmtHeader = $conn->prepare("
        SELECT 
            id,
            journal_id,
            journal_date,
            journal_type,
            journal_currency,
            transaction_type,
            journal_description,
            debit,
            credit,
            debit_ngn,
            credit_ngn,
            debit_others,
            credit_others,
            cost_center,
            rate_date,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM journal_table 
        WHERE journal_id = ?
    ");

    if (!$stmtHeader) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtHeader->bind_param("i", $journal_id);
    $stmtHeader->execute();
    $resultHeader = $stmtHeader->get_result();
    $headerData = $resultHeader->fetch_assoc();
    $stmtHeader->close();

    if (!$headerData) {
        throw new Exception("Journal with ID {$journal_id} not found.", 404);
    }

    /**
     * 2. Fetch Journal Line Items
     */
    $stmtItems = $conn->prepare("
        SELECT 
            id,
            journal_id,
            journal_date,
            journal_currency,
            transaction_type,
            journal_description,
            debit,
            credit,
            rate,
            rate_date,
            debit_ngn,
            credit_ngn,
            ngn_rate,
            usd_rate,
            eur_rate,
            gbp_rate,
            cost_center,
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
        FROM main_journal_table 
        WHERE journal_id = ?
    ");

    if (!$stmtItems) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtItems->bind_param("i", $journal_id);
    $stmtItems->execute();
    $resultItems = $stmtItems->get_result();
    
    $items = [];
    while ($row = $resultItems->fetch_assoc()) {
        $items[] = $row;
    }
    $stmtItems->close();

    /**
     * 3. Combine Data
     */
    $responseData = $headerData;
    $responseData['items'] = $items;

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Journal fetched successfully",
        "data" => $responseData
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}