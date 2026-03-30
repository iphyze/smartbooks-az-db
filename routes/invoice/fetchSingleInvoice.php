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
     * Validate invoice_number input
     */
    if (!isset($_GET['invoice_number']) || empty($_GET['invoice_number'])) {
        throw new Exception("Missing required parameter: 'invoice_number'.", 400);
    }

    $invoice_number = $_GET['invoice_number'];

    /**
     * 1. Fetch Invoice Header
     */
    $stmtHeader = $conn->prepare("
        SELECT 
            id,
            invoice_number,
            invoice_date,
            due_date,
            clients_name,
            clients_id,
            project,
            invoice_amount,
            currency,
            status,
            bank_name,
            account_name,
            account_number,
            account_currency,
            tin_number,
            paid,
            rate_date,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM invoice_table 
        WHERE invoice_number = ?
    ");

    if (!$stmtHeader) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtHeader->bind_param("s", $invoice_number);
    $stmtHeader->execute();
    $resultHeader = $stmtHeader->get_result();
    $headerData = $resultHeader->fetch_assoc();
    $stmtHeader->close();

    if (!$headerData) {
        throw new Exception("Invoice with number {$invoice_number} not found.", 404);
    }

    $clients_id = $headerData['clients_id'] ?? null;

    /**
     * 2. Fetch Invoice Line Items
     */
    $stmtItems = $conn->prepare("
        SELECT 
            id,
            invoice_number,
            clients_name,
            clients_id,
            description,
            amount,
            discount_percent,
            vat_percent,
            wht_percent,
            discount,
            vat,
            wht,
            total,
            rate_date,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM main_invoice_table 
        WHERE invoice_number = ?
    ");

    if (!$stmtItems) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtItems->bind_param("s", $invoice_number);
    $stmtItems->execute();
    $resultItems = $stmtItems->get_result();
    
    $items = [];
    while ($row = $resultItems->fetch_assoc()) {
        $items[] = $row;
    }
    $stmtItems->close();

    /**
     * 3. Fetch Company Data (profile_table)
     */
    $companyData = null;

    $stmtCompany = $conn->prepare("
        SELECT 
            id,
            office_address,
            email,
            tel,
            account_name,
            account_number,
            bank_name,
            tin,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM profile_table
        LIMIT 1
    ");

    if (!$stmtCompany) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtCompany->execute();
    $resultCompany = $stmtCompany->get_result();
    $companyData = $resultCompany->fetch_assoc();
    $stmtCompany->close();

    /**
     * 4. Fetch Clients Data
     */
    $clientsData = null;

    if ($clients_id) {
        $stmtClient = $conn->prepare("
            SELECT 
                id,
                clients_id,
                clients_name,
                clients_email,
                clients_address,
                clients_number,
                created_at,
                created_by,
                updated_at,
                updated_by
            FROM clients_table
            WHERE clients_id = ?
        ");

        if (!$stmtClient) {
            throw new Exception("Database error: " . $conn->error, 500);
        }

        $stmtClient->bind_param("i", $clients_id);
        $stmtClient->execute();
        $resultClient = $stmtClient->get_result();
        $clientsData = $resultClient->fetch_assoc();
        $stmtClient->close();
    }

    /**
     * 5. Combine Data
     */
    $responseData = $headerData;
    $responseData['items'] = $items;
    $responseData['company_data'] = $companyData;
    $responseData['clients_data'] = $clientsData;

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Invoice fetched successfully",
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