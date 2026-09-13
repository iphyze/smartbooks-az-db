<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

function normalizeRbacRoleCode(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace([' ', '-'], '_', $value);
    return preg_replace('/[^a-z0-9_]/', '', $value) ?: 'user';
}

function legacyIntegrityToRbacRoleCode(string $integrity, string $email = ''): string
{
    if ($email !== '' && isPrimaryAdminEmail($email)) {
        return 'super_admin';
    }

    return match (strtolower(trim($integrity))) {
        'super admin', 'super_admin' => 'super_admin',
        'admin' => 'admin',
        'controller' => 'controller',
        'timesheet' => 'timesheet',
        'user' => 'user',
        default => 'user',
    };
}

function fetchRbacRoleByCode(mysqli $conn, string $code): ?array
{
    $code = normalizeRbacRoleCode($code);
    $stmt = $conn->prepare(
        'SELECT id, code, name, description, is_system, is_super_admin, is_active
         FROM rbac_roles
         WHERE code = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read RBAC role configuration.', 500);
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($role) {
        $role['id'] = (int) $role['id'];
        $role['is_system'] = (bool) ((int) $role['is_system']);
        $role['is_super_admin'] = (bool) ((int) $role['is_super_admin']);
        $role['is_active'] = (bool) ((int) $role['is_active']);
    }

    return $role;
}

function fetchUserRbacRole(mysqli $conn, array $user): array
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId > 0) {
        $stmt = $conn->prepare(
            'SELECT r.id, r.code, r.name, r.description, r.is_system, r.is_super_admin, r.is_active
             FROM rbac_user_roles ur
             INNER JOIN rbac_roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to read user RBAC role.', 500);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $role = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($role) {
            $role['id'] = (int) $role['id'];
            $role['is_system'] = (bool) ((int) $role['is_system']);
            $role['is_super_admin'] = (bool) ((int) $role['is_super_admin']);
            $role['is_active'] = (bool) ((int) $role['is_active']);
            return $role;
        }
    }

    $fallbackCode = legacyIntegrityToRbacRoleCode(
        (string) ($user['integrity'] ?? ''),
        (string) ($user['email'] ?? '')
    );
    $fallbackRole = fetchRbacRoleByCode($conn, $fallbackCode);
    if ($fallbackRole) {
        return $fallbackRole;
    }

    return [
        'id' => 0,
        'code' => $fallbackCode,
        'name' => ucwords(str_replace('_', ' ', $fallbackCode)),
        'description' => '',
        'is_system' => true,
        'is_super_admin' => $fallbackCode === 'super_admin',
        'is_active' => true,
    ];
}

function rbacIsSuperAdmin(array $user): bool
{
    if (!empty($user['is_super_admin']) || !empty($user['rbac_is_super_admin'])) {
        return true;
    }

    $roleCode = normalizeRbacRoleCode((string) ($user['rbac_role_code'] ?? ''));
    if ($roleCode === 'super_admin') {
        return true;
    }

    if (strtolower(trim((string) ($user['integrity'] ?? ''))) === 'super admin') {
        return true;
    }

    $email = (string) ($user['email'] ?? '');
    return $email !== '' && isPrimaryAdminEmail($email);
}

function fetchRoleDefaultPermissionCodes(mysqli $conn, int $roleId): array
{
    if ($roleId <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT p.code
         FROM rbac_role_permissions rp
         INNER JOIN rbac_permissions p ON p.id = rp.permission_id AND p.is_active = 1
         WHERE rp.role_id = ? AND rp.allowed = 1
         ORDER BY p.sort_order ASC, p.code ASC'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read role permissions.', 500);
    }
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_values(array_map(static fn(array $row): string => (string) $row['code'], $rows));
}

function fetchUserPermissionOverrides(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT p.code, up.effect
         FROM rbac_user_permission_overrides up
         INNER JOIN rbac_permissions p ON p.id = up.permission_id AND p.is_active = 1
         WHERE up.user_id = ?
         ORDER BY p.sort_order ASC, p.code ASC'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read user permission overrides.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $overrides = [];
    foreach ($rows as $row) {
        $overrides[(string) $row['code']] = (string) $row['effect'];
    }
    return $overrides;
}

function fetchAllActivePermissionCodes(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT code FROM rbac_permissions WHERE is_active = 1 ORDER BY sort_order ASC, code ASC'
    );
    if (!$result) {
        throw new RuntimeException('Unable to read permission catalogue.', 500);
    }
    return array_values(array_map(
        static fn(array $row): string => (string) $row['code'],
        $result->fetch_all(MYSQLI_ASSOC)
    ));
}

function fetchEffectivePermissionCodes(mysqli $conn, array $user): array
{
    $role = fetchUserRbacRole($conn, $user);
    if (!empty($role['is_super_admin']) || rbacIsSuperAdmin($user)) {
        return fetchAllActivePermissionCodes($conn);
    }

    $defaults = fetchRoleDefaultPermissionCodes($conn, (int) ($role['id'] ?? 0));
    $effective = array_fill_keys($defaults, true);
    $overrides = fetchUserPermissionOverrides($conn, (int) ($user['id'] ?? 0));

    foreach ($overrides as $code => $effect) {
        if ($effect === 'allow') {
            $effective[$code] = true;
        } elseif ($effect === 'deny') {
            unset($effective[$code]);
        }
    }

    $codes = array_keys($effective);
    sort($codes, SORT_STRING);
    return $codes;
}

function hydrateUserRbacAccess(mysqli $conn, array $user): array
{
    $role = fetchUserRbacRole($conn, $user);
    $user['rbac_role_id'] = (int) ($role['id'] ?? 0);
    $user['rbac_role_code'] = (string) ($role['code'] ?? 'user');
    $user['rbac_role_name'] = (string) ($role['name'] ?? 'User');
    $user['rbac_is_super_admin'] = (bool) ($role['is_super_admin'] ?? false) || rbacIsSuperAdmin($user);
    $user['is_super_admin'] = $user['rbac_is_super_admin'];
    $user['permissions'] = fetchEffectivePermissionCodes($conn, $user);
    return $user;
}

function userHasPermission(mysqli $conn, array $user, string $permissionCode): bool
{
    if (rbacIsSuperAdmin($user)) {
        return true;
    }

    $permissionCode = trim($permissionCode);
    if ($permissionCode === '') {
        return false;
    }

    $permissions = isset($user['permissions']) && is_array($user['permissions'])
        ? $user['permissions']
        : fetchEffectivePermissionCodes($conn, $user);

    return in_array($permissionCode, $permissions, true);
}

function requirePermission(
    mysqli $conn,
    array $user,
    string $permissionCode,
    string $message = 'You do not have permission to perform this action.'
): void {
    if (!userHasPermission($conn, $user, $permissionCode)) {
        throw new RuntimeException($message, 403);
    }
}

function userHasAnyPermission(mysqli $conn, array $user, array $permissionCodes): bool
{
    if (rbacIsSuperAdmin($user)) {
        return true;
    }

    foreach ($permissionCodes as $permissionCode) {
        $code = trim((string) $permissionCode);
        if ($code !== '' && userHasPermission($conn, $user, $code)) {
            return true;
        }
    }

    return false;
}

function requireAnyPermission(
    mysqli $conn,
    array $user,
    array $permissionCodes,
    string $message = 'You do not have permission to perform this action.'
): void {
    if (!userHasAnyPermission($conn, $user, $permissionCodes)) {
        throw new RuntimeException($message, 403);
    }
}

function ensureUserRbacRoleAssignment(
    mysqli $conn,
    int $userId,
    string $integrity,
    string $email,
    string $actor = 'system'
): void {
    if ($userId <= 0) {
        throw new RuntimeException('A valid user is required for role assignment.', 400);
    }

    $roleCode = legacyIntegrityToRbacRoleCode($integrity, $email);
    $role = fetchRbacRoleByCode($conn, $roleCode);
    if (!$role || empty($role['is_active'])) {
        throw new RuntimeException('The selected RBAC role is not available.', 400);
    }

    $roleId = (int) $role['id'];
    $stmt = $conn->prepare(
        'INSERT INTO rbac_user_roles (user_id, role_id, created_by, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), updated_by = VALUES(updated_by)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to save user role assignment.', 500);
    }
    $stmt->bind_param('iiss', $userId, $roleId, $actor, $actor);
    $stmt->execute();
    $stmt->close();
}

function fetchPermissionCatalogue(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT id, code, module, action, label, description, sort_order
         FROM rbac_permissions
         WHERE is_active = 1
         ORDER BY module ASC, sort_order ASC, label ASC'
    );
    if (!$result) {
        throw new RuntimeException('Unable to read permission catalogue.', 500);
    }

    $modules = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $module = (string) $row['module'];
        if (!isset($modules[$module])) {
            $modules[$module] = [
                'module' => $module,
                'label' => ucwords(str_replace('_', ' ', $module)),
                'permissions' => [],
            ];
        }
        $modules[$module]['permissions'][] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'action' => (string) $row['action'],
            'label' => (string) $row['label'],
            'description' => (string) $row['description'],
        ];
    }

    return array_values($modules);
}

function fetchRbacRoles(mysqli $conn, bool $includeInactive = false): array
{
    $sql = 'SELECT id, code, name, description, is_system, is_super_admin, is_active
            FROM rbac_roles';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_super_admin DESC, name ASC';

    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Unable to read RBAC roles.', 500);
    }

    $roles = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $roleId = (int) $row['id'];
        $roles[] = [
            'id' => $roleId,
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'is_system' => (bool) ((int) $row['is_system']),
            'is_super_admin' => (bool) ((int) $row['is_super_admin']),
            'is_active' => (bool) ((int) $row['is_active']),
            'permissions' => fetchRoleDefaultPermissionCodes($conn, $roleId),
        ];
    }
    return $roles;
}


function normalizePermissionSelection(mysqli $conn, array $permissionCodes): array
{
    $catalogue = fetchAllActivePermissionCodes($conn);
    $validCodes = array_fill_keys($catalogue, true);
    $selected = [];

    foreach ($permissionCodes as $rawCode) {
        $code = trim((string) $rawCode);
        if ($code === '') {
            continue;
        }
        if (!isset($validCodes[$code])) {
            throw new RuntimeException('The permission selection contains an unknown or inactive permission.', 400);
        }
        $selected[$code] = true;
    }

    return array_keys($selected);
}

function assertActorCanAssignPermissionSelection(
    mysqli $conn,
    array $actor,
    array $permissionCodes
): array {
    $selected = normalizePermissionSelection($conn, $permissionCodes);
    if (rbacIsSuperAdmin($actor)) {
        return $selected;
    }

    $actorPermissions = array_fill_keys(fetchEffectivePermissionCodes($conn, $actor), true);
    foreach ($selected as $code) {
        if (!isset($actorPermissions[$code])) {
            throw new RuntimeException(
                'You cannot grant a permission that you do not currently hold.',
                403
            );
        }
    }

    return $selected;
}

function assertActorCanAssignRbacRole(
    mysqli $conn,
    array $actor,
    string $integrity,
    string $email = ''
): array {
    $roleCode = legacyIntegrityToRbacRoleCode($integrity, $email);
    $role = fetchRbacRoleByCode($conn, $roleCode);
    if (!$role || empty($role['is_active'])) {
        throw new RuntimeException('The selected RBAC role is not available.', 400);
    }

    if (!empty($role['is_super_admin']) || $roleCode === 'super_admin') {
        throw new RuntimeException(
            'The Super Admin role is reserved for the protected primary SmartBooks account.',
            400
        );
    }

    if (rbacIsSuperAdmin($actor)) {
        return $role;
    }

    $actorPermissions = array_fill_keys(fetchEffectivePermissionCodes($conn, $actor), true);
    foreach (fetchRoleDefaultPermissionCodes($conn, (int) $role['id']) as $code) {
        if (!isset($actorPermissions[$code])) {
            throw new RuntimeException(
                'You cannot assign a role whose default permissions exceed your own access.',
                403
            );
        }
    }

    return $role;
}

function assertActorCanManageRbacTarget(mysqli $conn, array $actor, array $target): void
{
    if (rbacIsSuperAdmin($actor)) {
        return;
    }

    $target = isset($target['permissions']) && is_array($target['permissions'])
        ? $target
        : hydrateUserRbacAccess($conn, $target);

    if (rbacIsSuperAdmin($target)) {
        throw new RuntimeException('Only a Super Admin can manage a Super Admin account.', 403);
    }

    $actorPermissions = array_fill_keys(fetchEffectivePermissionCodes($conn, $actor), true);
    foreach (($target['permissions'] ?? []) as $code) {
        if (!isset($actorPermissions[(string) $code])) {
            throw new RuntimeException(
                'You cannot manage an account whose effective permissions exceed your own access.',
                403
            );
        }
    }
}

function clearUserPermissionOverrides(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        throw new RuntimeException('A valid user is required.', 400);
    }

    $stmt = $conn->prepare('DELETE FROM rbac_user_permission_overrides WHERE user_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to reset user permission overrides.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function setExactUserPermissionSelection(
    mysqli $conn,
    array $targetUser,
    array $selectedPermissionCodes,
    string $actor
): array {
    if (rbacIsSuperAdmin($targetUser)) {
        throw new RuntimeException('Super Admin permissions are always unrestricted and cannot be customised.', 400);
    }

    $catalogue = fetchAllActivePermissionCodes($conn);
    $selected = array_fill_keys(
        normalizePermissionSelection($conn, $selectedPermissionCodes),
        true
    );

    $role = fetchUserRbacRole($conn, $targetUser);
    $roleDefaults = array_fill_keys(
        fetchRoleDefaultPermissionCodes($conn, (int) ($role['id'] ?? 0)),
        true
    );

    $userId = (int) ($targetUser['id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('A valid target user is required.', 400);
    }

    $delete = $conn->prepare('DELETE FROM rbac_user_permission_overrides WHERE user_id = ?');
    if (!$delete) {
        throw new RuntimeException('Unable to reset user permissions.', 500);
    }
    $delete->bind_param('i', $userId);
    $delete->execute();
    $delete->close();

    $insert = $conn->prepare(
        'INSERT INTO rbac_user_permission_overrides
            (user_id, permission_id, effect, created_by, updated_by)
         SELECT ?, p.id, ?, ?, ?
         FROM rbac_permissions p
         WHERE p.code = ? AND p.is_active = 1
         LIMIT 1'
    );
    if (!$insert) {
        throw new RuntimeException('Unable to save user permission overrides.', 500);
    }

    foreach ($catalogue as $code) {
        $roleAllows = isset($roleDefaults[$code]);
        $userWants = isset($selected[$code]);
        if ($roleAllows === $userWants) {
            continue;
        }

        $effect = $userWants ? 'allow' : 'deny';
        $insert->bind_param('issss', $userId, $effect, $actor, $actor, $code);
        $insert->execute();
    }
    $insert->close();

    return fetchEffectivePermissionCodes($conn, $targetUser);
}

