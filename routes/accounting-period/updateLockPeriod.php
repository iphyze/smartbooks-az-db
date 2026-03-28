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
        throw new Exception("Unauthorized: Only Admins can update accounting periods", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['id', 'start_date', 'end_date', 'is_locked'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $id = (int)$data['id'];

    if ($id <= 0) {
        throw new Exception("Invalid accounting period ID.", 400);
    }

    // Clean inputs
    $start_date = trim($data['start_date']);
    $end_date = trim($data['end_date']);

    // Validate boolean
    if (!is_bool($data['is_locked'])) {
        throw new Exception("is_locked must be true or false.", 400);
    }

    $is_locked = $data['is_locked'] ? 1 : 0;

    $lock_reason = isset($data['lock_reason']) ? trim($data['lock_reason']) : null;

    if (strtotime($start_date) > strtotime($end_date)) {
        throw new Exception("Start date cannot be greater than end date.", 400);
    }

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("
        SELECT id 
        FROM accounting_periods 
        WHERE id = ?
    ");

    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Accounting period with ID {$id} not found.", 404);
    }

    $checkStmt->close();


    /**
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE accounting_periods
        SET start_date = ?, 
            end_date = ?, 
            is_locked = ?, 
            lock_reason = ?, 
            updated_by = ?, 
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateStmt->bind_param(
        "ssissi",
        $start_date,
        $end_date,
        $is_locked,
        $lock_reason,
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
    $logStmt = $conn->prepare("
        INSERT INTO logs (userId, action, created_by)
        VALUES (?, ?, ?)
    ");

    $action = "$userEmail updated accounting period ID {$id} ({$start_date} to {$end_date})";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();


    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT id, start_date, end_date, 
               IF(is_locked = 1, true, false) AS is_locked,
               lock_reason, created_by, created_at, updated_by, updated_at
        FROM accounting_periods
        WHERE id = ?
    ");

    $fetchStmt->bind_param("i", $id);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();


    echo json_encode([
        "status" => "Success",
        "message" => "Accounting period updated successfully",
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