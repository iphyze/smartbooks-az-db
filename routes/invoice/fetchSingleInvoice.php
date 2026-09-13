<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/cost_center_access_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireAnyPermission($conn, $user, ['invoice.view', 'invoice.edit'], 'You do not have permission to access this invoice.');

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException("Missing required parameter: 'invoice_number'.", 400);
}

requireInvoiceCostCenterAccess($conn, $user, $invoiceNumber, false);
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
