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
        throw new Exception("Unauthorized: Only Admins or Controllers can create staff records", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Required fields validation (Based on legacy logic)
     */
    $requiredFields = [
        'staff_id', 
        'staff_name', 
        'staff_email', 
        'staff_tel', 
        'staff_address', 
        'date_of_birth', 
        'gender', 
        'job_title', 
        'date_of_joining', 
        'bank_name', 
        'bank_account_number', 
        'bank_account_name', 
        'generate_staff' // "Yes" or "No"
    ];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $staff_id = trim($data['staff_id']);
    $staff_name = trim($data['staff_name']);
    $staff_email = trim($data['staff_email']);
    $staff_tel = trim($data['staff_tel']);
    $staff_address = trim($data['staff_address']);
    $date_of_birth = trim($data['date_of_birth']);
    $gender = trim($data['gender']);
    $job_title = trim($data['job_title']);
    $date_of_joining = trim($data['date_of_joining']);
    $bank_name = trim($data['bank_name']);
    $bank_account_number = trim($data['bank_account_number']);
    $bank_account_name = trim($data['bank_account_name']);
    $pension_number = isset($data['pension_number']) ? trim($data['pension_number']) : '';
    $payee_id = isset($data['payee_id']) ? trim($data['payee_id']) : '';
    $generate_staff = trim($data['generate_staff']);

    /**
     * Specific Logic: Adjust staff_id if it is 1
     */
    if ($staff_id == 1) {
        $staff_id = 11001 + 1;
    }

    /**
     * Email Format Validation
     */
    if (!filter_var($staff_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("The email you provided is invalid.", 400);
    }

    /**
     * Duplicate check in staff_table (checking by email)
     */
    $dupStmt = $conn->prepare("SELECT id FROM staff_table WHERE staff_email = ? LIMIT 1");
    $dupStmt->bind_param("s", $staff_email);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("A staff with the email {$staff_email} already exists.", 400);
    }
    $dupStmt->close();

    /**
     * Variables for Ledger (if needed)
     */
    $ledger_name = $staff_name . ' (Employee)';
    $account_type = "Payroll and Similar Accounts";
    
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
        if ($generate_staff === "Yes") {
            
            // 1. Get Account Details from account_table
            $accStmt = $conn->prepare("SELECT category_id, category, sub_category, type FROM account_table WHERE type = ?");
            $accStmt->bind_param("s", $account_type);
            $accStmt->execute();
            $accResult = $accStmt->get_result();

            if ($accResult->num_rows === 0) {
                throw new Exception("Account type '{$account_type}' not found in account_table!", 404);
            }

            $accountData = $accResult->fetch_assoc();
            $category_id = $accountData['category_id'];
            $category = $accountData['category'];
            $sub_category = $accountData['sub_category'];
            $type = $accountData['type'];
            $accStmt->close();

            // 2. Check for duplicate ledger name in ledger_table
            $ledgerCheck = $conn->prepare("SELECT id FROM ledger_table WHERE ledger_name = ? LIMIT 1");
            $ledgerCheck->bind_param("s", $ledger_name);
            $ledgerCheck->execute();
            if ($ledgerCheck->get_result()->num_rows > 0) {
                throw new Exception("Ledger name '{$ledger_name}' already exists in ledger_table.", 400);
            }
            $ledgerCheck->close();

            // 3. Calculate next ledger number
            $numStmt = $conn->prepare("SELECT MAX(ledger_number) AS max_num FROM ledger_table WHERE ledger_class_code = ?");
            $numStmt->bind_param("s", $category_id); 
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
                throw new Exception("Failed to create staff ledger: " . $ledgerStmt->error, 500);
            }
            $ledgerStmt->close();
        }

        /**
         * Insert Staff Data
         */
        $insertStmt = $conn->prepare("
            INSERT INTO staff_table 
            (staff_id, staff_name, staff_email, staff_tel, staff_address, date_of_birth, gender, job_title, date_of_joining, bank_name, bank_account_number, bank_account_name, pension_number, payee_id, created_by, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $insertStmt->bind_param(
            "ssssssssssssssss", 
            $staff_id, 
            $staff_name, 
            $staff_email, 
            $staff_tel, 
            $staff_address, 
            $date_of_birth, 
            $gender, 
            $job_title, 
            $date_of_joining, 
            $bank_name, 
            $bank_account_number, 
            $bank_account_name, 
            $pension_number, 
            $payee_id, 
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
        
        $logAction = "$userEmail created a new staff member ({$staff_name})";
        if ($generate_staff === "Yes") {
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
            "message" => "Staff created successfully",
            "data" => [
                "id" => $insertedId,
                "staff_id" => $staff_id,
                "staff_name" => $staff_name,
                "staff_email" => $staff_email,
                "ledger_generated" => ($generate_staff === "Yes"),
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