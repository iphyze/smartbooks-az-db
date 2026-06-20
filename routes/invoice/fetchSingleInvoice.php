<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can view invoices.'
);

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException("Missing required parameter: 'invoice_number'.", 400);
}

$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
$activity = fetchInvoiceActivityPage($conn, $invoiceNumber, 1, 8);
$invoice['activity_history'] = $activity['data'];
$invoice['activity_meta'] = $activity['meta'];
$invoice['reminders'] = fetchInvoiceReminders($conn, $invoiceNumber, 12);
$invoice['payments'] = fetchInvoicePayments($conn, $invoiceNumber);
$invoice['payment_summary'] = invoicePaymentSummary(
    $conn,
    $invoiceNumber,
    (float) ($invoice['invoice_amount'] ?? 0)
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Invoice fetched successfully.',
    'data' => $invoice,
]);
