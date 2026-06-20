<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_catalogue_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$name = trim((string) ($payload['service_name'] ?? ''));
$description = trim((string) ($payload['description'] ?? ''));
$currency = strtoupper(trim((string) ($payload['currency'] ?? '')));
$amount = round((float) ($payload['default_amount'] ?? 0), 2);
$discount = normalizeInvoicePercentage($payload['discount_percent'] ?? 0);
$vat = normalizeInvoicePercentage($payload['vat_percent'] ?? 0);
$wht = normalizeInvoicePercentage($payload['wht_percent'] ?? 0);

if ($name === '' || mb_strlen($name) < 2) {
    throw new RuntimeException('Enter a service name.', 422);
}
if (mb_strlen($name) > 180) {
    throw new RuntimeException('Service name cannot exceed 180 characters.', 422);
}
if ($description === '') {
    throw new RuntimeException('Enter the service description that should appear on invoices.', 422);
}
if (!in_array($currency, ['NGN', 'USD', 'GBP', 'EUR'], true)) {
    throw new RuntimeException('Select a valid service currency.', 422);
}
if ($amount < 0) {
    throw new RuntimeException('Default amount cannot be negative.', 422);
}

$duplicateStmt = $conn->prepare(
    'SELECT id FROM invoice_service_catalogue WHERE service_name = ? AND currency = ? LIMIT 1'
);
if (!$duplicateStmt) {
    throw new RuntimeException('Unable to validate the service.', 500);
}
$duplicateStmt->bind_param('ss', $name, $currency);
$duplicateStmt->execute();
$duplicate = $duplicateStmt->get_result()->fetch_assoc();
$duplicateStmt->close();
if ($duplicate) {
    throw new RuntimeException("A {$currency} service with this name already exists.", 409);
}

$code = generateInvoiceServiceCode($conn);
$userEmail = (string) $user['email'];
$stmt = $conn->prepare(
    'INSERT INTO invoice_service_catalogue
        (service_code, service_name, description, currency, default_amount,
         discount_percent, vat_percent, wht_percent, is_active, created_by, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
);
if (!$stmt) {
    throw new RuntimeException('Unable to create the service catalogue item.', 500);
}
$stmt->bind_param(
    'ssssddddss',
    $code,
    $name,
    $description,
    $currency,
    $amount,
    $discount,
    $vat,
    $wht,
    $userEmail,
    $userEmail
);
$stmt->execute();
$serviceId = (int) $stmt->insert_id;
$stmt->close();

$service = fetchInvoiceServiceById($conn, $serviceId, false);

$logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
if ($logStmt) {
    $userId = (int) $user['id'];
    $action = "{$userEmail} created invoice service {$code}";
    $logStmt->bind_param('iss', $userId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Reusable invoice service created successfully.',
    'data' => $service,
], 201);
