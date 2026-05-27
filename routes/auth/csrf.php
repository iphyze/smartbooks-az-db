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

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'CSRF protection initialised.',
    'data' => [
        'csrfToken' => $csrfToken
    ]
]);
