<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUserEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins are authorized to update", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['invoiceIds']) || !is_array($data['invoiceIds']) || count($data['invoiceIds']) === 0) {
        throw new Exception("Please select an invoice first.", 400);
    }

    if (!isset($data['status']) || trim($data['status']) === '') {
        throw new Exception("Payment status is required.", 400);
    }

    $invoiceIds = array_map('intval', $data['invoiceIds']);
    $paymentStatus = trim($data['status']);


    if(count($invoiceIds) > 100) {
        throw new Exception("Too many invoice IDs provided. Maximum allowed is 100.", 400);
    }

    $validStatuses = ['Pending', 'Paid'];

    // Validate if an invalid status is provided
    if (!in_array($paymentStatus, $validStatuses)) {
        throw new Exception("Invalid payment status provided.", 400);
    }


    // Step 1: Verify that all IDs exist
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $typeString = str_repeat('i', count($invoiceIds));
    $checkStmt = $conn->prepare("SELECT invoice_number FROM invoice_table WHERE invoice_number IN ($placeholders)");
    $checkStmt->bind_param($typeString, ...$invoiceIds);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    $existingIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingIds[] = (int) $row['invoice_number'];
    }

    $missingIds = array_diff($invoiceIds, $existingIds);

    if (count($missingIds) > 0) {
        throw new Exception("The following payment IDs do not exist: " . implode(', ', $missingIds), 404);
    }

    $checkStmt->close();

    // Step 2: Begin transaction
    $conn->begin_transaction();

    try {
        $updateQuery = "UPDATE invoice_table SET status = ? WHERE invoice_number IN ($placeholders)";
        $stmt = $conn->prepare($updateQuery);

        if (!$stmt) {
            throw new Exception("Failed to prepare update statement: " . $conn->error, 500);
        }

        $params = array_merge([$paymentStatus], $invoiceIds);
        $types = 's' . $typeString;
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update payment status: " . $stmt->error, 500);
        }

        $stmt->close();

        // Step 3: Log the update
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$loggedInUserEmail updated status to '$paymentStatus' for invoice(s) with ID(s): " . implode(', ', $invoiceIds) . " in invoice update request.";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userData['email']);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log the update action: " . $logStmt->error, 500);
        }

        $logStmt->close();
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Invoice status updated successfully."
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
?>
