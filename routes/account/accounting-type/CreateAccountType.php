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
        throw new Exception("Unauthorized: Only Admins can create account types", 401);
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
    $requiredFields = ['type', 'category_id', 'category', 'sub_category'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $type         = trim($data['type']);
    $category_id  = (int) $data['category_id'];
    $category     = trim($data['category']);
    $sub_category = trim($data['sub_category']);

    if ($category_id <= 0) {
        throw new Exception("Invalid category_id provided.", 400);
    }

    /**
     * Duplicate check
     * Prevent same type within same category
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM account_type_table
        WHERE type = ? AND category_id = ?
        LIMIT 1
    ");

    $dupStmt->bind_param("si", $type, $category_id);
    $dupStmt->execute();

    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception(
            "Duplicate account type detected: This type already exists in the selected category.",
            400
        );
    }

    $dupStmt->close();


    /**
     * Insert account type
     */
    $insertStmt = $conn->prepare("
        INSERT INTO account_type_table
        (type, category_id, category, sub_category, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");

    $insertStmt->bind_param(
        "sisss",
        $type,
        $category_id,
        $category,
        $sub_category,
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

    $action = "$userEmail created a new account type ({$type} - {$category})";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);

    $logStmt->execute();
    $logStmt->close();


    http_response_code(201);

    echo json_encode([
        "status" => "Success",
        "message" => "Account type created successfully",
        "data" => [
            "id" => $insertedId,
            "type" => $type,
            "category_id" => $category_id,
            "category" => $category,
            "sub_category" => $sub_category,
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