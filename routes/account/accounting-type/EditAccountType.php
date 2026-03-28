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
        throw new Exception("Unauthorized: Only Admins can update account types", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    /**
     * Required fields
     */
    $requiredFields = ['id', 'type', 'category_id', 'category', 'sub_category'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $id = (int) $data['id'];

    if ($id <= 0) {
        throw new Exception("Invalid account type ID provided.", 400);
    }

    /**
     * Clean inputs
     */
    $type = trim($data['type']);
    $category_id = (int) $data['category_id'];
    $category = trim($data['category']);
    $sub_category = trim($data['sub_category']);

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("
        SELECT id 
        FROM account_type_table 
        WHERE id = ?
    ");

    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Account type with ID {$id} not found.", 404);
    }

    $checkStmt->close();

    /**
     * Duplicate check (exclude current record)
     * Prevent same type under same category/subcategory
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM account_type_table
        WHERE type = ?
        AND category_id = ?
        AND sub_category = ?
        AND id != ?
        LIMIT 1
    ");

    $dupStmt->bind_param("sisi", $type, $category_id, $sub_category, $id);
    $dupStmt->execute();

    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception(
            "Duplicate account type detected: This type already exists in the selected category/sub-category.",
            400
        );
    }

    $dupStmt->close();

    /**
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE account_type_table
        SET type = ?, category_id = ?, category = ?, sub_category = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $updateStmt->bind_param(
        "sisssi",
        $type,
        $category_id,
        $category,
        $sub_category,
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

    $action = "$userEmail updated account type ({$type} - {$category}) [ID {$id}]";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT 
            id,
            type,
            category_id,
            category,
            sub_category,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM account_type_table
        WHERE id = ?"
    );

    $fetchStmt->bind_param("i", $id);
    $fetchStmt->execute();

    $updatedData = $fetchStmt->get_result()->fetch_assoc();

    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Account type updated successfully",
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