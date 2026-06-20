<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    $requestedId = (int) ($_GET['id'] ?? 0);
    if ($requestedId <= 0) {
        throw new RuntimeException('A valid user ID is required.', 400);
    }

    if ($actor['integrity'] !== 'Admin' && $requestedId !== (int) $actor['id']) {
        throw new RuntimeException('You cannot view this user account.', 403);
    }

    $stmt = $conn->prepare(
        'SELECT a.id, a.fname, a.lname, a.email, a.username, a.integrity, a.staff_id,
                a.must_change_password, s.staff_name AS linked_staff_name,
                a.created_at, a.created_by, a.updated_at, a.updated_by
         FROM admin_table a
         LEFT JOIN staff_table s ON s.staff_id = a.staff_id
         WHERE a.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $requestedId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new RuntimeException('User record not found.', 404);
    }

    $user['must_change_password'] = (bool) ((int) ($user['must_change_password'] ?? 0));

    jsonResponse([
        'status' => 'Success',
        'message' => 'User profile fetched successfully.',
        'data' => $user
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Users/View] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
