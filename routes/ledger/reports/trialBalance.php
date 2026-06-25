<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/trialBalanceHelpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'], true)) {
        throw new Exception('Unauthorized: Only Admins or Controllers can access this resource', 401);
    }

    foreach (['datefrom', 'dateto', 'currency'] as $param) {
        if (!isset($_GET[$param]) || trim((string) $_GET[$param]) === '') {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim((string) $_GET['datefrom']);
    $dateto = trim((string) $_GET['dateto']);
    $currency = strtoupper(trim((string) $_GET['currency']));
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : null;
    $zerobal = isset($_GET['zerobal']) ? trim((string) $_GET['zerobal']) : 'Yes';

    $allowedCurrencies = [
        'NGN' => 'ngn_rate',
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate',
    ];

    if (!isset($allowedCurrencies[$currency])) {
        throw new Exception('Invalid currency specified.', 400);
    }

    $fromDate = DateTime::createFromFormat('Y-m-d', $datefrom);
    $toDate = DateTime::createFromFormat('Y-m-d', $dateto);
    if (!$fromDate || $fromDate->format('Y-m-d') !== $datefrom || !$toDate || $toDate->format('Y-m-d') !== $dateto) {
        throw new Exception('Dates must use the YYYY-MM-DD format.', 400);
    }
    if ($datefrom > $dateto) {
        throw new Exception('Date From cannot be later than Date To.', 400);
    }

    $report = fetchTrialBalanceReport(
        $conn,
        $datefrom,
        $dateto,
        $allowedCurrencies[$currency],
        $zerobal,
        $search
    );

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Trial Balance report fetched successfully',
        'data' => $report['data'],
        'totals' => $report['totals'],
        'meta' => [
            'total_records' => $report['total_records'],
            'currency' => $currency,
            'datefrom' => $datefrom,
            'dateto' => $dateto,
            'zerobal' => $zerobal,
            'presentation' => 'opening_movement_closing',
        ],
    ]);
} catch (Exception $e) {
    error_log('Trial Balance Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($e),
    ]);
}
