<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    // Required fields
    if (!isset($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $targetUserId = (int) $data['id'];
    if ($targetUserId <= 0) {
        throw new Exception("Invalid user ID provided.", 400);
    }

    /**
     * Authorization rule:
     * - Super_Admin can update anyone
     * - Others can only update themselves
     */
    if ($userIntegrity !== 'Admin' && $targetUserId !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only update your own account", 401);
    }

    /**
     * Check if user exists
     */
    $checkStmt = $conn->prepare("SELECT id, email FROM user_table WHERE id = ?");
    $checkStmt->bind_param("i", $targetUserId);
    $checkStmt->execute();
    $existingUser = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existingUser) {
        throw new Exception("User with ID {$targetUserId} not found.", 404);
    }

    /**
     * Build dynamic update fields
     */
    $updateFields = [];
    $params = [];
    $types = "";

    // First name
    if (isset($data['fname']) && trim($data['fname']) !== '') {
        $updateFields[] = "fname = ?";
        $params[] = trim($data['fname']);
        $types .= "s";
    }

    // Last name
    if (isset($data['lname']) && trim($data['lname']) !== '') {
        $updateFields[] = "lname = ?";
        $params[] = trim($data['lname']);
        $types .= "s";
    }

    // Email
    if (isset($data['email']) && trim($data['email']) !== '') {
        $email = strtolower(trim($data['email']));
        if (!v::email()->validate($email)) {
            throw new Exception("Invalid email format", 400);
        }

        // Prevent duplicate email (exclude self)
        $dupStmt = $conn->prepare("
            SELECT id FROM user_table 
            WHERE email = ? AND id != ?
            LIMIT 1
        ");
        $dupStmt->bind_param("si", $email, $targetUserId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("Email already in use by another user", 400);
        }
        $dupStmt->close();

        $updateFields[] = "email = ?";
        $params[] = $email;
        $types .= "s";
    }

    // Password (optional)
    if (isset($data['password']) && trim($data['password']) !== '') {
        if (!v::stringType()->length(6, null)->validate($data['password'])) {
            throw new Exception("Password must be at least 6 characters long", 400);
        }

        $updateFields[] = "password = ?";
        $params[] = password_hash(trim($data['password']), PASSWORD_DEFAULT);
        $types .= "s";
    }

    // Integrity (Super_Admin only)
    if (isset($data['integrity'])) {
        if ($userIntegrity !== 'Super_Admin') {
            throw new Exception("Only Super Admin can update user roles", 401);
        }

        $allowedRoles = ['Admin', 'Super_Admin'];
        if (!in_array($data['integrity'], $allowedRoles)) {
            throw new Exception("Invalid integrity role", 400);
        }

        $updateFields[] = "integrity = ?";
        $params[] = $data['integrity'];
        $types .= "s";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update", 400);
    }

    // Always update updated_by
    $updateFields[] = "updated_by = ?";
    $params[] = $loggedInUserEmail;
    $types .= "s";

    /**
     * Execute update
     */
    $sql = "
        UPDATE user_table 
        SET " . implode(", ", $updateFields) . "
        WHERE id = ?
    ";
    $params[] = $targetUserId;
    $types .= "i";

    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) {
        throw new Exception("Failed to prepare update query: " . $conn->error, 500);
    }

    $updateStmt->bind_param($types, ...$params);
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
    $action = "{$loggedInUserEmail} updated user account (ID {$targetUserId})";
    $logStmt->bind_param("iss", $loggedInUserId, $action, $loggedInUserEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT id, fname, lname, email, integrity, created_by, updated_by
        FROM user_table 
        WHERE id = ?
    ");
    $fetchStmt->bind_param("i", $targetUserId);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "User updated successfully",
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
