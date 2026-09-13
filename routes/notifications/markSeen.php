<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requirePermission($conn, $user, 'notification.view', 'You do not have permission to view notifications.');
    requirePermission($conn, $user, 'notification.mark_read', 'You do not have permission to mark notifications as seen.');
    $payload = json_decode(file_get_contents('php://input'), true);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', is_array($payload['ids'] ?? null) ? $payload['ids'] : []),
        static fn (int $id): bool => $id > 0
    )));
    $ids = array_slice($ids, 0, 50);
    $userId = (int) $user['id'];
    $visibility = notificationVisibilityCondition($user);
    $updated = 0;

    if ($ids !== []) {
        $stmt = $conn->prepare(
            "UPDATE notifications n
             SET seen_at = COALESCE(seen_at, NOW())
             WHERE n.id = ? AND n.recipient_user_id = ? AND n.dismissed_at IS NULL AND {$visibility}"
        );
        foreach ($ids as $id) {
            $stmt->bind_param('ii', $id, $userId);
            $stmt->execute();
            $updated += max(0, $stmt->affected_rows);
        }
        $stmt->close();
    }

    jsonResponse([
        'status' => 'Success',
        'data' => ['updated' => $updated, 'counts' => notificationCounts($conn, $user)],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Notifications/MarkSeen] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
