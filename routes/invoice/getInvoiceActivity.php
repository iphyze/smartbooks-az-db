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
requirePermission($conn, $user, 'invoice.view', 'You do not have permission to view invoice activity.');

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required.', 400);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(25, (int) ($_GET['limit'] ?? 8)));

// Confirm the invoice exists and is visible before returning its history.
requireInvoiceCostCenterAccess($conn, $user, $invoiceNumber, false);
fetchInvoiceBundle($conn, $invoiceNumber);
$activity = fetchInvoiceActivityPage($conn, $invoiceNumber, $page, $limit);

jsonResponse([
    'status' => 'Success',
    'message' => 'Invoice activity fetched successfully.',
    'data' => $activity['data'],
    'meta' => $activity['meta'],
]);
