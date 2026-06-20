<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_catalogue_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);
$clientId = (int) ($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    throw new RuntimeException('Client ID is required.', 422);
}

$clientStmt = $conn->prepare('SELECT clients_id, clients_name FROM clients_table WHERE clients_id = ? LIMIT 1');
if (!$clientStmt) {
    throw new RuntimeException('Unable to validate the selected client.', 500);
}
$clientStmt->bind_param('i', $clientId);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
$clientStmt->close();
if (!$client) {
    throw new RuntimeException('The selected client could not be found.', 404);
}

$preferences = fetchClientInvoicePreferences($conn, $clientId);
jsonResponse([
    'status' => 'Success',
    'message' => $preferences ? 'Client invoice defaults fetched.' : 'No invoice defaults have been saved for this client.',
    'data' => [
        'exists' => $preferences !== null,
        'client' => $client,
        'preferences' => $preferences,
    ],
]);
