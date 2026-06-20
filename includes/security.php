<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function authCookieName(): string
{
    return envString('AUTH_COOKIE_NAME', 'smartbooks_access_token');
}

function csrfCookieName(): string
{
    return envString('CSRF_COOKIE_NAME', 'smartbooks_csrf_token');
}

function cookieSecure(): bool
{
    return envBool('COOKIE_SECURE', envString('APP_ENV', 'production') === 'production');
}

function cookieSameSite(): string
{
    $sameSite = ucfirst(strtolower(envString('COOKIE_SAMESITE', 'Lax')));
    return in_array($sameSite, ['Strict', 'Lax', 'None'], true) ? $sameSite : 'Lax';
}

function cookieOptions(int $expires, bool $httpOnly): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => cookieSecure(),
        'httponly' => $httpOnly,
        'samesite' => cookieSameSite(),
    ];
}

function issueCsrfCookie(bool $forceRotate = false): string
{
    $cookieName = csrfCookieName();
    $existing = (string) ($_COOKIE[$cookieName] ?? '');

    if (!$forceRotate && preg_match('/^[a-f0-9]{64}$/', $existing) === 1) {
        return $existing;
    }

    $token = bin2hex(random_bytes(32));
    // Keep the CSRF companion cookie available for the lifetime of the persistent login.
    // It cannot authenticate a request on its own and is rotated on each successful login.
    setcookie($cookieName, $token, cookieOptions(time() + jwtTtlSeconds(), false));
    $_COOKIE[$cookieName] = $token;
    return $token;
}

function clearCsrfCookie(): void
{
    setcookie(csrfCookieName(), '', cookieOptions(time() - 3600, false));
    unset($_COOKIE[csrfCookieName()]);
}

function validateCsrfToken(): void
{
    if (!isStateChangingRequest()) {
        return;
    }

    enforceTrustedOrigin();

    $cookieToken = (string) ($_COOKIE[csrfCookieName()] ?? '');
    $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Invalid CSRF token.'
        ], 419);
    }
}

function issueAuthCookie(string $jwt, int $expiresAt): void
{
    setcookie(authCookieName(), $jwt, cookieOptions($expiresAt, true));
}

function clearAuthCookie(): void
{
    setcookie(authCookieName(), '', cookieOptions(time() - 3600, true));
    unset($_COOKIE[authCookieName()]);
}

function jwtSecret(): string
{
    $secret = envString('JWT_SECRET');

    if (strlen($secret) < 32 || str_contains(strtolower($secret), 'default')) {
        throw new RuntimeException('JWT_SECRET is not securely configured.', 500);
    }

    return $secret;
}

function jwtIssuer(): string
{
    return envString('JWT_ISSUER', 'smartbooks-api');
}

function jwtAudience(): string
{
    return envString('JWT_AUDIENCE', 'smartbooks-web');
}

function jwtTtlSeconds(): int
{
    // Smartbooks uses an absolute persistent login window.  Seven days is the
    // application minimum requested for normal signed-in users; a deployment
    // may extend this up to 30 days through configuration.  Logout and
    // administrative session revocation still terminate access immediately.
    $days = (int) envString('AUTH_SESSION_DAYS', '7');
    $days = max(7, min($days, 30));

    return $days * 86400;
}

function primaryAdminEmail(): string
{
    return strtolower(trim(envString('PRIMARY_ADMIN_EMAIL', 'admin@a-zconsultancyltd.com')));
}

function isPrimaryAdminEmail(string $email): bool
{
    return hash_equals(primaryAdminEmail(), strtolower(trim($email)));
}

function defaultUserPassword(?int $year = null): string
{
    $resolvedYear = $year ?? (int) date('Y');
    return 'Consultancy@' . $resolvedYear;
}

function isTemporaryUserPassword(string $password): bool
{
    return preg_match('/^Consultancy@\d{4}$/i', $password) === 1;
}

function sessionTokenHash(string $jti): string
{
    return hash('sha256', $jti);
}

function createAuthSession(mysqli $conn, int $userId, string $jti, int $expiresAt): void
{
    $hash = sessionTokenHash($jti);
    $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $expires = date('Y-m-d H:i:s', $expiresAt);

    $stmt = $conn->prepare(
        'INSERT INTO auth_sessions (user_id, jti_hash, ip_hash, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to create session.', 500);
    }
    $stmt->bind_param('issss', $userId, $hash, $ipHash, $userAgent, $expires);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to create session.', 500);
    }
    $stmt->close();
}

function revokeAuthSession(mysqli $conn, int $userId, string $jti): void
{
    $hash = sessionTokenHash($jti);
    $stmt = $conn->prepare(
        'UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND jti_hash = ? AND revoked_at IS NULL'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to end session.', 500);
    }
    $stmt->bind_param('is', $userId, $hash);
    $stmt->execute();
    $stmt->close();
}

function revokeAllUserSessions(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare(
        'UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND revoked_at IS NULL'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to revoke sessions.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function loginAttemptKey(string $email): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', strtolower(trim($email)) . '|' . $ip);
}

function assertLoginNotRateLimited(mysqli $conn, string $email): void
{
    $key = loginAttemptKey($email);
    $windowMinutes = max(1, min((int) envString('LOGIN_WINDOW_MINUTES', '15'), 60));
    $maxAttempts = max(3, min((int) envString('LOGIN_MAX_ATTEMPTS', '5'), 20));

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS attempts
         FROM auth_login_attempts
         WHERE attempt_key = ?
           AND was_successful = 0
           AND attempted_at >= DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate login attempt.', 500);
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $attempts = (int) ($stmt->get_result()->fetch_assoc()['attempts'] ?? 0);
    $stmt->close();

    if ($attempts >= $maxAttempts) {
        throw new RuntimeException('Too many login attempts. Please try again later.', 429);
    }
}

function recordLoginAttempt(mysqli $conn, string $email, bool $wasSuccessful): void
{
    $key = loginAttemptKey($email);
    $success = $wasSuccessful ? 1 : 0;

    $stmt = $conn->prepare(
        'INSERT INTO auth_login_attempts (attempt_key, was_successful) VALUES (?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('si', $key, $success);
        $stmt->execute();
        $stmt->close();
    }
}
