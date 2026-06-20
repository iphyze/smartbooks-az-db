<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

function notificationItemFromRow(array $row): array
{
    $metadata = null;
    if (!empty($row['metadata_json'])) {
        $decoded = json_decode((string) $row['metadata_json'], true);
        $metadata = is_array($decoded) ? $decoded : null;
    }

    $actorName = trim(sprintf(
        '%s %s',
        (string) ($row['actor_fname'] ?? ''),
        (string) ($row['actor_lname'] ?? '')
    ));

    return [
        'id' => (int) $row['id'],
        'notification_type' => (string) $row['notification_type'],
        'module' => (string) $row['module'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'priority' => (string) $row['priority'],
        'entity_type' => $row['entity_type'] !== null ? (string) $row['entity_type'] : null,
        'entity_id' => $row['entity_id'] !== null ? (string) $row['entity_id'] : null,
        'action_url' => $row['action_url'] !== null ? (string) $row['action_url'] : null,
        'metadata' => $metadata,
        'seen_at' => $row['seen_at'],
        'read_at' => $row['read_at'],
        'created_at' => (string) $row['created_at'],
        'expires_at' => $row['expires_at'],
        'is_seen' => $row['seen_at'] !== null,
        'is_read' => $row['read_at'] !== null,
        'actor' => $row['actor_user_id'] !== null ? [
            'id' => (int) $row['actor_user_id'],
            'name' => $actorName !== '' ? $actorName : 'Smartbooks user',
        ] : null,
    ];
}

function notificationBaseSelect(): string
{
    return 'SELECT
                n.id, n.recipient_user_id, n.actor_user_id, n.notification_type, n.module,
                n.title, n.message, n.priority, n.entity_type, n.entity_id, n.action_url,
                n.metadata_json, n.seen_at, n.read_at, n.created_at, n.expires_at,
                a.fname AS actor_fname, a.lname AS actor_lname
            FROM notifications n
            LEFT JOIN admin_table a ON a.id = n.actor_user_id';
}

function activeNotificationCondition(): string
{
    return 'n.recipient_user_id = ?
            AND n.dismissed_at IS NULL
            AND (n.expires_at IS NULL OR n.expires_at > NOW())';
}

function notificationCounts(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        'SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread_count,
            SUM(CASE WHEN seen_at IS NULL THEN 1 ELSE 0 END) AS unseen_count
         FROM notifications
         WHERE recipient_user_id = ?
           AND dismissed_at IS NULL
           AND (expires_at IS NULL OR expires_at > NOW())'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'total_count' => (int) ($row['total_count'] ?? 0),
        'unread_count' => (int) ($row['unread_count'] ?? 0),
        'unseen_count' => (int) ($row['unseen_count'] ?? 0),
    ];
}
