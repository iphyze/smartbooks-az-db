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
        throw new Exception("Unauthorized: Only Admins or Controllers can update bank accounts", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    /**
     * Required fields
     */
    $requiredFields = ['id', 'account_name', 'account_number', 'bank_name', 'account_currency'];

    foreach ($requiredFields as $field) {
        // For numeric fields like id or account_number, we check if they are set. 
        // We don't use trim() on integers during the validation check.
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
        // For string fields, ensure they aren't empty strings
        if (in_array($field, ['account_name', 'bank_name', 'account_currency']) && trim($data[$field]) === '') {
             throw new Exception("Field '{$field}' cannot be empty.", 400);
        }
    }

    $id = (int) $data['id'];

    if ($id <= 0) {
        throw new Exception("Invalid bank account ID provided.", 400);
    }

    /**
     * Clean inputs
     */
    $account_name = trim($data['account_name']);
    $account_number = (string) trim($data['account_number']);
    $bank_name = trim($data['bank_name']);
    $account_currency = trim($data['account_currency']);

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("
        SELECT id 
        FROM bank_table 
        WHERE id = ?
    ");

    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Bank account with ID {$id} not found.", 404);
    }

    $checkStmt->close();

    /**
     * Duplicate check (exclude current record)
     * Prevent same account number within the same bank name
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM bank_table
        WHERE account_number = ?
        AND bank_name = ?
        AND id != ?
        LIMIT 1
    ");

    // Bind types: i (integer for account_number), s (string for bank_name), i (integer for id)
    $dupStmt->bind_param("ssi", $account_number, $bank_name, $id);
    $dupStmt->execute();

    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception(
            "Duplicate entry: This account number already exists for the specified bank.",
            400
        );
    }

    $dupStmt->close();

    /**
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE bank_table
        SET account_name = ?, account_number = ?, bank_name = ?, account_currency = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?
    ");

    // Bind types: s (string), i (integer), s (string), s (string), s (string), i (integer)
    $updateStmt->bind_param(
        "sssssi",
        $account_name,
        $account_number,
        $bank_name,
        $account_currency,
        $userEmail,
        $id
    );

    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }

    $updateStmt->close();

    /**
     * Log action
     */
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");

    $action = "$userEmail updated bank account ({$bank_name} - {$account_number}) [ID {$id}]";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
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
        WHERE id = ?"
    );

    $fetchStmt->bind_param("i", $id);
    $fetchStmt->execute();

    $updatedData = $fetchStmt->get_result()->fetch_assoc();

    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Bank account updated successfully",
        "data" => $updatedData
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}