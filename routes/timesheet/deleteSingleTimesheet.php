<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

/**
 * DELETE /invoice/delete-single-line
 *
 * Body: { "line_item_id": 42 }
 *
 * Deletes one row from main_invoice_table by its primary key (id).
 * Used by the Editinvoice form when the user removes a line item
 * that already exists in the database (confirmed via modal).
 */
try {

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // ── Authenticate ──────────────────────────────────────────────────────────
    $userData             = authenticateUser();
    $loggedInUserId       = $userData['id'];
    $loggedInUserEmail    = $userData['email'];
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception(
            "Unauthorized: Only Admins or Controllers can delete invoice line items", 401
        );
    }

    // ── Decode body ───────────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['line_item_id']) || (int) $data['line_item_id'] <= 0) {
        throw new Exception("A valid line_item_id is required.", 400);
    }

    $line_item_id = (int) $data['line_item_id'];

    // ── Begin transaction ─────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {

        // 1. Verify the line item exists and grab its invoive_number for the log
        $checkStmt = $conn->prepare(
            "SELECT id, invoive_number FROM main_invoice_table WHERE id = ? LIMIT 1"
        );
        $checkStmt->bind_param("i", $line_item_id);
        $checkStmt->execute();
        $row = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$row) {
            throw new Exception(
                "Line item #{$line_item_id} does not exist or has already been deleted.", 404
            );
        }

        $invoive_number = (int) $row['invoive_number'];

        // 2. Prevent deleting the LAST line item of a invoice
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM main_invoice_table WHERE invoive_number = ?"
        );
        $countStmt->bind_param("i", $invoive_number);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        if ((int) $countRow['cnt'] <= 1) {
            throw new Exception(
                "Cannot delete the last line item of a invoice. " .
                "Delete the entire invoice instead.", 400
            );
        }

        // 3. Delete the line item
        $deleteStmt = $conn->prepare(
            "DELETE FROM main_invoice_table WHERE id = ?"
        );
        $deleteStmt->bind_param("i", $line_item_id);

        if (!$deleteStmt->execute()) {
            throw new Exception(
                "Failed to delete line item: " . $deleteStmt->error, 500
            );
        }
        $deleteStmt->close();

        // 4. Log the action
        $logStmt   = $conn->prepare(
            "INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)"
        );
        $logAction = "{$loggedInUserEmail} deleted line item #{$line_item_id} " .
                     "from invoice Voucher #{$invoive_number}";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $loggedInUserEmail);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Line item deleted successfully.",
            "data"    => [
                "line_item_id" => $line_item_id,
                "invoive_number"   => $invoive_number,
            ],
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
        "message" => $e->getMessage(),
    ]);
}