<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    // Only Super_Admin allowed
    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins can access this resource", 401);
    }

    // Decode JSON body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['fname', 'lname', 'email', 'password', 'integrity'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean inputs
    $fname     = trim($data['fname']);
    $lname     = trim($data['lname']);
    $email     = strtolower(trim($data['email']));
    $password  = trim($data['password']);
    $integrity = trim($data['integrity']);

    // Validation
    if (!v::email()->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }

    if (!v::stringType()->length(6, null)->validate($password)) {
        throw new Exception("Password must be at least 6 characters long", 400);
    }

    $allowedRoles = ['Admin', 'Super_Admin'];
    if (!in_array($integrity, $allowedRoles)) {
        throw new Exception("Invalid integrity role", 400);
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    /**
     * Check for duplicate email
     */
    $dupStmt = $conn->prepare("
        SELECT id 
        FROM user_table 
        WHERE email = ?
        LIMIT 1
    ");
    $dupStmt->bind_param("s", $email);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("User with this email already exists", 400);
    }
    $dupStmt->close();

    /**
     * Insert user
     */
    $insertStmt = $conn->prepare("
        INSERT INTO user_table (fname, lname, email, password, integrity, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->bind_param(
        "sssssss",
        $fname,
        $lname,
        $email,
        $hashedPassword,
        $integrity,
        $loggedInUserEmail,
        $loggedInUserEmail
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
    $action = "{$loggedInUserEmail} created a new user ({$email}) with role {$integrity}";
    $logStmt->bind_param("iss", $loggedInUserId, $action, $loggedInUserEmail);
    $logStmt->execute();
    $logStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "User created successfully",
        "data" => [
            "id" => $insertedId,
            "fname" => $fname,
            "lname" => $lname,
            "email" => $email,
            "integrity" => $integrity,
            "created_by" => $loggedInUserEmail,
            "updated_by" => $loggedInUserEmail
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
