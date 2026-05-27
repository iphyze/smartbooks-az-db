<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(
        envString('DB_HOST', 'localhost'),
        envString('DB_USER'),
        envString('DB_PASSWORD'),
        envString('DB_NAME'),
        (int) envString('DB_PORT', '3306')
    );

    $conn->set_charset('utf8mb4');
} catch (Throwable $exception) {
    error_log('[Smartbooks DB] Connection failed: ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Database connection unavailable.'
    ], 500);
}
