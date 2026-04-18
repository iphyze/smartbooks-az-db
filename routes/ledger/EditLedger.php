<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can update ledgers", 401);
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
    if (empty($data['ledger_number'])) {
        throw new Exception("Ledger Number is required.", 400);
    }

    if (empty($data['ledger_name'])) {
        throw new Exception("Please ensure that the ledger name is filled!", 400);
    }

    if (empty($data['account_type'])) {
        throw new Exception("Please ensure that the account type is selected!", 400);
    }

    // Input variables
    $current_ledger_number = trim($data['ledger_number']); // The ID used to find the record
    $ledger_name = trim($data['ledger_name']);
    $account_type = trim($data['account_type']);
    $updated_by = $userEmail;

    // Start Transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Fetch Account Type Details
         * Using account_table as per instruction
         */
        $stmtAccount = $conn->prepare("SELECT category_id, category, sub_category, type FROM account_table WHERE type = ? LIMIT 1");
        
        if (!$stmtAccount) {
            throw new Exception("Database error (prepare account_type): " . $conn->error, 500);
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
         * 2. Fetch Current Ledger Details
         * We need to check if the type is changing to determine if we need a new number
         */
        $stmtCurrent = $conn->prepare("SELECT ledger_type FROM ledger_table WHERE ledger_number = ? LIMIT 1");
        $stmtCurrent->bind_param("s", $current_ledger_number);
        $stmtCurrent->execute();
        $resCurrent = $stmtCurrent->get_result();
        $currentLedgerData = $resCurrent->fetch_assoc();
        $stmtCurrent->close();

        if (!$currentLedgerData) {
            throw new Exception("Ledger with number $current_ledger_number not found.", 404);
        }

        $current_type = $currentLedgerData['ledger_type'];

        /**
         * 3. Determine New Ledger Number
         * If account type changed, generate a new number. Otherwise, keep the old one.
         */
        $new_ledger_number = $current_ledger_number;

        if ($current_type != $account_type) {
            // Type changed, need a new number for the new class
            $stmtMax = $conn->prepare("SELECT MAX(ledger_number) AS max_ledger_number FROM ledger_table WHERE ledger_class_code = ?");
            $stmtMax->bind_param("s", $category_id);
            $stmtMax->execute();
            $resMax = $stmtMax->get_result();
            $rowMax = $resMax->fetch_assoc();
            $max_ledger_number = $rowMax['max_ledger_number'];
            $stmtMax->close();

            if (is_null($max_ledger_number)) {
                $new_ledger_number = $category_id + 1;
            } else {
                $new_ledger_number = $max_ledger_number + 1;
            }
        }

        /**
         * 4. Check for Duplicate Name
         * Ensure the new name doesn't exist for a *different* ledger
         */
        $stmtCheck = $conn->prepare("SELECT id FROM ledger_table WHERE ledger_name = ? AND ledger_number != ? LIMIT 1");
        $stmtCheck->bind_param("ss", $ledger_name, $current_ledger_number);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        
        if ($resCheck->num_rows > 0) {
            throw new Exception("Ledger name '$ledger_name' already exists!", 400);
        }
        $stmtCheck->close();

        /**
         * 5. Update Ledger Table
         */
        $stmtUpdateLedger = $conn->prepare("
            UPDATE ledger_table 
            SET 
                ledger_name = ?, 
                ledger_number = ?, 
                ledger_class = ?, 
                ledger_class_code = ?, 
                ledger_sub_class = ?, 
                ledger_type = ?, 
                updated_by = ? 
            WHERE ledger_number = ?
        ");

        if (!$stmtUpdateLedger) {
            throw new Exception("Database error (prepare ledger update): " . $conn->error, 500);
        }

        $stmtUpdateLedger->bind_param(
            "ssssssss", 
            $ledger_name,
            $new_ledger_number,
            $category,
            $category_id,
            $sub_category,
            $type,
            $updated_by,
            $current_ledger_number // Where condition
        );

        if (!$stmtUpdateLedger->execute()) {
            throw new Exception("Error updating ledger table: " . $stmtUpdateLedger->error, 500);
        }
        $stmtUpdateLedger->close();

        /**
         * 6. Update Main Journal Table (Cascade Changes)
         * Propagate the changes to existing journal entries
         */
        $stmtUpdateJournal = $conn->prepare("
            UPDATE main_journal_table 
            SET 
                ledger_name = ?, 
                ledger_number = ?, 
                ledger_class = ?, 
                ledger_class_code = ?, 
                ledger_sub_class = ?, 
                ledger_type = ?, 
                updated_by = ? 
            WHERE ledger_number = ?
        ");

        if (!$stmtUpdateJournal) {
            throw new Exception("Database error (prepare journal update): " . $conn->error, 500);
        }

        $stmtUpdateJournal->bind_param(
            "ssssssss", 
            $ledger_name,
            $new_ledger_number,
            $category,
            $category_id,
            $sub_category,
            $type,
            $updated_by,
            $current_ledger_number // Old ledger number
        );

        if (!$stmtUpdateJournal->execute()) {
            throw new Exception("Error updating Main Journal table: " . $stmtUpdateJournal->error, 500);
        }
        $stmtUpdateJournal->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail updated Ledger: $ledger_name (ID: $current_ledger_number -> $new_ledger_number)";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        echo json_encode([
            "status" => "Success",
            "message" => "Ledger Table and Main Journal Table updated successfully!",
            "data" => [
                "old_ledger_number" => $current_ledger_number,
                "new_ledger_number" => $new_ledger_number,
                "ledger_name" => $ledger_name
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