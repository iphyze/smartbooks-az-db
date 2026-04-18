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
     * Validate bankId
     */
    if (!isset($_GET['bankId']) || empty($_GET['bankId'])) {
        throw new Exception("Missing required parameter: 'bankId'.", 400);
    }

    $bankId = (int) $_GET['bankId'];

    /**
     * 1. Fetch Bank Account
     */
    $stmt = $conn->prepare("
        SELECT 
            id, 
            account_name, 
            account_number, 
            bank_name, 
            account_currency,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM bank_table
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $bankId);
    $stmt->execute();
    $result = $stmt->get_result();
    $bankData = $result->fetch_assoc();
    $stmt->close();

    if (!$bankData) {
        throw new Exception("Bank account with ID {$bankId} not found.", 404);
    }

    /**
     * 2. Fetch Invoices for Bank Account
     * Match by account_number from bank_table to account_number in invoice_table
     */
    $stmtInv = $conn->prepare("
        SELECT 
            id, 
            invoice_number, 
            invoice_date, 
            clients_name, 
            clients_id, 
            project, 
            paid,
            invoice_amount, 
            account_name, 
            account_number, 
            bank_name, 
            account_currency, 
            status, 
            tin_number, 
            currency, 
            rate_date, 
            due_date, 
            created_at
        FROM invoice_table
        WHERE account_number = ?
        ORDER BY created_at DESC
    ");

    if (!$stmtInv) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    // Bind the account_number to find associated invoices
    $accountNumber = $bankData['account_number'];
    $stmtInv->bind_param("s", $accountNumber);
    $stmtInv->execute();
    $resultInv = $stmtInv->get_result();

    $invoices = [];
    
    // Summary structure
    $summary = [];

    while ($row = $resultInv->fetch_assoc()) {
        $invoices[] = $row;

        $currency = $row['currency']; // grouping key
        $status = strtolower($row['status']);
        $amount = (float) $row['invoice_amount'];

        // Initialize currency bucket if not exists
        if (!isset($summary[$currency])) {
            $summary[$currency] = [
                'pending_total' => 0,
                'pending_count' => 0,
                'paid_total' => 0,
                'paid_count' => 0
            ];
        }

        // Categorize
        if ($status === 'paid') {
            $summary[$currency]['paid_total'] += $amount;
            $summary[$currency]['paid_count'] += 1;
        } else {
            $summary[$currency]['pending_total'] += $amount;
            $summary[$currency]['pending_count'] += 1;
        }
    }

    $stmtInv->close();

    /**
     * 3. Final Response
     */
    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "data" => [
            "bank" => $bankData,
            "invoices" => $invoices,
            "summary" => $summary
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