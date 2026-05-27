<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requireRole($actor, [SMARTBOOKS_ROLE_ADMIN], 'Only an Admin can create users.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $fname = trim((string) ($data['fname'] ?? ''));
    $lname = trim((string) ($data['lname'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $integrity = trim((string) ($data['integrity'] ?? ''));
    $staffId = isset($data['staff_id']) && $data['staff_id'] !== '' ? (int) $data['staff_id'] : null;

    if ($fname === '' || $lname === '' || !v::email()->validate($email)) {
        throw new RuntimeException('A valid first name, last name and email are required.', 400);
    }

    if (strlen($password) < 12) {
        throw new RuntimeException('Password must be at least 12 characters long.', 400);
    }

    if (!in_array($integrity, SMARTBOOKS_ALLOWED_ROLES, true)) {
        throw new RuntimeException('Invalid user role.', 400);
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

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $actorEmail = (string) $actor['email'];

    $stmt = $conn->prepare(
        'INSERT INTO admin_table (fname, lname, email, password, integrity, staff_id, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssiss', $fname, $lname, $email, $passwordHash, $integrity, $staffId, $actorEmail, $actorEmail);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $actorId = (int) $actor['id'];
    $action = "{$actorEmail} created user {$email} with role {$integrity}";
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    jsonResponse([
        'status' => 'Success',
        'message' => 'User created successfully.',
        'data' => [
            'id' => $newId,
            'fname' => $fname,
            'lname' => $lname,
            'email' => $email,
            'integrity' => $integrity,
            'staff_id' => $staffId,
            'created_by' => $actorEmail,
            'updated_by' => $actorEmail
        ]
    ], 201);
} catch (Throwable $exception) {
    error_log('[Smartbooks Users/Create] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
