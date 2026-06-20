<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_catalogue_helpers.php';

if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? ''), ['PUT', 'PATCH'], true)) {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$id = (int) ($payload['id'] ?? 0);
if ($id <= 0) {
    throw new RuntimeException('Service ID is required.', 422);
}
$current = fetchInvoiceServiceById($conn, $id, false);
if (!$current) {
    throw new RuntimeException('The reusable service could not be found.', 404);
}

$name = trim((string) ($payload['service_name'] ?? $current['service_name']));
$description = trim((string) ($payload['description'] ?? $current['description']));
$currency = strtoupper(trim((string) ($payload['currency'] ?? $current['currency'])));
$amount = round((float) ($payload['default_amount'] ?? $current['default_amount']), 2);
$discount = normalizeInvoicePercentage($payload['discount_percent'] ?? $current['discount_percent']);
$vat = normalizeInvoicePercentage($payload['vat_percent'] ?? $current['vat_percent']);
$wht = normalizeInvoicePercentage($payload['wht_percent'] ?? $current['wht_percent']);
$isActive = array_key_exists('is_active', $payload) ? ((bool) $payload['is_active'] ? 1 : 0) : ((bool) $current['is_active'] ? 1 : 0);

if ($name === '' || $description === '') {
    throw new RuntimeException('Service name and description are required.', 422);
}
if (!in_array($currency, ['NGN', 'USD', 'GBP', 'EUR'], true)) {
    throw new RuntimeException('Select a valid service currency.', 422);
}
if ($amount < 0) {
    throw new RuntimeException('Default amount cannot be negative.', 422);
}

$duplicateStmt = $conn->prepare(
    'SELECT id FROM invoice_service_catalogue WHERE service_name = ? AND currency = ? AND id <> ? LIMIT 1'
);
if (!$duplicateStmt) {
    throw new RuntimeException('Unable to validate the reusable service.', 500);
}
$duplicateStmt->bind_param('ssi', $name, $currency, $id);
$duplicateStmt->execute();
$duplicate = $duplicateStmt->get_result()->fetch_assoc();
$duplicateStmt->close();
if ($duplicate) {
    throw new RuntimeException("Another {$currency} service already uses this name.", 409);
}

$userEmail = (string) $user['email'];
$stmt = $conn->prepare(
    'UPDATE invoice_service_catalogue
     SET service_name = ?, description = ?, currency = ?, default_amount = ?,
         discount_percent = ?, vat_percent = ?, wht_percent = ?, is_active = ?,
         updated_by = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?'
);
if (!$stmt) {
    throw new RuntimeException('Unable to update the reusable service.', 500);
}
$stmt->bind_param('sssddddisi', $name, $description, $currency, $amount, $discount, $vat, $wht, $isActive, $userEmail, $id);
$stmt->execute();
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'message' => $isActive ? 'Reusable invoice service updated.' : 'Reusable invoice service archived.',
    'data' => fetchInvoiceServiceById($conn, $id, false),
]);
