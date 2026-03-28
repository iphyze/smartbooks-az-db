<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can create clients", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Required fields validation
     */
    $requiredFields = ['clients_id', 'clients_name', 'clients_email', 'clients_number', 'clients_address', 'create_ledger'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $clients_id = trim($data['clients_id']);
    $clients_name = trim($data['clients_name']);
    $clients_email = trim($data['clients_email']);
    $clients_number = trim($data['clients_number']);
    $clients_address = trim($data['clients_address']);
    $create_ledger = trim($data['create_ledger']); // Expected "Yes" or "No"

    /**
     * Specific Logic from source: Adjust clients_id if it is 1
     */
    if ($clients_id == 1) {
        $clients_id = 5000 + 1;
    }

    /**
     * Email Format Validation
     */
    if (!filter_var($clients_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("The email you provided is invalid.", 400);
    }

    /**
     * Duplicate check in clients_table
     */
    $dupStmt = $conn->prepare("SELECT id FROM clients_table WHERE clients_name = ? LIMIT 1");
    $dupStmt->bind_param("s", $clients_name);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("A client with the name {$clients_name} already exists.", 400);
    }
    $dupStmt->close();

    /**
     * Variables for Ledger (if needed)
     */
    $ledger_name = $clients_name;
    $account_type = "Service Customers";
    
    // Initialize ledger variables
    $category_id = null;
    $category = null;
    $sub_category = null;
    $type = null;
    $ledger_number = null;

    // Start Transaction
    $conn->begin_transaction();

    try {
        
        /**
         * Handle Ledger Generation
         */
        if ($create_ledger === "Yes") {
            
            // 1. Get Account Details
            $accStmt = $conn->prepare("SELECT category_id, category, sub_category, type FROM account_table WHERE type = ?");
            $accStmt->bind_param("s", $account_type);
            $accStmt->execute();
            $accResult = $accStmt->get_result();

            if ($accResult->num_rows === 0) {
                throw new Exception("Account type '{$account_type}' not found!", 404);
            }

            $accountData = $accResult->fetch_assoc();
            $category_id = $accountData['category_id'];
            $category = $accountData['category'];
            $sub_category = $accountData['sub_category'];
            $type = $accountData['type'];
            $accStmt->close();

            // 2. Check for duplicate ledger name
            $ledgerCheck = $conn->prepare("SELECT id FROM ledger_table WHERE ledger_name = ? LIMIT 1");
            $ledgerCheck->bind_param("s", $ledger_name);
            $ledgerCheck->execute();
            if ($ledgerCheck->get_result()->num_rows > 0) {
                throw new Exception("Ledger name '{$ledger_name}' already exists in ledger_table.", 400);
            }
            $ledgerCheck->close();

            // 3. Calculate next ledger number
            // Logic: MAX(ledger_number) + 1 within the same category_id
            $numStmt = $conn->prepare("SELECT MAX(ledger_number) AS max_num FROM ledger_table WHERE ledger_class_code = ?");
            $numStmt->bind_param("s", $category_id); // assuming category_id is the code used for grouping
            $numStmt->execute();
            $numResult = $numStmt->get_result()->fetch_assoc();
            
            if (is_null($numResult['max_num'])) {
                $ledger_number = $category_id + 1;
            } else {
                $ledger_number = $numResult['max_num'] + 1;
            }
            $numStmt->close();

            // 4. Insert into ledger_table
            $ledgerStmt = $conn->prepare("
                INSERT INTO ledger_table 
                (ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type, created_by, updated_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $ledgerStmt->bind_param(
                "sissssss", 
                $ledger_name, 
                $ledger_number, 
                $category, 
                $category_id, 
                $sub_category, 
                $type, 
                $userEmail, 
                $userEmail
            );

            if (!$ledgerStmt->execute()) {
                throw new Exception("Failed to create client ledger: " . $ledgerStmt->error, 500);
            }
            $ledgerStmt->close();
        }

        /**
         * Insert Client Data
         */
        $insertStmt = $conn->prepare("
            INSERT INTO clients_table 
            (clients_id, clients_name, clients_email, clients_number, clients_address, created_by, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $insertStmt->bind_param(
            "sssssss", 
            $clients_id, 
            $clients_name, 
            $clients_email, 
            $clients_number, 
            $clients_address, 
            $userEmail, 
            $userEmail
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Database insert failed: " . $insertStmt->error, 500);
        }

        $insertedId = $insertStmt->insert_id;
        $insertStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        
        $logAction = "$userEmail created a new client ({$clients_name})";
        if ($create_ledger === "Yes") {
            $logAction .= " with ledger #{$ledger_number}";
        }

        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        http_response_code(200);

        echo json_encode([
            "status" => "Success",
            "message" => "Client created successfully",
            "data" => [
                "id" => $insertedId,
                "clients_id" => $clients_id,
                "clients_name" => $clients_name,
                "clients_email" => $clients_email,
                "ledger_generated" => ($create_ledger === "Yes"),
                "ledger_number" => $ledger_number
            ]
        ]);

    } catch (Exception $e) {
        // Rollback Transaction on error
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}