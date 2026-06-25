<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/activity_log_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Method not allowed.'
    ], 405);
}

$user = authenticateUser();
$actorName = trim(((string) ($user['fname'] ?? '')) . ' ' . ((string) ($user['lname'] ?? '')));
logActivity(
    $conn,
    $user,
    ($actorName !== '' ? $actorName : ($user['email'] ?? 'User')) . ' logged out',
    'Authentication',
    'logout',
    [
        'created_by' => $actorName !== '' ? $actorName : ($user['email'] ?? 'User'),
        'description' => 'User ended their Smartbooks session.',
        'entity_type' => 'user',
        'entity_id' => (string) ((int) ($user['id'] ?? 0)),
    ]
);

revokeAuthSession($conn, (int) $user['id'], (string) $user['jti']);
clearAuthCookie();
clearCsrfCookie();

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Logged out successfully.'
]);
