<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
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

$invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
$mode = strtolower(trim((string) ($data['mode'] ?? 'send_now')));
$kind = trim((string) ($data['reminder_kind'] ?? 'Friendly'));
$recipient = trim((string) ($data['recipient_email'] ?? ''));
$subject = trim((string) ($data['subject'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));
$scheduledForInput = trim((string) ($data['scheduled_for'] ?? ''));

if ($invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required.', 400);
}
if (!in_array($mode, ['send_now', 'schedule'], true)) {
    throw new RuntimeException('Reminder mode must be Send now or Schedule.', 400);
}
if (!in_array($kind, ['Friendly', 'Due Today', 'Overdue', 'Final'], true)) {
    throw new RuntimeException('Invalid reminder type.', 400);
}

$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
if (!invoiceReminderCanBeSent($invoice)) {
    throw new RuntimeException('Payment reminders can only be created for active invoices with an outstanding balance.', 409);
}

if ($recipient === '') {
    $recipient = trim((string) ($invoice['clients_data']['clients_email'] ?? ''));
}
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('A valid recipient email address is required.', 400);
}

$template = buildInvoiceReminderEmailTemplate($invoice, $message, $kind);
if ($subject === '') {
    $subject = $template['subject'];
}
if ((function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject)) > 255) {
    throw new RuntimeException('The reminder subject must not exceed 255 characters.', 400);
}
if ((function_exists('mb_strlen') ? mb_strlen($message) : strlen($message)) > 3000) {
    throw new RuntimeException('The reminder message must not exceed 3,000 characters.', 400);
}

$scheduledFor = null;
$deliveryStatus = $mode === 'schedule' ? 'Scheduled' : 'Processing';
$reminderMode = $mode === 'schedule' ? 'Scheduled' : 'Manual';
if ($mode === 'schedule') {
    if ($scheduledForInput === '') {
        throw new RuntimeException('Select when the reminder should be sent.', 400);
    }
    try {
        $scheduledDate = new DateTimeImmutable($scheduledForInput);
    } catch (Throwable) {
        throw new RuntimeException('The scheduled reminder date is invalid.', 400);
    }
    $now = new DateTimeImmutable('now');
    if ($scheduledDate <= $now->modify('+4 minutes')) {
        throw new RuntimeException('Schedule the reminder at least five minutes from now.', 400);
    }
    if ($scheduledDate > $now->modify('+1 year')) {
        throw new RuntimeException('A reminder cannot be scheduled more than one year ahead.', 400);
    }
    $scheduledFor = $scheduledDate->format('Y-m-d H:i:s');

    $duplicateStmt = $conn->prepare(
        "SELECT id FROM invoice_reminders
         WHERE invoice_number = ?
           AND recipient_email = ?
           AND scheduled_for = ?
           AND delivery_status = 'Scheduled'
         LIMIT 1"
    );
    if ($duplicateStmt) {
        $duplicateStmt->bind_param('sss', $invoiceNumber, $recipient, $scheduledFor);
        $duplicateStmt->execute();
        $duplicateExists = (bool) $duplicateStmt->get_result()->fetch_assoc();
        $duplicateStmt->close();
        if ($duplicateExists) {
            throw new RuntimeException('A reminder is already scheduled for this recipient at that time.', 409);
        }
    }
}

$userId = (int) $user['id'];
$userEmail = (string) $user['email'];
$stmt = $conn->prepare(
    'INSERT INTO invoice_reminders
        (invoice_number, reminder_kind, reminder_mode, recipient_email, subject, message,
         scheduled_for, delivery_status, created_by_user_id, created_by_email)
     VALUES (?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?)'
);
if (!$stmt) {
    throw new RuntimeException('Unable to create the payment reminder.', 500);
}
$stmt->bind_param(
    'ssssssssis',
    $invoiceNumber,
    $kind,
    $reminderMode,
    $recipient,
    $subject,
    $message,
    $scheduledFor,
    $deliveryStatus,
    $userId,
    $userEmail
);
$stmt->execute();
$reminderId = (int) $conn->insert_id;
$stmt->close();

$logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
if ($logStmt) {
    $action = $mode === 'schedule'
        ? "{$userEmail} scheduled a payment reminder for Invoice #{$invoiceNumber}"
        : "{$userEmail} initiated a payment reminder for Invoice #{$invoiceNumber}";
    $logStmt->bind_param('iss', $userId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();
}

if ($mode === 'schedule') {
    jsonResponse([
        'status' => 'Success',
        'message' => 'Payment reminder scheduled successfully.',
        'data' => [
            'id' => $reminderId,
            'delivery_status' => 'Scheduled',
            'scheduled_for' => $scheduledFor,
        ],
    ], 201);
}

$reminder = fetchInvoiceReminderById($conn, $reminderId);
if (!$reminder) {
    throw new RuntimeException('The reminder was created but could not be loaded for delivery.', 500);
}
$result = deliverInvoiceReminder($conn, $reminder, $invoice, $userId, $userEmail);

if (!$result['success']) {
    jsonResponse([
        'status' => 'Failed',
        'code' => 'REMINDER_DELIVERY_FAILED',
        'message' => $result['message'],
        'data' => [
            'id' => $reminderId,
            'delivery_status' => $result['status'],
        ],
    ], 424);
}

jsonResponse([
    'status' => 'Success',
    'message' => $result['message'],
    'data' => [
        'id' => $reminderId,
        'delivery_status' => $result['status'],
    ],
], 201);
