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
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required.', 400);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(25, (int) ($_GET['limit'] ?? 8)));

// Confirm the invoice exists before returning its history.
fetchInvoiceBundle($conn, $invoiceNumber);
$activity = fetchInvoiceActivityPage($conn, $invoiceNumber, $page, $limit);

jsonResponse([
    'status' => 'Success',
    'message' => 'Invoice activity fetched successfully.',
    'data' => $activity['data'],
    'meta' => $activity['meta'],
]);
