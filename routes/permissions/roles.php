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
        ['role.view', 'user.manage_permissions'],
        'You do not have permission to view RBAC roles.'
    );

    jsonResponse([
        'status' => 'Success',
        'message' => 'RBAC roles fetched successfully.',
        'data' => fetchRbacRoles($conn),
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Permissions/Roles] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
