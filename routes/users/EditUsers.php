<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requireRole($actor, [SMARTBOOKS_ROLE_ADMIN], 'Only an Admin can edit user accounts.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $targetId = (int) ($data['id'] ?? 0);
    if ($targetId <= 0) {
        throw new RuntimeException('A valid user ID is required.', 400);
    }

    $check = $conn->prepare('SELECT id, email, integrity, staff_id FROM admin_table WHERE id = ? LIMIT 1');
    $check->bind_param('i', $targetId);
    $check->execute();
    $existingUser = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$existingUser) {
        throw new RuntimeException('User record not found.', 404);
    }

    $requestedRole = isset($data['integrity']) && trim((string) $data['integrity']) !== ''
        ? trim((string) $data['integrity'])
        : (string) $existingUser['integrity'];

    if (!in_array($requestedRole, SMARTBOOKS_ALLOWED_ROLES, true)) {
        throw new RuntimeException('Invalid user role.', 400);
    }

    if ($targetId === (int) $actor['id'] && $requestedRole !== SMARTBOOKS_ROLE_ADMIN) {
        throw new RuntimeException('You cannot remove your own Admin role.', 400);
    }

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

    $updateFields = [];
    $params = [];
    $types = '';
    $securityChanged = false;

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

    if (isset($data['password']) && (string) $data['password'] !== '') {
        $password = (string) $data['password'];
        if (strlen($password) < 12) {
            throw new RuntimeException('Password must be at least 12 characters long.', 400);
        }

        $updateFields[] = 'password = ?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
        $types .= 's';
        $securityChanged = true;
    }

    $updateFields[] = 'integrity = ?';
    $params[] = $requestedRole;
    $types .= 's';

    $updateFields[] = 'staff_id = ?';
    $params[] = $requestedStaffId;
    $types .= 'i';

    if ($requestedRole !== (string) $existingUser['integrity']
        || (int) ($existingUser['staff_id'] ?? 0) !== (int) ($requestedStaffId ?? 0)) {
        $securityChanged = true;
    }

    $actorEmail = (string) $actor['email'];
    $updateFields[] = 'updated_by = ?';
    $params[] = $actorEmail;
    $types .= 's';
    $params[] = $targetId;
    $types .= 'i';

    $sql = 'UPDATE admin_table SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    if ($securityChanged) {
        revokeAllUserSessions($conn, $targetId);
    }

    $actorId = (int) $actor['id'];
    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $action = "{$actorEmail} updated user account ID {$targetId}";
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    $fetch = $conn->prepare(
        'SELECT a.id, a.fname, a.lname, a.email, a.integrity, a.staff_id,
                s.staff_name AS linked_staff_name, a.created_by, a.updated_by
         FROM admin_table a
         LEFT JOIN staff_table s ON s.staff_id = a.staff_id
         WHERE a.id = ? LIMIT 1'
    );
    $fetch->bind_param('i', $targetId);
    $fetch->execute();
    $updated = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    jsonResponse([
        'status' => 'Success',
        'message' => $securityChanged
            ? 'User access updated. Active sessions for this account were revoked.'
            : 'User updated successfully.',
        'data' => $updated
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Users/Edit] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
