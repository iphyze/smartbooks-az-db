<?php
declare(strict_types=1);

require_once __DIR__ . '/cost_center_access_helpers.php';

/**
 * Smartbooks notification helpers.
 * Notification writes are intentionally non-blocking: a notification failure must
 * never roll back or interrupt the accounting operation that generated it.
 */


function notificationEntityCostCenter(
    mysqli $conn,
    ?string $entityType,
    int|string|null $entityId,
    array $metadata = []
): ?string {
    $metadataCostCenter = trim((string) ($metadata['cost_center'] ?? $metadata['cost_centre'] ?? ''));
    if ($metadataCostCenter !== '') {
        return $metadataCostCenter;
    }

    $type = strtolower(trim((string) $entityType));
    $id = trim((string) ($entityId ?? ''));
    if ($type === '' || $id === '') {
        return null;
    }

    try {
        if ($type === 'journal' && ctype_digit($id)) {
            $journalId = (int) $id;
            $stmt = $conn->prepare('SELECT cost_center FROM journal_table WHERE journal_id = ? LIMIT 1');
            $stmt->bind_param('i', $journalId);
        } elseif ($type === 'invoice') {
            $stmt = $conn->prepare('SELECT cost_center FROM invoice_table WHERE invoice_number = ? LIMIT 1');
            $stmt->bind_param('s', $id);
        } elseif (in_array($type, ['bank_recon', 'bank_reconciliation'], true) && ctype_digit($id)) {
            $reconId = (int) $id;
            $stmt = $conn->prepare('SELECT cost_center FROM bank_recons WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $reconId);
        } else {
            return null;
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $costCenter = trim((string) ($row['cost_center'] ?? ''));
        return $costCenter !== '' ? $costCenter : null;
    } catch (Throwable $exception) {
        error_log('[Smartbooks Notifications/CostCentreResolve] ' . $exception->getMessage());
        return null;
    }
}

function notificationRecipientCanReceive(
    mysqli $conn,
    int $recipientUserId,
    ?string $entityType,
    int|string|null $entityId,
    array $metadata = []
): bool {
    try {
        $stmt = $conn->prepare('SELECT id, cost_center_access_mode FROM admin_table WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $recipientUserId);
        $stmt->execute();
        $recipient = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$recipient) {
            return false;
        }

        if (userHasAllCostCenterAccess($recipient)) {
            return true;
        }

        $costCenter = notificationEntityCostCenter($conn, $entityType, $entityId, $metadata);
        if ($costCenter === null) {
            // Personal/security notifications that are not tied to accounting data remain visible.
            $personalTypes = ['user', 'profile', 'authentication'];
            $normalizedEntityType = strtolower(trim((string) $entityType));
            return $normalizedEntityType === '' || in_array($normalizedEntityType, $personalTypes, true);
        }

        return userCanAccessCostCenter($conn, $recipient, $costCenter);
    } catch (Throwable $exception) {
        error_log('[Smartbooks Notifications/RecipientScope] ' . $exception->getMessage());
        return false;
    }
}

function notificationText(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

function notificationPriority(string $priority): string
{
    return in_array($priority, ['info', 'warning', 'critical'], true)
        ? $priority
        : 'info';
}

function createNotification(
    mysqli $conn,
    int $recipientUserId,
    string $notificationType,
    string $module,
    string $title,
    string $message,
    string $priority = 'info',
    ?string $entityType = null,
    int|string|null $entityId = null,
    ?string $actionUrl = null,
    array $metadata = [],
    ?int $actorUserId = null,
    ?string $expiresAt = null
): bool {
    if ($recipientUserId <= 0) {
        return false;
    }

    try {
        $recipientExists = $conn->prepare('SELECT id FROM admin_table WHERE id = ? LIMIT 1');
        if (!$recipientExists) {
            return false;
        }
        $recipientExists->bind_param('i', $recipientUserId);
        $recipientExists->execute();
        $recipient = $recipientExists->get_result()->fetch_assoc();
        $recipientExists->close();

        if (!$recipient) {
            return false;
        }

        if (!notificationRecipientCanReceive($conn, $recipientUserId, $entityType, $entityId, $metadata)) {
            return false;
        }

        $resolvedActorId = ($actorUserId !== null && $actorUserId > 0) ? $actorUserId : null;
        $resolvedType = notificationText($notificationType !== '' ? $notificationType : 'general', 80);
        $resolvedModule = notificationText($module !== '' ? $module : 'general', 60);
        $resolvedTitle = notificationText($title, 160);
        if ($resolvedTitle === '') {
            $resolvedTitle = 'Smartbooks notification';
        }
        $resolvedMessage = notificationText($message, 600);
        if ($resolvedMessage === '') {
            $resolvedMessage = 'New activity is available in Smartbooks.';
        }
        $resolvedPriority = notificationPriority($priority);
        $resolvedEntityType = $entityType !== null ? notificationText($entityType, 80) : null;
        $resolvedEntityId = $entityId !== null ? notificationText((string) $entityId, 100) : null;
        $resolvedActionUrl = ($actionUrl !== null && str_starts_with($actionUrl, '/'))
            ? notificationText($actionUrl, 255)
            : null;
        $resolvedMetadata = null;
        if ($metadata !== []) {
            $encodedMetadata = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $resolvedMetadata = is_string($encodedMetadata) ? $encodedMetadata : null;
        }
        $resolvedExpiresAt = ($expiresAt !== null && $expiresAt !== '') ? $expiresAt : null;

        $stmt = $conn->prepare(
            'INSERT INTO notifications
                (recipient_user_id, actor_user_id, notification_type, module, title, message,
                 priority, entity_type, entity_id, action_url, metadata_json, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'iissssssssss',
            $recipientUserId,
            $resolvedActorId,
            $resolvedType,
            $resolvedModule,
            $resolvedTitle,
            $resolvedMessage,
            $resolvedPriority,
            $resolvedEntityType,
            $resolvedEntityId,
            $resolvedActionUrl,
            $resolvedMetadata,
            $resolvedExpiresAt
        );
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    } catch (Throwable $exception) {
        error_log('[Smartbooks Notifications/Create] ' . $exception->getMessage());
        return false;
    }
}

function notifyUser(
    mysqli $conn,
    int $recipientUserId,
    string $notificationType,
    string $module,
    string $title,
    string $message,
    string $priority = 'info',
    ?string $entityType = null,
    int|string|null $entityId = null,
    ?string $actionUrl = null,
    array $metadata = [],
    ?int $actorUserId = null,
    ?string $expiresAt = null
): bool {
    return createNotification(
        $conn,
        $recipientUserId,
        $notificationType,
        $module,
        $title,
        $message,
        $priority,
        $entityType,
        $entityId,
        $actionUrl,
        $metadata,
        $actorUserId,
        $expiresAt
    );
}

function notificationRecipientIdsByRoles(
    mysqli $conn,
    array $roles,
    array $excludeUserIds = []
): array {
    $validRoles = array_values(array_intersect(
        array_map(static fn ($role): string => trim((string) $role), $roles),
        defined('SMARTBOOKS_ALLOWED_ROLES') ? SMARTBOOKS_ALLOWED_ROLES : ['Admin', 'Controller', 'Timesheet']
    ));

    if ($validRoles === []) {
        return [];
    }

    $excluded = array_fill_keys(array_map('intval', $excludeUserIds), true);
    $result = $conn->query('SELECT id, integrity FROM admin_table');
    $recipientIds = [];

    while ($row = $result->fetch_assoc()) {
        $userId = (int) ($row['id'] ?? 0);
        $role = trim((string) ($row['integrity'] ?? ''));
        if ($userId > 0 && in_array($role, $validRoles, true) && !isset($excluded[$userId])) {
            $recipientIds[] = $userId;
        }
    }

    return array_values(array_unique($recipientIds));
}

function notifyUsersByRoles(
    mysqli $conn,
    array $roles,
    string $notificationType,
    string $module,
    string $title,
    string $message,
    string $priority = 'info',
    ?string $entityType = null,
    int|string|null $entityId = null,
    ?string $actionUrl = null,
    array $metadata = [],
    ?int $actorUserId = null,
    array $excludeUserIds = [],
    ?string $expiresAt = null
): int {
    try {
        $recipientIds = notificationRecipientIdsByRoles($conn, $roles, $excludeUserIds);
        $created = 0;

        foreach ($recipientIds as $recipientUserId) {
            if (createNotification(
                $conn,
                $recipientUserId,
                $notificationType,
                $module,
                $title,
                $message,
                $priority,
                $entityType,
                $entityId,
                $actionUrl,
                $metadata,
                $actorUserId,
                $expiresAt
            )) {
                $created++;
            }
        }

        return $created;
    } catch (Throwable $exception) {
        error_log('[Smartbooks Notifications/Broadcast] ' . $exception->getMessage());
        return 0;
    }
}

function notifyAccountingUsers(
    mysqli $conn,
    string $notificationType,
    string $module,
    string $title,
    string $message,
    string $priority = 'info',
    ?string $entityType = null,
    int|string|null $entityId = null,
    ?string $actionUrl = null,
    array $metadata = [],
    ?int $actorUserId = null,
    bool $excludeActor = true,
    ?string $expiresAt = null
): int {
    $roles = defined('SMARTBOOKS_ROLE_ADMIN')
        ? [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]
        : ['Admin', 'Controller'];

    return notifyUsersByRoles(
        $conn,
        $roles,
        $notificationType,
        $module,
        $title,
        $message,
        $priority,
        $entityType,
        $entityId,
        $actionUrl,
        $metadata,
        $actorUserId,
        ($excludeActor && $actorUserId) ? [$actorUserId] : [],
        $expiresAt
    );
}
