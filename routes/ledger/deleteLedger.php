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
        throw new Exception("Unauthorized: Only Admins or Controllers are authorized to delete ledgers", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate that 'ledgerNumbers' is provided and is a non-empty array
    if (
        !isset($data['ledgerNumbers']) ||
        !is_array($data['ledgerNumbers']) ||
        count($data['ledgerNumbers']) === 0
    ) {
        throw new Exception("Please select at least one ledger to delete.", 400);
    }

    // Sanitize IDs (ledger_number is typically a string code, but we map them for binding)
    $ledgerNumbers = $data['ledgerNumbers'];

    // Start transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Delete from ledger_table
         * Note: If there are Foreign Key constraints linking main_journal_table to ledger_table,
         * this query will fail if the ledger is in use, unless ON DELETE CASCADE is set.
         */
        $placeholders = implode(',', array_fill(0, count($ledgerNumbers), '?'));
        
        // Using 's' for ledger_number as it is often a string code (e.g. "110000010")
        $idTypes = str_repeat('s', count($ledgerNumbers));

        $deleteQuery = "DELETE FROM ledger_table WHERE ledger_number IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error (prepare ledger delete): " . $conn->error, 500);
        }

        $deleteStmt->bind_param($idTypes, ...$ledgerNumbers);

        if (!$deleteStmt->execute()) {
            // Check for foreign key constraint errors
            if (strpos($conn->error, 'foreign key constraint') !== false) {
                 throw new Exception("Cannot delete ledger: It is currently referenced in journal entries.", 400);
            }
            throw new Exception("Failed to delete ledger records: " . $deleteStmt->error, 500);
        }

        // Check if any ledger was actually deleted
        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching ledgers found to delete.", 404);
        }

        $deleteStmt->close();


        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "$loggedInUserEmail deleted ledger(s) with Number(s): " . implode(', ', $ledgerNumbers);

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
            "message" => "Ledger(s) deleted successfully."
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