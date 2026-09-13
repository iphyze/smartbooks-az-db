<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requirePermission($conn, $user, 'notification.view', 'You do not have permission to view notifications.');
    requirePermission($conn, $user, 'notification.mark_read', 'You do not have permission to mark notifications as read.');
    $userId = (int) $user['id'];
    $visibility = notificationVisibilityCondition($user);
    $stmt = $conn->prepare(
        "UPDATE notifications n
         SET seen_at = COALESCE(seen_at, NOW()), read_at = COALESCE(read_at, NOW())
         WHERE n.recipient_user_id = ?
           AND n.dismissed_at IS NULL
           AND n.read_at IS NULL
           AND (n.expires_at IS NULL OR n.expires_at > NOW())
           AND {$visibility}"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    jsonResponse([
        'status' => 'Success',
        'message' => $updated > 0 ? 'All notifications marked as read.' : 'No unread notifications.',
        'data' => ['updated' => $updated, 'counts' => notificationCounts($conn, $user)],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Notifications/MarkAllRead] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
