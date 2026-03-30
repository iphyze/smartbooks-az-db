<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUserEmail = $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers are authorized to delete", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate that 'projectIds' is provided and is a non-empty array
    if (
        !isset($data['projectIds']) ||
        !is_array($data['projectIds']) ||
        count($data['projectIds']) === 0
    ) {
        throw new Exception("Please select at least one project to delete.", 400);
    }

    // Sanitize IDs to integers
    $projectIds = array_map('intval', $data['projectIds']);

    // Start transaction
    $conn->begin_transaction();

    try {

        /**
         * Delete projects from project_table
         */
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        $deleteQuery = "DELETE FROM project_table WHERE id IN ($placeholders)";

        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        // Dynamically bind the integer parameters
        $deleteStmt->bind_param(
            str_repeat('i', count($projectIds)),
            ...$projectIds
        );

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete project(s): " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching projects found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "$loggedInUserEmail deleted project record(s) with ID(s): " . implode(', ', $projectIds);

        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $loggedInUserEmail);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);

        echo json_encode([
            "status" => "Success",
            "message" => "Project record(s) deleted successfully."
        ]);

    } catch (Exception $e) {
        // Rollback everything if any step fails
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