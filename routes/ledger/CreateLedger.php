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
        throw new Exception("Unauthorized: Only Admins or Controllers can create ledgers", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Validation
     */
    if (empty($data['ledger_name'])) {
        throw new Exception("Please ensure that the ledger name is filled!", 400);
    }

    if (empty($data['account_type'])) {
        throw new Exception("Please ensure that the account type is selected!", 400);
    }

    $ledger_name = trim($data['ledger_name']);
    $account_type = trim($data['account_type']);
    $created_by = $userEmail;
    $updated_by = $userEmail;

    // Start Transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Fetch Account Details from account_table
         * We need category_id, category, sub_category, and type based on the selected account_type.
         */
        $stmtAccount = $conn->prepare("SELECT category_id, category, sub_category, type FROM account_table WHERE type = ? LIMIT 1");
        
        if (!$stmtAccount) {
            throw new Exception("Database error (prepare account): " . $conn->error, 500);
        }

        $stmtAccount->bind_param("s", $account_type);
        $stmtAccount->execute();
        $resultAccount = $stmtAccount->get_result();
        $accountData = $resultAccount->fetch_assoc();
        $stmtAccount->close();

        if (!$accountData) {
            throw new Exception("Account type '$account_type' not found in account_table.", 404);
        }

        $category_id = $accountData['category_id'];     // Maps to ledger_class_code
        $category = $accountData['category'];           // Maps to ledger_class
        $sub_category = $accountData['sub_category'];   // Maps to ledger_sub_class
        $type = $accountData['type'];                   // Maps to ledger_type

        /**
         * 2. Check if Ledger Name already exists
         */
        $stmtCheck = $conn->prepare("SELECT id FROM ledger_table WHERE ledger_name = ? LIMIT 1");
        $stmtCheck->bind_param("s", $ledger_name);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        
        if ($resCheck->num_rows > 0) {
            throw new Exception("Ledger name '$ledger_name' already exists!", 400);
        }
        $stmtCheck->close();

        /**
         * 3. Generate Ledger Number
         * Logic: Get MAX(ledger_number) for the specific class. 
         * If null, use base number defined by class. Else, increment by 1.
         */
        $stmtMax = $conn->prepare("SELECT MAX(ledger_number) AS max_ledger_number FROM ledger_table WHERE ledger_class = ?");
        $stmtMax->bind_param("s", $category);
        $stmtMax->execute();
        $resMax = $stmtMax->get_result();
        $rowMax = $resMax->fetch_assoc();
        $max_ledger_number = $rowMax['max_ledger_number'];
        $stmtMax->close();

        if (is_null($max_ledger_number)) {
            // Base numbers for new categories (from reference logic)
            switch ($category) {
                case 'Asset':
                    $ledger_number = 110000010;
                    break;
                case 'Expense':
                    $ledger_number = 510000010;
                    break;
                case 'Liability':
                    $ledger_number = 210000010;
                    break;
                case 'Equity':
                    $ledger_number = 310000010;
                    break;
                case 'Income':
                    $ledger_number = 410000010;
                    break;
                default:
                    // Fallback for unexpected categories
                    $ledger_number = 100000010; 
                    break;
            }
        } else {
            $ledger_number = $max_ledger_number + 1;
        }

        /**
         * 4. Insert into ledger_table
         */
        $stmtInsert = $conn->prepare("
            INSERT INTO ledger_table 
            (ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type, created_by, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Bind types: 
        // s (ledger_name), i (ledger_number), s (ledger_class), s (ledger_class_code), 
        // s (ledger_sub_class), s (ledger_type), s (created_by), s (updated_by)
        $stmtInsert->bind_param(
            "sissssss", 
            $ledger_name,
            $ledger_number,
            $category,
            $category_id,
            $sub_category,
            $type,
            $created_by,
            $updated_by
        );

        if (!$stmtInsert->execute()) {
            throw new Exception("Error inserting ledger: " . $stmtInsert->error, 500);
        }

        $insertedId = $stmtInsert->insert_id;
        $stmtInsert->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail created Ledger: $ledger_name (Code: $ledger_number)";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        http_response_code(201); // 201 Created

        echo json_encode([
            "status" => "Success",
            "message" => "Ledger created successfully!",
            "data" => [
                "id" => $insertedId,
                "ledger_name" => $ledger_name,
                "ledger_number" => $ledger_number,
                "ledger_class" => $category,
                "ledger_type" => $type
            ]
        ]);

    } catch (Exception $e) {
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