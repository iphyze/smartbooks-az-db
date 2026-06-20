<?php
declare(strict_types=1);

require_once 'includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Method not allowed.'
    ], 405);
}

$csrfToken = issueCsrfCookie();
$authToken = (string) ($_COOKIE[authCookieName()] ?? '');

// A signed-out first visit should not open a database connection merely to
// establish the browser's CSRF companion token.
if ($authToken === '') {
    header('Cache-Control: no-store');
    jsonResponse([
        'status' => 'Success',
        'data' => [
            'authenticated' => false,
            'user' => null,
            'csrfToken' => $csrfToken,
        ],
    ]);
}

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

$user = authenticateUser();
unset($user['jti']);

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'data' => [
        'authenticated' => true,
        'user' => $user,
        'csrfToken' => $csrfToken,
    ],
]);
