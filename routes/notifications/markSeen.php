<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    $payload = json_decode(file_get_contents('php://input'), true);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', is_array($payload['ids'] ?? null) ? $payload['ids'] : []),
        static fn (int $id): bool => $id > 0
    )));
    $ids = array_slice($ids, 0, 50);
    $userId = (int) $user['id'];
    $updated = 0;

    if ($ids !== []) {
        $stmt = $conn->prepare(
            'UPDATE notifications
             SET seen_at = COALESCE(seen_at, NOW())
             WHERE id = ? AND recipient_user_id = ? AND dismissed_at IS NULL'
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
        'data' => ['updated' => $updated, 'counts' => notificationCounts($conn, $userId)],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Notifications/MarkSeen] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
