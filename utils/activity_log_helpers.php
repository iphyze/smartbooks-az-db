<?php
declare(strict_types=1);

/**
 * Returns the best available client IP without trusting arbitrary forwarding chains.
 * The first X-Forwarded-For entry is used only when it is present and valid.
 */
function activityClientIp(): ?string
{
    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $candidate = trim(explode(',', $forwarded)[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null;
}

function activityUserAgent(): ?string
{
    $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $agent === '' ? null : substr($agent, 0, 500);
}

function activityRequestPath(): ?string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return is_string($path) && $path !== '' ? substr($path, 0, 255) : null;
}

function activityJson(?array $value): ?string
{
    if ($value === null || $value === []) {
        return null;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $encoded === false ? null : $encoded;
}

/**
 * Writes a structured audit event while retaining compatibility with the existing
 * logs table and its historical user-facing action text.
 *
 * Logging is deliberately non-blocking by default so an audit write cannot corrupt
 * the accounting transaction that triggered it. Set $throwOnFailure only when the
 * caller explicitly needs logging to participate in its transaction.
 */
function logActivity(
    mysqli $conn,
    array $actor,
    string $action,
    string $module,
    string $actionType,
    array $options = [],
    bool $throwOnFailure = false
): ?int {
    $userId = (int) ($actor['id'] ?? $actor['userId'] ?? 0);
    $createdBy = trim((string) (
        $options['created_by']
        ?? $actor['email']
        ?? trim(((string) ($actor['fname'] ?? '')) . ' ' . ((string) ($actor['lname'] ?? '')))
        ?? 'System'
    ));
    if ($createdBy === '') {
        $createdBy = 'System';
    }

    $description = trim((string) ($options['description'] ?? $action));
    $entityType = trim((string) ($options['entity_type'] ?? '')) ?: null;
    $entityId = trim((string) ($options['entity_id'] ?? '')) ?: null;
    $severity = strtolower(trim((string) ($options['severity'] ?? 'info')));
    if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
        $severity = 'info';
    }

    $metadataJson = activityJson(is_array($options['metadata'] ?? null) ? $options['metadata'] : null);
    $beforeJson = activityJson(is_array($options['before'] ?? null) ? $options['before'] : null);
    $afterJson = activityJson(is_array($options['after'] ?? null) ? $options['after'] : null);
    $ipAddress = activityClientIp();
    $userAgent = activityUserAgent();
    $requestMethod = substr(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'SYSTEM')), 0, 10);
    $requestPath = activityRequestPath();

    try {
        $stmt = $conn->prepare(
            'INSERT INTO logs (
                userId, action, created_by, module, action_type, entity_type, entity_id,
                description, metadata_json, before_json, after_json, ip_address,
                user_agent, request_method, request_path, severity
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare activity log: ' . $conn->error, 500);
        }

        $stmt->bind_param(
            'isssssssssssssss',
            $userId,
            $action,
            $createdBy,
            $module,
            $actionType,
            $entityType,
            $entityId,
            $description,
            $metadataJson,
            $beforeJson,
            $afterJson,
            $ipAddress,
            $userAgent,
            $requestMethod,
            $requestPath,
            $severity
        );
        $stmt->execute();
        $insertId = (int) $stmt->insert_id;
        $stmt->close();
        return $insertId;
    } catch (Throwable $exception) {
        error_log('[Smartbooks Activity Log] ' . $exception->getMessage());
        if ($throwOnFailure) {
            throw $exception;
        }
        return null;
    }
}
