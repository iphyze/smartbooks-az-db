<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/rbac_helpers.php';
require_once 'utils/activity_log_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requirePermission(
        $conn,
        $actor,
        'user.manage_permissions',
        'You do not have permission to manage user permissions.'
    );

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $userId = (int) ($data['user_id'] ?? 0);
    $permissions = $data['permissions'] ?? null;
    if ($userId <= 0 || !is_array($permissions)) {
        throw new RuntimeException('A valid user ID and permission selection are required.', 400);
    }

    if ($userId === (int) ($actor['id'] ?? 0)) {
        throw new RuntimeException('You cannot change your own permission matrix from this action.', 400);
    }

    $stmt = $conn->prepare(
        'SELECT id, fname, lname, email, integrity, staff_id, cost_center_access_mode
         FROM admin_table
         WHERE id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read target user.', 500);
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

    if (rbacIsSuperAdmin($target)) {
        throw new RuntimeException('Super Admin has unrestricted access and cannot have a custom permission matrix.', 400);
    }

    $permissions = assertActorCanAssignPermissionSelection($conn, $actor, $permissions);
    $actorEmail = (string) ($actor['email'] ?? 'system');
    $before = array_values($target['permissions'] ?? []);

    $conn->begin_transaction();
    $effective = setExactUserPermissionSelection($conn, $target, $permissions, $actorEmail);
    $conn->commit();

    // Permission changes are security changes. End the target user's sessions so
    // their next login starts with the new frontend permission state as well.
    revokeAllUserSessions($conn, $userId);

    logActivity(
        $conn,
        $actor,
        $actorEmail . ' updated permissions for user #' . $userId,
        'User Administration',
        'update_permissions',
        [
            'created_by' => $actorEmail,
            'description' => 'Granular SmartBooks permissions were updated.',
            'entity_type' => 'user',
            'entity_id' => (string) $userId,
            'metadata' => [
                'before' => $before,
                'after' => $effective,
            ],
        ]
    );

    jsonResponse([
        'status' => 'Success',
        'message' => 'User permissions updated successfully. Active sessions for the user were revoked.',
        'data' => [
            'user_id' => $userId,
            'effective_permissions' => $effective,
            'overrides' => fetchUserPermissionOverrides($conn, $userId),
        ],
    ]);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('[Smartbooks Permissions/UpdateUser] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
