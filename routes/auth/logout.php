<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Method not allowed.'
    ], 405);
}

$user = authenticateUser();
revokeAuthSession($conn, (int) $user['id'], (string) $user['jti']);
clearAuthCookie();
clearCsrfCookie();

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Logged out successfully.'
]);
