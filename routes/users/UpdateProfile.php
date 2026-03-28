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


    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    /**
     * Fetch current user record (for password verification)
     */
    $userStmt = $conn->prepare("
        SELECT id, email, password 
        FROM user_table 
        WHERE id = ?
        LIMIT 1
    ");
    $userStmt->bind_param("i", $loggedInUserId);
    $userStmt->execute();
    $currentUser = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$currentUser) {
        throw new Exception("User record not found", 404);
    }

    /**
     * Build update fields dynamically
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

        // Prevent duplicate email
        $dupStmt = $conn->prepare("
            SELECT id FROM user_table 
            WHERE email = ? AND id != ?
            LIMIT 1
        ");
        $dupStmt->bind_param("si", $email, $loggedInUserId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("Email already in use", 400);
        }
        $dupStmt->close();

        $updateFields[] = "email = ?";
        $params[] = $email;
        $types .= "s";
    }

    /**
     * Password update (requires current password)
     */
    if (
        isset($data['password']) && trim($data['password']) !== '' ||
        isset($data['currentPassword']) && trim($data['currentPassword']) !== ''
    ) {

        if (
            empty($data['currentPassword']) ||
            empty($data['password'])
        ) {
            throw new Exception("Both current password and new password are required", 400);
        }

        if (!password_verify($data['currentPassword'], $currentUser['password'])) {
            throw new Exception("Current password is incorrect", 401);
        }

        if (!v::stringType()->length(6, null)->validate($data['password'])) {
            throw new Exception("New password must be at least 6 characters long", 400);
        }

        $updateFields[] = "password = ?";
        $params[] = password_hash(trim($data['password']), PASSWORD_DEFAULT);
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
    $params[] = $loggedInUserId;
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
    $action = "{$loggedInUserEmail} updated their profile";
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
    $fetchStmt->bind_param("i", $loggedInUserId);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Profile updated successfully",
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
