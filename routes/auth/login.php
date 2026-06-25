<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/security.php';
require_once 'utils/activity_log_helpers.php';

use Firebase\JWT\JWT;
use Respect\Validation\Validator as v;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Method not allowed.'
    ], 405);
}

try {
    enforceTrustedOrigin();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    if (!v::email()->validate($email) || $password === '') {
        throw new RuntimeException('Invalid email or password.', 401);
    }

    assertLoginNotRateLimited($conn, $email);

    $stmt = $conn->prepare(
        'SELECT id, fname, lname, username, email, password, integrity, staff_id, must_change_password, created_by, updated_by
         FROM admin_table
         WHERE email = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $passwordValid = false;
    $legacyPassword = false;

    if ($user) {
        $storedHash = (string) $user['password'];
        $passwordValid = password_verify($password, $storedHash);

        // Transitional support for the legacy SHA-1 hashes present in the supplied schema.
        $legacyPassword = preg_match('/^[a-f0-9]{40}$/i', $storedHash) === 1
            && hash_equals(strtolower($storedHash), sha1($password));
        $passwordValid = $passwordValid || $legacyPassword;
    }

    if (!$user || !$passwordValid) {
        recordLoginAttempt($conn, $email, false);
        throw new RuntimeException('Invalid email or password.', 401);
    }

    $userId = (int) $user['id'];

    if ($legacyPassword || password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
        $replacementHash = password_hash($password, PASSWORD_DEFAULT);
        $rehashStmt = $conn->prepare('UPDATE admin_table SET password = ? WHERE id = ?');
        $rehashStmt->bind_param('si', $replacementHash, $userId);
        $rehashStmt->execute();
        $rehashStmt->close();
    }

    $issuedAt = time();
    $expiresAt = $issuedAt + jwtTtlSeconds();
    $jti = bin2hex(random_bytes(32));

    $payload = [
        'iss' => jwtIssuer(),
        'aud' => jwtAudience(),
        'sub' => (string) $userId,
        'jti' => $jti,
        'iat' => $issuedAt,
        'nbf' => $issuedAt,
        'exp' => $expiresAt,
    ];

    $jwt = JWT::encode($payload, jwtSecret(), 'HS256');

    createAuthSession($conn, $userId, $jti, $expiresAt);
    recordLoginAttempt($conn, $email, true);

    // Keep a lightweight, user-facing record of the most recent successful sign-in.
    // This must never prevent an otherwise valid login from completing.
    $lastLoginStmt = $conn->prepare('UPDATE admin_table SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    if ($lastLoginStmt) {
        $lastLoginStmt->bind_param('i', $userId);
        if (!$lastLoginStmt->execute()) {
            error_log('[Smartbooks Login] Unable to update last_login_at for user #' . $userId . ': ' . $lastLoginStmt->error);
        }
        $lastLoginStmt->close();
    } else {
        error_log('[Smartbooks Login] Unable to prepare last_login_at update: ' . $conn->error);
    }

    issueAuthCookie($jwt, $expiresAt);
    $csrfToken = issueCsrfCookie(true);

    $actor = trim($user['fname'] . ' ' . $user['lname']);
    logActivity(
        $conn,
        $user,
        $actor . ' logged in successfully',
        'Authentication',
        'login',
        [
            'created_by' => $actor,
            'description' => 'Successful sign-in to Smartbooks.',
            'entity_type' => 'user',
            'entity_id' => (string) $userId,
            'metadata' => ['email' => $user['email'] ?? null],
        ]
    );

    unset($user['password']);
    $user['id'] = $userId;
    $user['must_change_password'] = (bool) ((int) ($user['must_change_password'] ?? 0));

    header('Cache-Control: no-store');
    jsonResponse([
        'status' => 'Success',
        'message' => 'Login successful.',
        'csrfToken' => $csrfToken,
        'data' => $user
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Login] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
