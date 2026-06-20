<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(5, min((int) ($_GET['limit'] ?? 20), 50));
    $offset = ($page - 1) * $limit;
    $filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
    $module = strtolower(trim((string) ($_GET['module'] ?? '')));

    if (!in_array($filter, ['all', 'unread', 'read'], true)) {
        throw new RuntimeException('Invalid notification filter.', 400);
    }
    if ($module !== '' && preg_match('/^[a-z0-9_-]{1,60}$/', $module) !== 1) {
        throw new RuntimeException('Invalid notification module.', 400);
    }

    $where = [
        'n.recipient_user_id = ?',
        'n.dismissed_at IS NULL',
        '(n.expires_at IS NULL OR n.expires_at > NOW())',
    ];
    $types = 'i';
    $params = [$userId];

    if ($filter === 'unread') {
        $where[] = 'n.read_at IS NULL';
    } elseif ($filter === 'read') {
        $where[] = 'n.read_at IS NOT NULL';
    }

    if ($module !== '') {
        $where[] = 'n.module = ?';
        $types .= 's';
        $params[] = $module;
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications n WHERE {$whereSql}");
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = notificationBaseSelect() . "\nWHERE {$whereSql}\nORDER BY n.created_at DESC, n.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $selectTypes = $types . 'ii';
    $selectParams = [...$params, $limit, $offset];
    $stmt->bind_param($selectTypes, ...$selectParams);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = notificationItemFromRow($row);
    }
    $stmt->close();

    $counts = notificationCounts($conn, $userId);
    jsonResponse([
        'status' => 'Success',
        'data' => $items,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
            'has_more' => ($offset + count($items)) < $total,
            ...$counts,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Notifications/List] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
