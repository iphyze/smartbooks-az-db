<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

/**
 * DELETE /ledger/delete-single
 *
 * Body: { "ledger_number": "110000010" }
 *
 * Deletes one row from ledger_table by its ledger_number.
 * Used when the user wants to remove a single ledger record.
 */
try {

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // ── Authenticate ──────────────────────────────────────────────────────────
    $userData              = authenticateUser();
    $loggedInUserId        = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUserEmail     = $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception(
            "Unauthorized: Only Admins or Controllers are authorized to delete ledgers", 401
        );
    }

    // ── Decode body ───────────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['ledger_number']) || trim($data['ledger_number']) === '') {
        throw new Exception("A valid ledger_number is required.", 400);
    }

    $ledgerNumber = trim($data['ledger_number']);

    // ── Begin transaction ─────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {

        /**
         * 1. Delete from ledger_table
         * Note: If there are Foreign Key constraints linking main_journal_table to ledger_table,
         * this query will fail if the ledger is in use, unless ON DELETE CASCADE is set.
         */
        $deleteQuery = "DELETE FROM ledger_table WHERE ledger_number = ?";
        $deleteStmt  = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error (prepare ledger delete): " . $conn->error, 500);
        }

        // Using 's' for ledger_number as it is typically a string code (e.g. "110000010")
        $deleteStmt->bind_param("s", $ledgerNumber);

        if (!$deleteStmt->execute()) {
            // Check for foreign key constraint errors
            if (strpos($deleteStmt->error, 'foreign key constraint') !== false) {
                throw new Exception(
                    "Cannot delete ledger: It is currently referenced in journal entries.", 400
                );
            }
            throw new Exception("Failed to delete ledger record: " . $deleteStmt->error, 500);
        }

        // Check if the ledger was actually deleted
        if ($deleteStmt->affected_rows === 0) {
            throw new Exception(
                "Ledger with number '{$ledgerNumber}' does not exist or has already been deleted.", 404
            );
        }

        $deleteStmt->close();

        /**
         * 2. Log action
         */
        $logStmt   = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "{$loggedInUserEmail} deleted ledger with Number: {$ledgerNumber}";

        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $loggedInUserEmail);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);

        echo json_encode([
            "status"  => "Success",
            "message" => "Ledger deleted successfully."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}