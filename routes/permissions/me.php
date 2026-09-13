<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/rbac_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    unset($user['jti']);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Current permissions fetched successfully.',
        'data' => [
            'role' => [
                'id' => (int) ($user['rbac_role_id'] ?? 0),
                'code' => (string) ($user['rbac_role_code'] ?? ''),
                'name' => (string) ($user['rbac_role_name'] ?? ''),
                'is_super_admin' => (bool) ($user['rbac_is_super_admin'] ?? false),
            ],
            'permissions' => array_values($user['permissions'] ?? []),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Permissions/Me] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
