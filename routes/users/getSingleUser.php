<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];

    /**
     * Fetch logged-in user data
     */
    $stmt = $conn->prepare("
        SELECT 
            id, 
            fname, 
            lname, 
            email, 
            integrity, 
            created_by, 
            updated_by
        FROM user_table
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("User record not found", 404);
    }

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "User profile fetched successfully",
        "data" => $user
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
