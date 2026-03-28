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
        throw new Exception("Unauthorized: Only Admins or Controllers can update client details", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    /**
     * Required fields
     */
    $requiredFields = ['clients_id', 'clients_name', 'clients_email', 'clients_number', 'clients_address'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
        // Check for empty strings on string fields
        if (trim($data[$field]) === '') {
             throw new Exception("Field '{$field}' cannot be empty.", 400);
        }
    }

    $clients_id = (int) $data['clients_id'];

    if ($clients_id <= 0) {
        throw new Exception("Invalid Client ID provided.", 400);
    }

    /**
     * Clean inputs
     */
    $clients_name = trim($data['clients_name']);
    $clients_email = trim($data['clients_email']);
    $clients_number = trim($data['clients_number']);
    $clients_address = trim($data['clients_address']);

    /**
     * Validate Email Format
     */
    if (!filter_var($clients_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid Email Format!", 400);
    }

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("
        SELECT clients_id 
        FROM clients_table 
        WHERE clients_id = ?
    ");

    $checkStmt->bind_param("i", $clients_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Client with ID {$clients_id} not found.", 404);
    }

    $checkStmt->close();

    /**
     * Duplicate check (exclude current record)
     * Prevent same clients_name under a different ID
     */
    $dupStmt = $conn->prepare("
        SELECT clients_id
        FROM clients_table
        WHERE clients_name = ?
        AND clients_id != ?
        LIMIT 1
    ");

    // Bind types: s (string for clients_name), i (integer for id)
    $dupStmt->bind_param("si", $clients_name, $clients_id);
    $dupStmt->execute();

    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception(
            "Sorry, " . $clients_name . " already exists in your client's list.",
            400
        );
    }

    $dupStmt->close();

    /**
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE clients_table
        SET clients_name = ?, clients_email = ?, clients_number = ?, clients_address = ?, updated_by = ?, updated_at = NOW()
        WHERE clients_id = ?
    ");

    // Bind types: s (string), s (string), s (string), s (string), s (string), i (integer)
    $updateStmt->bind_param(
        "sssssi",
        $clients_name,
        $clients_email,
        $clients_number,
        $clients_address,
        $userEmail,
        $clients_id
    );

    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }

    $updateStmt->close();

    /**
     * Log action
     */
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");

    $action = "$userEmail updated client details ({$clients_name}) [ID {$clients_id}]";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
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
        WHERE clients_id = ?"
    );

    $fetchStmt->bind_param("i", $clients_id);
    $fetchStmt->execute();

    $updatedData = $fetchStmt->get_result()->fetch_assoc();

    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Client updated successfully",
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