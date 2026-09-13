<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';
require_once 'utils/cost_center_management_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $actor = authenticateUser();
    requireAnyPermission(
        $conn,
        $actor,
        ['cost_centre.view', 'cost_centre.edit', 'cost_centre.delete'],
        'You do not have permission to access this cost centre.'
    );
    if (!userHasAllCostCenterAccess($actor)) {
        throw new RuntimeException('Cost Centre Administration requires All Cost Centres access.', 403);
    }

    $row = costCenterManagementFind($conn, (int) ($_GET['id'] ?? 0));
    jsonResponse(['status' => 'Success', 'data' => costCenterManagementDecorate($conn, $row)]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Cost Centre/Get] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
