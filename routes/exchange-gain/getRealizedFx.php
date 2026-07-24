<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/realized_fx_reporting_helpers.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $integrity = (string) ($userData['integrity'] ?? '');
    if (!in_array($integrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can view realized FX reports.', 403);
    }

    $dateFrom = smartbooksFxValidateDate((string) ($_GET['datefrom'] ?? ''), 'start date');
    $dateTo = smartbooksFxValidateDate((string) ($_GET['dateto'] ?? ''), 'end date');
    if ($dateFrom > $dateTo) {
        throw new RuntimeException('The start date cannot be later than the end date.', 422);
    }

    $currency = smartbooksFxNormaliseCurrency((string) ($_GET['currency'] ?? ''));
    $report = smartbooksRealizedFxBuildReport($conn, $dateFrom, $dateTo, $currency);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Realized FX report generated successfully.',
        'data' => $report['data'],
        'summary' => $report['summary'],
        'pending_manual_journals' => $report['pending_manual_journals'],
        'pending_manual_summary' => $report['pending_manual_summary'],
        'warnings' => $report['warnings'],
        'meta' => $report['meta'],
    ], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    error_log('Realized FX Report Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($error),
    ]);
}
