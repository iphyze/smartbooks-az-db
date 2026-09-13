<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';


function notificationVisibilityCondition(array $user): string
{
    if (userHasAllCostCenterAccess($user)) {
        return '1=1';
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return '1=0';
    }

    // Restricted users may keep personal/security notices. Accounting notices
    // are visible only when their entity resolves to an assigned cost centre.
    return "(
        (LOWER(COALESCE(n.entity_type, '')) IN ('', 'user', 'profile', 'authentication')
            AND LOWER(COALESCE(n.module, '')) NOT IN ('accounting_period', 'fx_revaluation'))
        OR (
            LOWER(COALESCE(n.entity_type, '')) = 'journal'
            AND EXISTS (
                SELECT 1
                FROM journal_table sb_nj
                INNER JOIN user_cost_center_access sb_nucca ON sb_nucca.user_id = {$userId}
                INNER JOIN cost_center_table sb_ncc
                    ON sb_ncc.id = sb_nucca.cost_center_id
                   AND sb_ncc.is_active = 1
                   AND sb_ncc.normalized_name = LOWER(TRIM(sb_nj.cost_center))
                WHERE sb_nj.journal_id = CAST(n.entity_id AS UNSIGNED)
            )
        )
        OR (
            LOWER(COALESCE(n.entity_type, '')) = 'invoice'
            AND EXISTS (
                SELECT 1
                FROM invoice_table sb_ni
                INNER JOIN user_cost_center_access sb_nucca2 ON sb_nucca2.user_id = {$userId}
                INNER JOIN cost_center_table sb_ncc2
                    ON sb_ncc2.id = sb_nucca2.cost_center_id
                   AND sb_ncc2.is_active = 1
                   AND sb_ncc2.normalized_name = LOWER(TRIM(sb_ni.cost_center))
                WHERE sb_ni.invoice_number = n.entity_id
            )
        )
        OR (
            LOWER(COALESCE(n.entity_type, '')) IN ('bank_recon', 'bank_reconciliation')
            AND EXISTS (
                SELECT 1
                FROM bank_recons sb_nbr
                INNER JOIN user_cost_center_access sb_nucca3 ON sb_nucca3.user_id = {$userId}
                INNER JOIN cost_center_table sb_ncc3
                    ON sb_ncc3.id = sb_nucca3.cost_center_id
                   AND sb_ncc3.is_active = 1
                   AND sb_ncc3.normalized_name = LOWER(TRIM(sb_nbr.cost_center))
                WHERE sb_nbr.id = CAST(n.entity_id AS UNSIGNED)
            )
        )
    )";
}

function notificationScopedActiveCondition(array $user): string
{
    return activeNotificationCondition() . ' AND ' . notificationVisibilityCondition($user);
}

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

function notificationCounts(mysqli $conn, array $user): array
{
    $userId = (int) ($user['id'] ?? 0);
    $visibility = notificationVisibilityCondition($user);
    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN n.read_at IS NULL THEN 1 ELSE 0 END) AS unread_count,
            SUM(CASE WHEN n.seen_at IS NULL THEN 1 ELSE 0 END) AS unseen_count
         FROM notifications n
         WHERE n.recipient_user_id = ?
           AND n.dismissed_at IS NULL
           AND (n.expires_at IS NULL OR n.expires_at > NOW())
           AND {$visibility}"
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
