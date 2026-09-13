<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/notification_helpers.php';
require_once 'utils/cost_center_access_helpers.php';
require_once 'utils/rbac_helpers.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requirePermission($conn, $actor, 'user.create', 'You do not have permission to create users.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $fname = trim((string) ($data['fname'] ?? ''));
    $lname = trim((string) ($data['lname'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $integrity = trim((string) ($data['integrity'] ?? ''));
    $permissionsProvided = array_key_exists('permissions', $data);
    $requestedPermissions = $permissionsProvided ? ($data['permissions'] ?? null) : null;
    if ($permissionsProvided && !is_array($requestedPermissions)) {
        throw new RuntimeException('Permissions must be provided as an array.', 400);
    }
    $canManagePermissions = userHasPermission($conn, $actor, 'user.manage_permissions');
    $staffId = isset($data['staff_id']) && $data['staff_id'] !== '' ? (int) $data['staff_id'] : null;
    $costCenterAccessMode = normalizeCostCenterAccessMode($data['cost_center_access_mode'] ?? SMARTBOOKS_COST_CENTER_ACCESS_ALL);
    $costCenterIds = costCenterIdsFromPayload($data['cost_center_ids'] ?? []);

    if ($fname === '' || $lname === '' || !v::email()->validate($email)) {
        throw new RuntimeException('A valid first name, last name and email are required.', 400);
    }

    if (isPrimaryAdminEmail($email)) {
        throw new RuntimeException('The primary Admin User email is reserved.', 409);
    }

    if (!in_array($integrity, SMARTBOOKS_ALLOWED_ROLES, true)) {
        throw new RuntimeException('Invalid user role.', 400);
    }

    if ($integrity === SMARTBOOKS_ROLE_SUPER_ADMIN) {
        throw new RuntimeException('The Super Admin role is reserved for the protected primary SmartBooks account.', 400);
    }

    if (!$canManagePermissions && $integrity !== SMARTBOOKS_ROLE_USER) {
        throw new RuntimeException('You need user permission-management access to assign this role.', 403);
    }
    if ($permissionsProvided && !$canManagePermissions) {
        throw new RuntimeException('You do not have permission to assign user permissions.', 403);
    }
    assertActorCanAssignRbacRole($conn, $actor, $integrity, $email);
    if ($permissionsProvided) {
        $requestedPermissions = assertActorCanAssignPermissionSelection($conn, $actor, $requestedPermissions);
    }

    if ($integrity === SMARTBOOKS_ROLE_TIMESHEET) {
        if (!$staffId || $staffId <= 0) {
            throw new RuntimeException('A Timesheet user must be linked to a staff profile.', 400);
        }

        $staff = $conn->prepare('SELECT staff_name FROM staff_table WHERE staff_id = ? LIMIT 1');
        $staff->bind_param('i', $staffId);
        $staff->execute();
        if (!$staff->get_result()->fetch_assoc()) {
            throw new RuntimeException('Selected staff profile was not found.', 400);
        }
        $staff->close();
    } else {
        $staffId = null;
    }

    // Timesheet-only users do not participate in accounting cost-centre scoping.
    if ($integrity === SMARTBOOKS_ROLE_TIMESHEET) {
        $costCenterAccessMode = SMARTBOOKS_COST_CENTER_ACCESS_ALL;
        $costCenterIds = [];
    } else {
        $costCenterIds = validateCostCenterSelection($conn, $costCenterAccessMode, $costCenterIds);
    }

    $duplicate = $conn->prepare('SELECT id FROM admin_table WHERE email = ? LIMIT 1');
    $duplicate->bind_param('s', $email);
    $duplicate->execute();
    if ($duplicate->get_result()->fetch_assoc()) {
        throw new RuntimeException('A user with this email already exists.', 409);
    }
    $duplicate->close();

    if ($staffId !== null) {
        $link = $conn->prepare('SELECT id FROM admin_table WHERE staff_id = ? LIMIT 1');
        $link->bind_param('i', $staffId);
        $link->execute();
        if ($link->get_result()->fetch_assoc()) {
            throw new RuntimeException('That staff profile is already linked to a user account.', 409);
        }
        $link->close();
    }

    $temporaryPassword = defaultUserPassword();
    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $mustChangePassword = 1;
    $actorEmail = (string) $actor['email'];

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        'INSERT INTO admin_table
            (fname, lname, email, password, must_change_password, integrity, staff_id, cost_center_access_mode, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'ssssisisss',
        $fname,
        $lname,
        $email,
        $passwordHash,
        $mustChangePassword,
        $integrity,
        $staffId,
        $costCenterAccessMode,
        $actorEmail,
        $actorEmail
    );
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    replaceUserCostCenterAccess($conn, $newId, $costCenterAccessMode, $costCenterIds, $actorEmail);
    ensureUserRbacRoleAssignment($conn, $newId, $integrity, $email, $actorEmail);

    if ($permissionsProvided) {
        $newUserForPermissions = [
            'id' => $newId,
            'email' => $email,
            'integrity' => $integrity,
        ];
        setExactUserPermissionSelection($conn, $newUserForPermissions, $requestedPermissions, $actorEmail);
    }

    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $actorId = (int) $actor['id'];
    $action = "{$actorEmail} created user {$email} with role {$integrity} and a temporary password";
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    $conn->commit();

    notifyUser(
        $conn,
        $newId,
        'account_created',
        'users',
        'Welcome to Smartbooks',
        "Your {$integrity} account has been created. Sign in with your temporary password and change it before continuing.",
        'info',
        'user',
        $newId,
        '/users/my-profile',
        ['role' => $integrity, 'must_change_password' => true],
        $actorId
    );

    $createdUser = [
        'id' => $newId,
        'fname' => $fname,
        'lname' => $lname,
        'email' => $email,
        'integrity' => $integrity,
        'staff_id' => $staffId,
        'cost_center_access_mode' => $costCenterAccessMode,
        'cost_centers' => $costCenterAccessMode === SMARTBOOKS_COST_CENTER_ACCESS_RESTRICTED
            ? fetchUserCostCenterAccess($conn, $newId)
            : [],
        'must_change_password' => true,
        'created_by' => $actorEmail,
        'updated_by' => $actorEmail
    ];
    $createdUser = hydrateUserRbacAccess($conn, $createdUser);

    jsonResponse([
        'status' => 'Success',
        'message' => 'User created successfully with the temporary password for the current year.',
        'data' => $createdUser
    ], 201);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('[Smartbooks Users/Create] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
