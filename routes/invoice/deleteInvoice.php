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
        throw new Exception("Unauthorized: Only Admins are authorized to delete invoices", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate that 'invoiceIds' is provided and is a non-empty array
    if (
        !isset($data['invoiceIds']) ||
        !is_array($data['invoiceIds']) ||
        count($data['invoiceIds']) === 0
    ) {
        throw new Exception("Please select at least one invoice to delete.", 400);
    }

    // Sanitize IDs (Assuming invoice_number can be treated as string or integer)
    $invoiceIds = $data['invoiceIds'];

    // Start transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Delete line items from main_invoice_table
         */
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        
        // Determine types for invoice_number (using 's' is safer for mixed or string IDs)
        $idTypes = str_repeat('s', count($invoiceIds));

        $deleteItemsQuery = "DELETE FROM main_invoice_table WHERE invoice_number IN ($placeholders)";
        $deleteItemsStmt = $conn->prepare($deleteItemsQuery);

        if (!$deleteItemsStmt) {
            throw new Exception("Database error (prepare items delete): " . $conn->error, 500);
        }

        $deleteItemsStmt->bind_param($idTypes, ...$invoiceIds);

        if (!$deleteItemsStmt->execute()) {
            throw new Exception("Failed to delete invoice line items: " . $deleteItemsStmt->error, 500);
        }

        $deleteItemsStmt->close();


        /**
         * 2. Delete invoice headers from invoice_table
         */
        $deleteInvQuery = "DELETE FROM invoice_table WHERE invoice_number IN ($placeholders)";
        $deleteInvStmt = $conn->prepare($deleteInvQuery);

        if (!$deleteInvStmt) {
            throw new Exception("Database error (prepare invoice delete): " . $conn->error, 500);
        }

        $deleteInvStmt->bind_param($idTypes, ...$invoiceIds);

        if (!$deleteInvStmt->execute()) {
            throw new Exception("Failed to delete invoice records: " . $deleteInvStmt->error, 500);
        }

        // Check if any invoice was actually deleted
        if ($deleteInvStmt->affected_rows === 0) {
            throw new Exception("No matching invoices found to delete.", 404);
        }

        $deleteInvStmt->close();


        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "$loggedInUserEmail deleted invoice(s) with ID(s): " . implode(', ', $invoiceIds);

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
            "message" => "Invoice(s) deleted successfully."
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