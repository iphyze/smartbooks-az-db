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
    requirePermission(
        $conn,
        $actor,
        'user.manage_permissions',
        'You do not have permission to manage user permissions.'
    );

    $userId = (int) ($_GET['id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('A valid user ID is required.', 400);
    }

    $stmt = $conn->prepare(
        'SELECT id, fname, lname, email, integrity, staff_id, cost_center_access_mode
         FROM admin_table
         WHERE id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read user permissions.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        throw new RuntimeException('User record not found.', 404);
    }

    $target['id'] = (int) $target['id'];
    $target = hydrateUserRbacAccess($conn, $target);
    assertActorCanManageRbacTarget($conn, $actor, $target);
    $role = fetchUserRbacRole($conn, $target);

    jsonResponse([
        'status' => 'Success',
        'message' => 'User permissions fetched successfully.',
        'data' => [
            'user' => [
                'id' => (int) $target['id'],
                'fname' => (string) $target['fname'],
                'lname' => (string) $target['lname'],
                'email' => (string) $target['email'],
                'integrity' => (string) $target['integrity'],
            ],
            'role' => [
                'id' => (int) ($role['id'] ?? 0),
                'code' => (string) ($role['code'] ?? ''),
                'name' => (string) ($role['name'] ?? ''),
                'is_super_admin' => (bool) ($role['is_super_admin'] ?? false),
            ],
            'role_permissions' => fetchRoleDefaultPermissionCodes($conn, (int) ($role['id'] ?? 0)),
            'overrides' => fetchUserPermissionOverrides($conn, $userId),
            'effective_permissions' => array_values($target['permissions'] ?? []),
            'customisable' => !rbacIsSuperAdmin($target),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Permissions/User] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
