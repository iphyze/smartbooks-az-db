<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_reminder_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    throw new RuntimeException('Invalid request body.', 400);
}

$reminderId = (int) ($data['reminder_id'] ?? 0);
$reason = trim((string) ($data['reason'] ?? 'Cancelled by user.'));
if ($reminderId <= 0) {
    throw new RuntimeException('A valid reminder is required.', 400);
}
if (strlen($reason) > 500) {
    throw new RuntimeException('The cancellation reason must not exceed 500 characters.', 400);
}

$reminder = fetchInvoiceReminderById($conn, $reminderId);
if (!$reminder) {
    throw new RuntimeException('The payment reminder was not found.', 404);
}
if ((string) $reminder['delivery_status'] !== 'Scheduled') {
    throw new RuntimeException('Only scheduled reminders can be cancelled.', 409);
}

$userId = (int) $user['id'];
$userEmail = (string) $user['email'];
$status = 'Cancelled';
$stmt = $conn->prepare(
    "UPDATE invoice_reminders
     SET delivery_status = ?,
         cancelled_at = CURRENT_TIMESTAMP,
         cancelled_by_user_id = ?,
         cancelled_by_email = ?,
         cancel_reason = ?,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND delivery_status = 'Scheduled'"
);
if (!$stmt) {
    throw new RuntimeException('Unable to cancel the payment reminder.', 500);
}
$stmt->bind_param('sissi', $status, $userId, $userEmail, $reason, $reminderId);
$stmt->execute();
$changed = $stmt->affected_rows;
$stmt->close();
if ($changed !== 1) {
    throw new RuntimeException('The reminder changed before it could be cancelled. Refresh and try again.', 409);
}

$logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
if ($logStmt) {
    $action = "{$userEmail} cancelled payment reminder #{$reminderId} for Invoice #{$reminder['invoice_number']}";
    $logStmt->bind_param('iss', $userId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Scheduled reminder cancelled successfully.',
    'data' => ['id' => $reminderId, 'delivery_status' => 'Cancelled'],
]);
