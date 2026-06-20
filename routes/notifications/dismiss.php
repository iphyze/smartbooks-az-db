<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    $payload = json_decode(file_get_contents('php://input'), true);
    $notificationId = (int) ($payload['id'] ?? 0);
    if ($notificationId <= 0) {
        throw new RuntimeException('A valid notification ID is required.', 400);
    }

    $userId = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE notifications
         SET seen_at = COALESCE(seen_at, NOW()), dismissed_at = NOW()
         WHERE id = ? AND recipient_user_id = ? AND dismissed_at IS NULL'
    );
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        throw new RuntimeException('Notification not found.', 404);
    }

    jsonResponse([
        'status' => 'Success',
        'message' => 'Notification dismissed.',
        'data' => ['id' => $notificationId, 'counts' => notificationCounts($conn, $userId)],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Notifications/Dismiss] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
