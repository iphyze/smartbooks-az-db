<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $limit = max(1, min((int) ($_GET['limit'] ?? 8), 12));

    $sql = notificationBaseSelect() . "\nWHERE " . activeNotificationCondition() . "\nORDER BY n.created_at DESC, n.id DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = notificationItemFromRow($row);
    }
    $stmt->close();

    jsonResponse([
        'status' => 'Success',
        'data' => [
            'items' => $items,
            'counts' => notificationCounts($conn, $userId),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Notifications/Summary] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
