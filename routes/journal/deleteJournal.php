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
        throw new Exception("Unauthorized: Only Admins or Controllers are authorized to delete journals", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate that 'journalIds' is provided and is a non-empty array
    if (
        !isset($data['journalIds']) ||
        !is_array($data['journalIds']) ||
        count($data['journalIds']) === 0
    ) {
        throw new Exception("Please select at least one journal to delete.", 400);
    }

    // Sanitize IDs (Ensuring they are integers)
    $journalIds = array_map('intval', $data['journalIds']);

    // Start transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Delete line items from main_journal_table
         */
        $placeholders = implode(',', array_fill(0, count($journalIds), '?'));
        
        // journal_id is typically an integer, so we use 'i'
        $idTypes = str_repeat('i', count($journalIds));

        $deleteItemsQuery = "DELETE FROM main_journal_table WHERE journal_id IN ($placeholders)";
        $deleteItemsStmt = $conn->prepare($deleteItemsQuery);

        if (!$deleteItemsStmt) {
            throw new Exception("Database error (prepare items delete): " . $conn->error, 500);
        }

        $deleteItemsStmt->bind_param($idTypes, ...$journalIds);

        if (!$deleteItemsStmt->execute()) {
            throw new Exception("Failed to delete journal line items: " . $deleteItemsStmt->error, 500);
        }

        $deleteItemsStmt->close();


        /**
         * 2. Delete headers from journal_table
         */
        $deleteJrnlQuery = "DELETE FROM journal_table WHERE journal_id IN ($placeholders)";
        $deleteJrnlStmt = $conn->prepare($deleteJrnlQuery);

        if (!$deleteJrnlStmt) {
            throw new Exception("Database error (prepare journal delete): " . $conn->error, 500);
        }

        $deleteJrnlStmt->bind_param($idTypes, ...$journalIds);

        if (!$deleteJrnlStmt->execute()) {
            throw new Exception("Failed to delete journal records: " . $deleteJrnlStmt->error, 500);
        }

        // Check if any journal was actually deleted
        if ($deleteJrnlStmt->affected_rows === 0) {
            throw new Exception("No matching journals found to delete.", 404);
        }

        $deleteJrnlStmt->close();


        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "$loggedInUserEmail deleted journal(s) with ID(s): " . implode(', ', $journalIds);

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
            "message" => "Journal(s) deleted successfully."
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