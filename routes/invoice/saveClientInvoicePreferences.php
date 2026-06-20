<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_catalogue_helpers.php';

if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? ''), ['POST', 'PUT'], true)) {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$clientId = (int) ($payload['client_id'] ?? 0);
$preferences = is_array($payload['preferences'] ?? null) ? $payload['preferences'] : $payload;
saveClientInvoicePreferences($conn, $clientId, $preferences, (string) $user['email']);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client invoice defaults saved successfully.',
    'data' => fetchClientInvoicePreferences($conn, $clientId),
]);
