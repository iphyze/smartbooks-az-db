<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function unauthenticated(string $message = 'Authentication required.'): never
{
    clearAuthCookie();
    jsonResponse([
        'status' => 'Failed',
        'message' => $message
    ], 401);
}

function authenticateUser(): array
{
    global $conn;
    static $authenticatedUser = null;

    if (is_array($authenticatedUser)) {
        return $authenticatedUser;
    }

    $token = (string) ($_COOKIE[authCookieName()] ?? '');
    if ($token === '') {
        unauthenticated();
    }

    try {
        $decoded = (array) JWT::decode($token, new Key(jwtSecret(), 'HS256'));

        $userId = (int) ($decoded['sub'] ?? 0);
        $jti = (string) ($decoded['jti'] ?? '');
        $issuer = (string) ($decoded['iss'] ?? '');
        $audience = (string) ($decoded['aud'] ?? '');

        if (
            $userId <= 0 ||
            $jti === '' ||
            !hash_equals(jwtIssuer(), $issuer) ||
            !hash_equals(jwtAudience(), $audience)
        ) {
            unauthenticated('Invalid session.');
        }

        $sessionHash = sessionTokenHash($jti);
        $sessionStmt = $conn->prepare(
            'SELECT id
             FROM auth_sessions
             WHERE user_id = ?
               AND jti_hash = ?
               AND revoked_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $sessionStmt->bind_param('is', $userId, $sessionHash);
        $sessionStmt->execute();
        $activeSession = $sessionStmt->get_result()->fetch_assoc();
        $sessionStmt->close();

        if (!$activeSession) {
            unauthenticated('Session expired. Please log in again.');
        }

        $userStmt = $conn->prepare(
            'SELECT id, fname, lname, username, email, integrity, staff_id, must_change_password, created_by, updated_by
             FROM admin_table
             WHERE id = ?
             LIMIT 1'
        );
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$user) {
            unauthenticated('Account is no longer available.');
        }

        validateCsrfToken();

        $user['id'] = (int) $user['id'];
        $user['jti'] = $jti;
        $user['must_change_password'] = (bool) ((int) ($user['must_change_password'] ?? 0));

        $user['staff_id'] = isset($user['staff_id']) && $user['staff_id'] !== null ? (int) $user['staff_id'] : null;
        $authenticatedUser = $user;

        return $authenticatedUser;
    } catch (Throwable $exception) {
        error_log('[Smartbooks Auth] ' . $exception->getMessage());
        unauthenticated('Invalid or expired session.');
    }
}
