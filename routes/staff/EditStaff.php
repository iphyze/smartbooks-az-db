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
        throw new Exception("Unauthorized: Only Admins or Controllers can update staff details", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    /**
     * Required fields validation
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
        'bank_account_name'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // ID for lookup
    $staff_id = trim($data['staff_id']);

    /**
     * Clean inputs
     */
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
    
    // Optional fields (not strictly validated for empty in legacy code)
    $pension_number = isset($data['pension_number']) ? trim($data['pension_number']) : '';
    $payee_id = isset($data['payee_id']) ? trim($data['payee_id']) : '';

    /**
     * Validate Email Format
     */
    if (!filter_var($staff_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("The email you provided is invalid!", 400);
    }

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("SELECT staff_id FROM staff_table WHERE staff_id = ?");
    
    // Using 's' because staff_id in legacy code is treated as a string/varchar in queries
    $checkStmt->bind_param("s", $staff_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Staff with ID {$staff_id} not found.", 404);
    }

    $checkStmt->close();

    /**
     * Duplicate check (exclude current record)
     * Prevent same staff_email under a different ID
     */
    $dupStmt = $conn->prepare("
        SELECT staff_id
        FROM staff_table
        WHERE staff_email = ?
        AND staff_id != ?
        LIMIT 1
    ");

    $dupStmt->bind_param("ss", $staff_email, $staff_id);
    $dupStmt->execute();

    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Sorry, the email " . $staff_email . " already exists in your staff list.", 400);
    }

    $dupStmt->close();

    /**
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE staff_table
        SET 
            staff_name = ?, 
            staff_email = ?, 
            staff_tel = ?, 
            staff_address = ?, 
            date_of_birth = ?, 
            gender = ?, 
            job_title = ?, 
            date_of_joining = ?, 
            bank_name = ?, 
            bank_account_number = ?, 
            bank_account_name = ?, 
            pension_number = ?, 
            payee_id = ?, 
            updated_by = ?, 
            updated_at = NOW()
        WHERE staff_id = ?
    ");

    // Bind types: 14 strings (s) + 1 string (s) for ID
    $updateStmt->bind_param(
        "sssssssssssssss",
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
        $staff_id
    );

    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }

    $updateStmt->close();

    /**
     * Log action
     */
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");

    $action = "$userEmail updated staff details ({$staff_name}) [ID {$staff_id}]";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT 
            id,
            staff_id,
            staff_name,
            staff_email,
            staff_tel,
            staff_address,
            date_of_birth,
            gender,
            job_title,
            date_of_joining,
            bank_name,
            bank_account_number,
            bank_account_name,
            pension_number,
            payee_id,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM staff_table
        WHERE staff_id = ?
    ");

    $fetchStmt->bind_param("s", $staff_id);
    $fetchStmt->execute();

    $updatedData = $fetchStmt->get_result()->fetch_assoc();

    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Staff updated successfully",
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