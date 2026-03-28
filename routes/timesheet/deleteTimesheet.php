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
        throw new Exception("Unauthorized: Only Admins or Controllers are authorized to delete timesheets", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate that 'ids' is provided and is a non-empty array
    if (
        !isset($data['ids']) ||
        !is_array($data['ids']) ||
        count($data['ids']) === 0
    ) {
        throw new Exception("Please select at least one timesheet entry to delete.", 400);
    }

    // Sanitize IDs (Timesheet IDs are integers)
    $ids = $data['ids'];

    // Start transaction
    $conn->begin_transaction();

    try {

        /**
         * Delete timesheet entries from timesheet_table
         * Note: Unlike invoices, timesheets are a flat structure, so we don't need to delete child rows.
         */
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // Types for id (integer)
        $idTypes = str_repeat('i', count($ids));

        $deleteQuery = "DELETE FROM timesheet_table WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error (prepare delete): " . $conn->error, 500);
        }

        $deleteStmt->bind_param($idTypes, ...$ids);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete timesheet entries: " . $deleteStmt->error, 500);
        }

        // Check if any row was actually deleted
        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching timesheet entries found to delete.", 404);
        }

        $deleteStmt->close();


        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "$loggedInUserEmail deleted timesheet entry(s) with ID(s): " . implode(', ', $ids);

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
            "message" => "Timesheet entry(s) deleted successfully."
        ]);

    } catch (Exception $e) {
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