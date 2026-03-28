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
        throw new Exception("Unauthorized: Only Admins are authorized to delete", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate that 'rateIds' is provided and is a non-empty array
    if (
        !isset($data['rateIds']) ||
        !is_array($data['rateIds']) ||
        count($data['rateIds']) === 0
    ) {
        throw new Exception("Please select at least one currency record to delete.", 400);
    }

    $rateIds = array_map('intval', $data['rateIds']);

    // Start transaction
    $conn->begin_transaction();

    try {

        /**
         * Delete currency records from currency_table
         */
        $placeholders = implode(',', array_fill(0, count($rateIds), '?'));

        $deleteQuery = "DELETE FROM currency_table WHERE id IN ($placeholders)";

        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        $deleteStmt->bind_param(
            str_repeat('i', count($rateIds)),
            ...$rateIds
        );

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete currency record(s): " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching currency records found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "$loggedInUserEmail deleted currency record(s) with ID(s): " . implode(', ', $rateIds);

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
            "message" => "Currency record(s) deleted successfully."
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