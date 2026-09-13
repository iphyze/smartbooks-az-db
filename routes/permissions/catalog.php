<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/rbac_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requireAnyPermission(
        $conn,
        $actor,
        ['permission.view', 'user.manage_permissions'],
        'You do not have permission to view the permission catalogue.'
    );

    jsonResponse([
        'status' => 'Success',
        'message' => 'Permission catalogue fetched successfully.',
        'data' => fetchPermissionCatalogue($conn),
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Permissions/Catalog] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
