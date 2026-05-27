<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Method not allowed.'
    ], 405);
}

$user = authenticateUser();
unset($user['jti']);

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'data' => $user
]);
