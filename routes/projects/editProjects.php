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
        throw new Exception("Unauthorized: Only Admins or Controllers can update project details", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    /**
     * Required fields
     */
    $requiredFields = ['id', 'project_name', 'project_code'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
        if (trim($data[$field]) === '') {
             throw new Exception("Field '{$field}' cannot be empty.", 400);
        }
    }

    $id = (int) $data['id'];

    if ($id <= 0) {
        throw new Exception("Invalid Project ID provided.", 400);
    }

    /**
     * Clean inputs
     */
    $project_name = trim($data['project_name']);
    $project_code = trim($data['project_code']);

    /**
     * Generate Code Logic from source
     */
    $code_word = explode(' ', $project_name);
    $codeWord = '';
    
    for ($i = 0; $i < min(3, count($code_word)); $i++) {
        if (isset($code_word[$i][0])) {
            $codeWord .= strtoupper($code_word[$i][0]);
        }
    }
    
    $code = $codeWord . "-" . $project_code;

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("
        SELECT id 
        FROM project_table 
        WHERE id = ?
    ");

    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Project with ID {$id} not found.", 404);
    }

    $checkStmt->close();

    /**
     * Duplicate check (exclude current record)
     * Prevents the same project_name under a different ID
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM project_table
        WHERE project_name = ?
        AND id != ?
        LIMIT 1
    ");

    $dupStmt->bind_param("si", $project_name, $id);
    $dupStmt->execute();

    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception(
            "Sorry, " . $project_name . " already exists in your project's list.",
            400
        );
    }

    $dupStmt->close();

    /**
     * Update record
     * Using the primary key 'id' for the WHERE clause is safer than using project_code,
     * in case the user decides to change the project_code during the update.
     */
    $updateStmt = $conn->prepare("
        UPDATE project_table
        SET project_name = ?, project_code = ?, code = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $updateStmt->bind_param(
        "ssssi",
        $project_name,
        $project_code,
        $code,
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

    $action = "$userEmail updated project details ({$project_name}) [ID {$id}]";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT 
            id,
            project_name,
            project_code,
            code,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM project_table
        WHERE id = ?"
    );

    $fetchStmt->bind_param("i", $id);
    $fetchStmt->execute();

    $updatedData = $fetchStmt->get_result()->fetch_assoc();

    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Project updated successfully",
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