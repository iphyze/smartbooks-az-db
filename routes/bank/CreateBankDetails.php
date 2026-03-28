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
        throw new Exception("Unauthorized: Only Admins or Controllers can create bank accounts", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Required fields
     */
    $requiredFields = ['account_name', 'account_number', 'bank_name', 'account_currency'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $account_name = trim($data['account_name']);
    $account_number  = (int) $data['account_number']; // Cast to integer
    $bank_name     = trim($data['bank_name']);
    $account_currency = trim($data['account_currency']);


    /**
     * Duplicate check
     * Prevent same account number within same bank
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM bank_table
        WHERE account_number = ? AND bank_name = ?
        LIMIT 1
    ");

    // Bind types: 'i' for integer (account_number), 's' for string (bank_name)
    $dupStmt->bind_param("is", $account_number, $bank_name);
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
     * Insert bank account
     * updated_at and updated_by are left NULL on creation
     */
    $insertStmt = $conn->prepare("
        INSERT INTO bank_table
        (account_name, account_number, bank_name, account_currency, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");

    // Bind types: s(string), i(integer), s(string), s(string), s(string)
    $insertStmt->bind_param(
        "sisss",
        $account_name,
        $account_number,
        $bank_name,
        $account_currency,
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
    $logStmt = $conn->prepare("
        INSERT INTO logs (userId, action, created_by)
        VALUES (?, ?, ?)
    ");

    $action = "$userEmail created a new bank account ({$bank_name} - {$account_number})";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);

    $logStmt->execute();
    $logStmt->close();


    http_response_code(201);

    echo json_encode([
        "status" => "Success",
        "message" => "Bank account created successfully",
        "data" => [
            "id" => $insertedId,
            "account_name" => $account_name,
            "account_number" => $account_number,
            "bank_name" => $bank_name,
            "account_currency" => $account_currency,
            "created_by" => $userEmail
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