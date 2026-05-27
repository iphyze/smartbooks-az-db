<?php
declare(strict_types=1);

use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->safeLoad();

function envString(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : (string) $value;
}

function envBool(string $key, bool $default = false): bool
{
    $value = strtolower(envString($key, $default ? 'true' : 'false'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function publicErrorMessage(Throwable $exception): string
{
    $code = (int) $exception->getCode();
    return ($code >= 400 && $code < 500)
        ? $exception->getMessage()
        : 'An internal server error occurred.';
}

function publicErrorStatus(Throwable $exception): int
{
    $code = (int) $exception->getCode();
    return ($code >= 400 && $code <= 599) ? $code : 500;
}

function allowedFrontendOrigins(): array
{
    $configured = array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', envString('FRONTEND_ORIGINS', 'http://localhost:5173'))
    ));

    return array_values(array_unique($configured));
}

function requestOrigin(): string
{
    return rtrim(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
}

function isAllowedFrontendOrigin(string $origin): bool
{
    return $origin !== '' && in_array($origin, allowedFrontendOrigins(), true);
}

function isStateChangingRequest(): bool
{
    return in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function enforceTrustedOrigin(): void
{
    if (!isStateChangingRequest() || !envBool('REQUIRE_ORIGIN_CHECK', true)) {
        return;
    }

    $origin = requestOrigin();
    if (isAllowedFrontendOrigin($origin)) {
        return;
    }

    // Some browsers or privacy tooling may omit Origin; allow an exact trusted Referer fallback.
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $refererOrigin = parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST);
        $port = parse_url($referer, PHP_URL_PORT);
        if ($port) {
            $refererOrigin .= ':' . $port;
        }
        if (isAllowedFrontendOrigin($refererOrigin)) {
            return;
        }
    }

    jsonResponse([
        'status' => 'Failed',
        'message' => 'Request origin is not allowed.'
    ], 403);
}

function applyApiSecurityHeaders(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if (envBool('HTTPS_ONLY', envString('APP_ENV', 'production') === 'production')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $origin = requestOrigin();
    if ($origin !== '' && isAllowedFrontendOrigin($origin)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Max-Age: 600');
        header('Vary: Origin');
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        if ($origin === '' || !isAllowedFrontendOrigin($origin)) {
            jsonResponse([
                'status' => 'Failed',
                'message' => 'CORS origin is not allowed.'
            ], 403);
        }

        http_response_code(204);
        exit;
    }
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('[Smartbooks API] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
});

applyApiSecurityHeaders();
