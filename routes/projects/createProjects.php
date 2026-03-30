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
        throw new Exception("Unauthorized: Only Admins or Controllers can create projects", 401);
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
    $requiredFields = ['project_name', 'project_code'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $project_name = trim($data['project_name']);
    $project_code = trim($data['project_code']);

    /**
     * Specific Logic from source: Adjust project_code if it is 1
     */
    if ($project_code == 1) {
        $project_code = 100 + 1; // Results in 101
    }

    /**
     * Generate Code Logic from source
     */
    $code_word = explode(' ', $project_name);
    $codeWord = '';
    
    // Get the first letter of up to the first 3 words and make them uppercase
    for ($i = 0; $i < min(3, count($code_word)); $i++) {
        if (isset($code_word[$i][0])) {
            $codeWord .= strtoupper($code_word[$i][0]);
        }
    }
    
    $code = $codeWord . "-" . $project_code;

    /**
     * Duplicate check in project_table
     */
    $dupStmt = $conn->prepare("SELECT id FROM project_table WHERE project_name = ? LIMIT 1");
    $dupStmt->bind_param("s", $project_name);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("A project with the name {$project_name} already exists.", 400);
    }
    $dupStmt->close();

    // Start Transaction
    $conn->begin_transaction();

    try {
        
        /**
         * Insert Project Data
         * Note: created_at and updated_at are assumed to be handled by MySQL default CURRENT_TIMESTAMP
         */
        $insertStmt = $conn->prepare("
            INSERT INTO project_table 
            (project_name, project_code, code, created_by, updated_by) 
            VALUES (?, ?, ?, ?, ?)
        ");

        $insertStmt->bind_param(
            "sssss", 
            $project_name, 
            $project_code, 
            $code, 
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
        
        $logAction = "$userEmail created a new project ({$project_name}) with code {$code}";

        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        http_response_code(200);

        echo json_encode([
            "status" => "Success",
            "message" => "Project created successfully",
            "data" => [
                "id" => $insertedId,
                "project_name" => $project_name,
                "project_code" => $project_code,
                "generated_code" => $code
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