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

    if (
        !isset($data['accountTypeIds']) ||
        !is_array($data['accountTypeIds']) ||
        count($data['accountTypeIds']) === 0
    ) {
        throw new Exception("Please select at least one account type to delete.", 400);
    }

    $accountTypeIds = array_map('intval', $data['accountTypeIds']);

    // Start transaction
    $conn->begin_transaction();

    try {

        /**
         * Delete account types
         */
        $placeholders = implode(',', array_fill(0, count($accountTypeIds), '?'));

        $deleteQuery = "DELETE FROM account_type_table WHERE id IN ($placeholders)";

        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        $deleteStmt->bind_param(
            str_repeat('i', count($accountTypeIds)),
            ...$accountTypeIds
        );

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete account type(s): " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching account types found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "$loggedInUserEmail deleted account type record(s) with ID(s): " . implode(', ', $accountTypeIds);

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
            "message" => "Account type record(s) deleted successfully."
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