<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE notifications
         SET seen_at = COALESCE(seen_at, NOW()), read_at = COALESCE(read_at, NOW())
         WHERE recipient_user_id = ?
           AND dismissed_at IS NULL
           AND read_at IS NULL
           AND (expires_at IS NULL OR expires_at > NOW())'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    jsonResponse([
        'status' => 'Success',
        'message' => $updated > 0 ? 'All notifications marked as read.' : 'No unread notifications.',
        'data' => ['updated' => $updated, 'counts' => notificationCounts($conn, $userId)],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Notifications/MarkAllRead] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
