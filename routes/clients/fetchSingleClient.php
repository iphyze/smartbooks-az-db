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
     * Validate clientId
     */
    if (!isset($_GET['clientId']) || empty($_GET['clientId'])) {
        throw new Exception("Missing required parameter: 'clientId'.", 400);
    }

    $clientId = (int) $_GET['clientId'];

    /**
     * 1. Fetch Client
     */
    $stmt = $conn->prepare("
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
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $clientData = $result->fetch_assoc();
    $stmt->close();

    if (!$clientData) {
        throw new Exception("Client with ID {$clientId} not found.", 404);
    }

    /**
     * 2. Fetch Invoices for Client
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
        WHERE clients_id = ?
        ORDER BY created_at DESC
    ");

    if (!$stmtInv) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtInv->bind_param("i", $clientId);
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
            "client" => $clientData,
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