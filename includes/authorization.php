<?php
declare(strict_types=1);

require_once __DIR__ . '/authMiddleware.php';

const SMARTBOOKS_ROLE_ADMIN = 'Admin';
const SMARTBOOKS_ROLE_CONTROLLER = 'Controller';
const SMARTBOOKS_ROLE_TIMESHEET = 'Timesheet';
const SMARTBOOKS_ALLOWED_ROLES = [
    SMARTBOOKS_ROLE_ADMIN,
    SMARTBOOKS_ROLE_CONTROLLER,
    SMARTBOOKS_ROLE_TIMESHEET,
];

function userRole(array $user): string
{
    return trim((string) ($user['integrity'] ?? ''));
}

function userHasRole(array $user, array $roles): bool
{
    return in_array(userRole($user), $roles, true);
}

function requireRole(array $user, array $roles, string $message = 'You are not authorised to perform this action.'): void
{
    if (!userHasRole($user, $roles)) {
        throw new RuntimeException($message, 403);
    }
}

/**
 * Compatibility access helper used by the newer bank-reconciliation routes.
 *
 * Those routes were originally built around an AcctLab `requireAdmin()` helper.
 * Smartbooks authorises both Admin and Controller users for accounting modules,
 * so this wrapper keeps endpoint-level checks aligned with the router policy.
 */
function requireAdmin(): array
{
    $user = authenticateUser();

    requireRole(
        $user,
        [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
        'Only Admin or Controller users can access Bank Reconciliation.'
    );

    return $user;
}

function isTimesheetOnlyUser(array $user): bool
{
    return userRole($user) === SMARTBOOKS_ROLE_TIMESHEET;
}

/**
 * Returns the staff profile explicitly linked to a Timesheet-only user.
 * A missing or invalid mapping must not silently fall back to an email/name lookup.
 */
function requireLinkedTimesheetStaff(mysqli $conn, array $user): array
{
    requireRole($user, [SMARTBOOKS_ROLE_TIMESHEET], 'A Timesheet staff profile is required.');

    $staffId = (int) ($user['staff_id'] ?? 0);
    if ($staffId <= 0) {
        throw new RuntimeException(
            'Your Timesheet account has not been linked to a staff profile. Please contact an Admin.',
            403
        );
    }

    $stmt = $conn->prepare(
        'SELECT id, staff_id, staff_name, staff_email
         FROM staff_table
         WHERE staff_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate staff access.', 500);
    }

    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff) {
        throw new RuntimeException(
            'Your linked staff profile is no longer available. Please contact an Admin.',
            403
        );
    }

    $staff['id'] = (int) $staff['id'];
    $staff['staff_id'] = (int) $staff['staff_id'];
    return $staff;
}

/**
 * Returns an ownership scope for Timesheet-only users, or null for Admin/Controller.
 */
function timesheetStaffScope(mysqli $conn, array $user): ?array
{
    return isTimesheetOnlyUser($user) ? requireLinkedTimesheetStaff($conn, $user) : null;
}

function assertTimesheetEntryAccess(mysqli $conn, array $user, int $entryId): void
{
    if (!isTimesheetOnlyUser($user)) {
        return;
    }

    $scope = requireLinkedTimesheetStaff($conn, $user);
    $staffId = (int) $scope['staff_id'];
    $stmt = $conn->prepare('SELECT id FROM timesheet_table WHERE id = ? AND staff_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to validate timesheet access.', 500);
    }
    $stmt->bind_param('ii', $entryId, $staffId);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$entry) {
        // Return not-found to avoid exposing another staff member's record existence.
        throw new RuntimeException('Timesheet entry not found.', 404);
    }
}

/**
 * Router-level deny-by-default role enforcement. Endpoint-level checks remain in place
 * for object ownership and action-specific validation.
 */
function enforceApiRouteAccess(string $relativePath): void
{
    global $conn;

    $publicPaths = ['/', '/welcome', '/auth/csrf', '/auth/bootstrap', '/auth/login'];
    if (in_array($relativePath, $publicPaths, true)) {
        return;
    }

    $user = authenticateUser();

    // Session introspection and logout remain available so an account with a retired role can exit safely.
    if (in_array($relativePath, ['/auth/me', '/auth/logout'], true)) {
        return;
    }

    if (!empty($user['must_change_password'])) {
        if ($relativePath === '/users/updateProfile') {
            return;
        }

        jsonResponse([
            'status' => 'Failed',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
            'message' => 'You must change your temporary password before accessing Smartbooks.'
        ], 403);
    }

    requireRole($user, SMARTBOOKS_ALLOWED_ROLES, 'Your account does not have an active Smartbooks role.');

    // Notifications are personal to the authenticated recipient and are available
    // to every active Smartbooks role. Endpoint queries still scope every operation
    // to the current user's ID.
    if (str_starts_with($relativePath, '/notifications/')) {
        return;
    }

    if (in_array($relativePath, ['/users/getSingleUser', '/users/updateProfile'], true)) {
        return;
    }

    if (str_starts_with($relativePath, '/users/')) {
        requireRole($user, [SMARTBOOKS_ROLE_ADMIN], 'Only Admin users can manage user accounts.');
        return;
    }

    if (str_starts_with($relativePath, '/timesheet/')) {
        requireRole(
            $user,
            [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER, SMARTBOOKS_ROLE_TIMESHEET],
            'You are not authorised to access Timesheets.'
        );
        if (isTimesheetOnlyUser($user)) {
            requireLinkedTimesheetStaff($conn, $user);
        }
        return;
    }

    requireRole(
        $user,
        [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
        'Only Admin or Controller users can access this module.'
    );
}
