<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/rbac_helpers.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    requirePermission($conn, $userData, 'project.create', 'You do not have permission to prepare a new project record.');

    /**
     * Fetch LAST project_code only
     */
    $stmt = $conn->prepare("
        SELECT project_code
        FROM project_table
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();

    $project_code = $data['project_code'] ?? 100;

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "project_code" => $project_code 
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => publicErrorMessage($e)
    ]);
}