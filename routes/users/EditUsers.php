<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/notification_helpers.php';
require_once 'utils/cost_center_access_helpers.php';
require_once 'utils/rbac_helpers.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requirePermission($conn, $actor, 'user.edit', 'You do not have permission to edit users.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $targetId = (int) ($data['id'] ?? 0);
    if ($targetId <= 0) {
        throw new RuntimeException('A valid user ID is required.', 400);
    }

    $permissionsProvided = array_key_exists('permissions', $data);
    $requestedPermissions = $permissionsProvided ? ($data['permissions'] ?? null) : null;
    if ($permissionsProvided && !is_array($requestedPermissions)) {
        throw new RuntimeException('Permissions must be provided as an array.', 400);
    }

    $check = $conn->prepare(
        'SELECT id, fname, lname, email, integrity, staff_id, cost_center_access_mode, must_change_password
         FROM admin_table WHERE id = ? LIMIT 1'
    );
    $check->bind_param('i', $targetId);
    $check->execute();
    $existingUser = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$existingUser) {
        throw new RuntimeException('User record not found.', 404);
    }

    $existingUser['id'] = (int) $existingUser['id'];
    $existingRbacUser = hydrateUserRbacAccess($conn, $existingUser);
    if (rbacIsSuperAdmin($existingRbacUser) && !rbacIsSuperAdmin($actor)) {
        throw new RuntimeException('Only a Super Admin can edit a Super Admin account.', 403);
    }
    assertActorCanManageRbacTarget($conn, $actor, $existingRbacUser);

    $requestedRole = isset($data['integrity']) && trim((string) $data['integrity']) !== ''
        ? trim((string) $data['integrity'])
        : (string) $existingUser['integrity'];

    if (!in_array($requestedRole, SMARTBOOKS_ALLOWED_ROLES, true)) {
        throw new RuntimeException('Invalid user role.', 400);
    }

    if ($requestedRole === SMARTBOOKS_ROLE_SUPER_ADMIN) {
        throw new RuntimeException('The Super Admin role is reserved for the protected primary SmartBooks account.', 400);
    }

    $roleChanged = $requestedRole !== (string) $existingUser['integrity'];
    if ($roleChanged) {
        if ($targetId === (int) ($actor['id'] ?? 0)) {
            throw new RuntimeException('You cannot change your own RBAC role from User Administration.', 400);
        }
        requirePermission(
            $conn,
            $actor,
            'user.manage_permissions',
            'You do not have permission to change user roles.'
        );
        assertActorCanAssignRbacRole($conn, $actor, $requestedRole, (string) $existingUser['email']);
    }

    if ($permissionsProvided) {
        if ($targetId === (int) ($actor['id'] ?? 0)) {
            throw new RuntimeException('You cannot change your own permission matrix from User Administration.', 400);
        }
        if (rbacIsSuperAdmin($existingRbacUser)) {
            throw new RuntimeException('Super Admin permissions are always unrestricted and cannot be customised.', 400);
        }
        requirePermission(
            $conn,
            $actor,
            'user.manage_permissions',
            'You do not have permission to manage user permissions.'
        );
        $requestedPermissions = assertActorCanAssignPermissionSelection($conn, $actor, $requestedPermissions);
    }

    $requestedCostCenterMode = normalizeCostCenterAccessMode(
        $data['cost_center_access_mode'] ?? ($existingUser['cost_center_access_mode'] ?? SMARTBOOKS_COST_CENTER_ACCESS_ALL)
    );
    $existingCostCenters = fetchUserCostCenterAccess($conn, $targetId);
    $existingCostCenterIds = array_map(static fn(array $row): int => (int) $row['id'], $existingCostCenters);
    $requestedCostCenterIds = array_key_exists('cost_center_ids', $data)
        ? costCenterIdsFromPayload($data['cost_center_ids'])
        : $existingCostCenterIds;

    $requestedStaffId = isset($data['staff_id']) && $data['staff_id'] !== ''
        ? (int) $data['staff_id']
        : null;

    if ($requestedRole === SMARTBOOKS_ROLE_TIMESHEET) {
        if (!$requestedStaffId || $requestedStaffId <= 0) {
            throw new RuntimeException('A Timesheet user must be linked to a staff profile.', 400);
        }

        $staff = $conn->prepare('SELECT staff_name FROM staff_table WHERE staff_id = ? LIMIT 1');
        $staff->bind_param('i', $requestedStaffId);
        $staff->execute();
        if (!$staff->get_result()->fetch_assoc()) {
            throw new RuntimeException('Selected staff profile was not found.', 400);
        }
        $staff->close();

        $link = $conn->prepare('SELECT id FROM admin_table WHERE staff_id = ? AND id <> ? LIMIT 1');
        $link->bind_param('ii', $requestedStaffId, $targetId);
        $link->execute();
        if ($link->get_result()->fetch_assoc()) {
            throw new RuntimeException('That staff profile is already linked to another user account.', 409);
        }
        $link->close();
    } else {
        $requestedStaffId = null;
    }

    if ($requestedRole === SMARTBOOKS_ROLE_TIMESHEET) {
        $requestedCostCenterMode = SMARTBOOKS_COST_CENTER_ACCESS_ALL;
        $requestedCostCenterIds = [];
    } else {
        $requestedCostCenterIds = validateCostCenterSelection($conn, $requestedCostCenterMode, $requestedCostCenterIds);
    }

    if ($targetId === (int) ($actor['id'] ?? 0)) {
        $existingModeForSelf = normalizeCostCenterAccessMode(
            $existingUser['cost_center_access_mode'] ?? SMARTBOOKS_COST_CENTER_ACCESS_ALL
        );
        $existingIdsForSelf = $existingCostCenterIds;
        $requestedIdsForSelf = $requestedCostCenterIds;
        sort($existingIdsForSelf);
        sort($requestedIdsForSelf);

        $costCenterAccessChanged = $requestedCostCenterMode !== $existingModeForSelf
            || ($requestedCostCenterMode === SMARTBOOKS_COST_CENTER_ACCESS_RESTRICTED
                && $existingIdsForSelf !== $requestedIdsForSelf);

        if ($costCenterAccessChanged) {
            throw new RuntimeException('You cannot change your own cost-centre access from User Administration.', 400);
        }
    }

    $updateFields = [];
    $params = [];
    $types = '';
    $securityChanged = false;
    $passwordReset = filter_var($data['reset_password'] ?? false, FILTER_VALIDATE_BOOLEAN)
        || (isset($data['password']) && (string) $data['password'] !== '');

    if (isset($data['fname']) && trim((string) $data['fname']) !== '') {
        $updateFields[] = 'fname = ?';
        $params[] = trim((string) $data['fname']);
        $types .= 's';
    }

    if (isset($data['lname']) && trim((string) $data['lname']) !== '') {
        $updateFields[] = 'lname = ?';
        $params[] = trim((string) $data['lname']);
        $types .= 's';
    }

    if (isset($data['email']) && trim((string) $data['email']) !== '') {
        $email = strtolower(trim((string) $data['email']));
        if (!v::email()->validate($email)) {
            throw new RuntimeException('Invalid email format.', 400);
        }

        if (isPrimaryAdminEmail((string) $existingUser['email']) && !isPrimaryAdminEmail($email)) {
            throw new RuntimeException('The primary Admin User email cannot be changed.', 400);
        }

        if (!isPrimaryAdminEmail((string) $existingUser['email']) && isPrimaryAdminEmail($email)) {
            throw new RuntimeException('The primary Admin User email is reserved.', 409);
        }

        $duplicate = $conn->prepare('SELECT id FROM admin_table WHERE email = ? AND id <> ? LIMIT 1');
        $duplicate->bind_param('si', $email, $targetId);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) {
            throw new RuntimeException('Email already in use by another user.', 409);
        }
        $duplicate->close();

        $updateFields[] = 'email = ?';
        $params[] = $email;
        $types .= 's';
        $securityChanged = true;
    }

    if ($passwordReset) {
        if (isPrimaryAdminEmail((string) $existingUser['email'])) {
            throw new RuntimeException('The primary Admin User password cannot be reset to the shared temporary password.', 400);
        }

        $updateFields[] = 'password = ?';
        $params[] = password_hash(defaultUserPassword(), PASSWORD_DEFAULT);
        $types .= 's';

        $updateFields[] = 'must_change_password = 1';
        $securityChanged = true;
    }

    $updateFields[] = 'integrity = ?';
    $params[] = $requestedRole;
    $types .= 's';

    $updateFields[] = 'staff_id = ?';
    $params[] = $requestedStaffId;
    $types .= 'i';

    $updateFields[] = 'cost_center_access_mode = ?';
    $params[] = $requestedCostCenterMode;
    $types .= 's';

    $existingMode = normalizeCostCenterAccessMode($existingUser['cost_center_access_mode'] ?? SMARTBOOKS_COST_CENTER_ACCESS_ALL);
    $oldIds = $existingCostCenterIds;
    $newIds = $requestedCostCenterIds;
    sort($oldIds);
    sort($newIds);

    if ($roleChanged
        || (int) ($existingUser['staff_id'] ?? 0) !== (int) ($requestedStaffId ?? 0)
        || $requestedCostCenterMode !== $existingMode
        || ($requestedCostCenterMode === SMARTBOOKS_COST_CENTER_ACCESS_RESTRICTED && $oldIds !== $newIds)) {
        $securityChanged = true;
    }

    if ($permissionsProvided) {
        $beforePermissions = array_values($existingRbacUser['permissions'] ?? []);
        $afterPermissions = array_values($requestedPermissions);
        sort($beforePermissions, SORT_STRING);
        sort($afterPermissions, SORT_STRING);
        if ($beforePermissions !== $afterPermissions) {
            $securityChanged = true;
        }
    }

    $actorEmail = (string) $actor['email'];
    $updateFields[] = 'updated_by = ?';
    $params[] = $actorEmail;
    $types .= 's';
    $params[] = $targetId;
    $types .= 'i';

    $conn->begin_transaction();

    $sql = 'UPDATE admin_table SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    replaceUserCostCenterAccess($conn, $targetId, $requestedCostCenterMode, $requestedCostCenterIds, $actorEmail);
    $effectiveEmail = isset($email) ? $email : (string) $existingUser['email'];
    ensureUserRbacRoleAssignment($conn, $targetId, $requestedRole, $effectiveEmail, $actorEmail);

    if ($permissionsProvided) {
        $permissionTarget = [
            'id' => $targetId,
            'email' => $effectiveEmail,
            'integrity' => $requestedRole,
        ];
        setExactUserPermissionSelection($conn, $permissionTarget, $requestedPermissions, $actorEmail);
    } elseif ($roleChanged) {
        // Overrides belong to the old role baseline. A role change without an
        // explicit matrix starts cleanly from the new role defaults.
        clearUserPermissionOverrides($conn, $targetId);
    }

    $conn->commit();

    if ($securityChanged) {
        revokeAllUserSessions($conn, $targetId);
    }

    $actorId = (int) $actor['id'];
    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $action = $passwordReset
        ? "{$actorEmail} updated user account ID {$targetId} and reset its temporary password"
        : "{$actorEmail} updated user account ID {$targetId}";
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    $fetch = $conn->prepare(
        'SELECT a.id, a.fname, a.lname, a.email, a.integrity, a.staff_id,
                a.cost_center_access_mode, a.must_change_password, s.staff_name AS linked_staff_name,
                a.created_by, a.updated_by
         FROM admin_table a
         LEFT JOIN staff_table s ON s.staff_id = a.staff_id
         WHERE a.id = ? LIMIT 1'
    );
    $fetch->bind_param('i', $targetId);
    $fetch->execute();
    $updated = $fetch->get_result()->fetch_assoc();
    $fetch->close();
    $updated['must_change_password'] = (bool) ((int) ($updated['must_change_password'] ?? 0));
    $updated = hydrateUserCostCenterAccess($conn, $updated);
    $updated = hydrateUserRbacAccess($conn, $updated);

    if ($targetId !== $actorId) {
        $notificationTitle = $passwordReset
            ? 'Your Smartbooks password was reset'
            : ($securityChanged ? 'Your Smartbooks access was updated' : 'Your profile was updated');
        $notificationMessage = $passwordReset
            ? 'An authorised administrator reset your password to the temporary password. You will be required to change it at your next sign-in.'
            : ($securityChanged
                ? "Your Smartbooks account now uses the {$requestedRole} role. Review your profile if anything looks unexpected."
                : 'An authorised administrator updated your Smartbooks profile information. Review your profile if anything looks unexpected.');

        notifyUser(
            $conn,
            $targetId,
            $passwordReset ? 'password_reset' : 'account_updated',
            $securityChanged ? 'security' : 'users',
            $notificationTitle,
            $notificationMessage,
            $securityChanged ? 'warning' : 'info',
            'user',
            $targetId,
            '/users/my-profile',
            ['role' => $requestedRole, 'cost_center_access_mode' => $requestedCostCenterMode, 'security_changed' => $securityChanged],
            $actorId
        );
    }

    jsonResponse([
        'status' => 'Success',
        'message' => $passwordReset
            ? 'User password reset to the current temporary password. The user must change it at next login.'
            : ($securityChanged
                ? 'User access updated. Active sessions for this account were revoked.'
                : 'User updated successfully.'),
        'data' => $updated
    ]);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('[Smartbooks Users/Edit] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
