<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/cost_center_access_helpers.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    requirePermission($conn, $userData, 'fx.post', 'You do not have permission to post FX revaluations.');

    requireAllCostCenterAccessForGlobalAccounting($userData, 'FX revaluation and realized FX controls currently require All Cost Centres access.');

    throw new RuntimeException(
        'The zero-entry FX method has been disabled because it does not create a balanced double-entry journal. Use the standard preview and post action instead.',
        410
    );
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($error),
        'replacement_endpoint' => '/exchange/post-revaluation',
    ]);
}
