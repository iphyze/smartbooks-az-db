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

    $fetchCurrent = $conn->prepare('SELECT id, password FROM admin_table WHERE id = ? LIMIT 1');
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

    $passwordRequested = isset($data['password']) && (string) $data['password'] !== ''
        || isset($data['currentPassword']) && (string) $data['currentPassword'] !== '';

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

        $updateFields[] = 'password = ?';
        $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        $types .= 's';
        $requiresNewLogin = true;
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
    $action = "{$actorEmail} updated their profile";
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    $fetch = $conn->prepare(
        'SELECT id, fname, lname, email, integrity, staff_id, created_by, updated_by
         FROM admin_table WHERE id = ? LIMIT 1'
    );
    $fetch->bind_param('i', $actorId);
    $fetch->execute();
    $updated = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    jsonResponse([
        'status' => 'Success',
        'message' => $requiresNewLogin
            ? 'Profile updated. Please sign in again.'
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
