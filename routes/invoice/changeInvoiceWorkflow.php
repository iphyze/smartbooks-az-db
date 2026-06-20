<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    throw new RuntimeException('Invalid request body.', 400);
}

$invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
$newStatus = ucfirst(strtolower(trim((string) ($data['workflow_status'] ?? ''))));
$reason = trim((string) ($data['reason'] ?? ''));
if ($invoiceNumber === '' || !in_array($newStatus, ['Issued', 'Cancelled', 'Void'], true)) {
    throw new RuntimeException('A valid invoice and workflow status are required.', 400);
}

$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
$oldStatus = (string) ($invoice['workflow_status'] ?? 'Issued');
if ($oldStatus === $newStatus) {
    jsonResponse([
        'status' => 'Success',
        'message' => 'The invoice already has this workflow status.',
        'data' => ['workflow_status' => $newStatus],
    ]);
}

$allowedTransitions = [
    'Issued' => ['Cancelled', 'Void'],
    'Cancelled' => ['Issued'],
    'Void' => [],
];
if (!in_array($newStatus, $allowedTransitions[$oldStatus] ?? [], true)) {
    throw new RuntimeException("Invoice status cannot move from {$oldStatus} to {$newStatus}.", 409);
}
if (in_array($newStatus, ['Cancelled', 'Void'], true) && $reason === '') {
    throw new RuntimeException('Please provide a reason for this invoice status change.', 400);
}
if ($newStatus === 'Void' && userRole($user) !== SMARTBOOKS_ROLE_ADMIN) {
    throw new RuntimeException('Only an Admin can void an invoice.', 403);
}

$userEmail = (string) $user['email'];
$conn->begin_transaction();
try {
    // The migration deliberately keeps only issued_at in invoice_table. Cancel/void dates
    // remain fully available in invoice_status_history without introducing redundant columns.
    if ($newStatus === 'Issued') {
        $stmt = $conn->prepare(
            'UPDATE invoice_table
             SET workflow_status = ?, issued_at = COALESCE(issued_at, CURRENT_TIMESTAMP), updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE invoice_number = ?'
        );
        $stmt->bind_param('sss', $newStatus, $userEmail, $invoiceNumber);
    } else {
        $stmt = $conn->prepare(
            'UPDATE invoice_table
             SET workflow_status = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE invoice_number = ?'
        );
        $stmt->bind_param('sss', $newStatus, $userEmail, $invoiceNumber);
    }
    $stmt->execute();
    $stmt->close();

    recordInvoiceStatusHistory($conn, $invoiceNumber, 'workflow', $oldStatus, $newStatus, $reason, $user);

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $userId = (int) $user['id'];
    $action = "{$userEmail} changed Invoice #{$invoiceNumber} workflow status from {$oldStatus} to {$newStatus}";
    $logStmt->bind_param('iss', $userId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    notifyAccountingUsers(
        $conn,
        'invoice_workflow_changed',
        'invoice',
        "Invoice #{$invoiceNumber} marked {$newStatus}",
        "{$userEmail} changed the invoice workflow from {$oldStatus} to {$newStatus}.",
        in_array($newStatus, ['Cancelled', 'Void'], true) ? 'warning' : 'info',
        'invoice',
        $invoiceNumber,
        "/invoice/view/{$invoiceNumber}",
        ['old_status' => $oldStatus, 'new_status' => $newStatus, 'reason' => $reason],
        $userId
    );

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => "Invoice marked as {$newStatus}.",
    'data' => ['workflow_status' => $newStatus],
]);
