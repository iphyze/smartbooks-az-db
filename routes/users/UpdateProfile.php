<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    $actorId = (int) $actor['id'];
    $actorEmail = (string) $actor['email'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $fetchCurrent = $conn->prepare(
        'SELECT id, password, must_change_password FROM admin_table WHERE id = ? LIMIT 1'
    );
    $fetchCurrent->bind_param('i', $actorId);
    $fetchCurrent->execute();
    $current = $fetchCurrent->get_result()->fetch_assoc();
    $fetchCurrent->close();

    if (!$current) {
        throw new RuntimeException('User record not found.', 404);
    }

    $updateFields = [];
    $params = [];
    $types = '';
    $requiresNewLogin = false;
    $passwordChanged = false;

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

        if (isPrimaryAdminEmail($actorEmail) && !isPrimaryAdminEmail($email)) {
            throw new RuntimeException('The primary Admin User email cannot be changed.', 400);
        }

        $duplicate = $conn->prepare('SELECT id FROM admin_table WHERE email = ? AND id <> ? LIMIT 1');
        $duplicate->bind_param('si', $email, $actorId);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) {
            throw new RuntimeException('Email already in use.', 409);
        }
        $duplicate->close();

        $updateFields[] = 'email = ?';
        $params[] = $email;
        $types .= 's';
        $requiresNewLogin = true;
    }

    $passwordRequested = (isset($data['password']) && (string) $data['password'] !== '')
        || (isset($data['currentPassword']) && (string) $data['currentPassword'] !== '');

    if ($passwordRequested) {
        $currentPassword = (string) ($data['currentPassword'] ?? '');
        $newPassword = (string) ($data['password'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            throw new RuntimeException('Current password and new password are required.', 400);
        }

        $storedHash = (string) $current['password'];
        $validCurrent = password_verify($currentPassword, $storedHash)
            || (preg_match('/^[a-f0-9]{40}$/i', $storedHash) === 1
                && hash_equals(strtolower($storedHash), sha1($currentPassword)));

        if (!$validCurrent) {
            throw new RuntimeException('Current password is incorrect.', 401);
        }

        if (strlen($newPassword) < 12) {
            throw new RuntimeException('New password must be at least 12 characters long.', 400);
        }

        if (password_verify($newPassword, $storedHash) || hash_equals($currentPassword, $newPassword)) {
            throw new RuntimeException('Your new password must be different from your current password.', 400);
        }

        if (isTemporaryUserPassword($newPassword)) {
            throw new RuntimeException('Choose a personal password instead of a Consultancy temporary password.', 400);
        }

        $updateFields[] = 'password = ?';
        $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        $types .= 's';

        $updateFields[] = 'must_change_password = 0';
        $passwordChanged = true;
        $requiresNewLogin = true;
    }

    if (!empty($current['must_change_password']) && !$passwordChanged) {
        throw new RuntimeException('You must change your temporary password before updating other profile details.', 403);
    }

    if (!$updateFields) {
        throw new RuntimeException('No valid fields provided for update.', 400);
    }

    $updateFields[] = 'updated_by = ?';
    $params[] = $actorEmail;
    $types .= 's';
    $params[] = $actorId;
    $types .= 'i';

    $stmt = $conn->prepare('UPDATE admin_table SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    if ($requiresNewLogin) {
        revokeAllUserSessions($conn, $actorId);
        clearAuthCookie();
        clearCsrfCookie();
    }

    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $action = $passwordChanged
        ? "{$actorEmail} changed their password"
        : "{$actorEmail} updated their profile";
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    $fetch = $conn->prepare(
        'SELECT id, fname, lname, email, integrity, staff_id, must_change_password, created_by, updated_by
         FROM admin_table WHERE id = ? LIMIT 1'
    );
    $fetch->bind_param('i', $actorId);
    $fetch->execute();
    $updated = $fetch->get_result()->fetch_assoc();
    $fetch->close();
    $updated['must_change_password'] = (bool) ((int) ($updated['must_change_password'] ?? 0));

    jsonResponse([
        'status' => 'Success',
        'message' => $requiresNewLogin
            ? 'Password updated successfully. Please sign in again with your new password.'
            : 'Profile updated successfully.',
        'requiresLogin' => $requiresNewLogin,
        'data' => $updated
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Users/Profile] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
