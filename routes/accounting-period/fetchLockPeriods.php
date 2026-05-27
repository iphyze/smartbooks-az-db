<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can view accounting periods.');

    $search = trim((string) ($_GET['search'] ?? ''));
    $sql = 'SELECT id, start_date, end_date, is_locked, is_active, lock_reason, created_by, created_at, updated_by, updated_at FROM accounting_periods';
    $params = [];
    $types = '';
    if ($search !== '') {
        $sql .= ' WHERE start_date LIKE ? OR end_date LIKE ? OR lock_reason LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like];
        $types = 'sss';
    }
    $sql .= ' ORDER BY end_date DESC, id DESC';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $normalised = array_map(static function (array $period): array {
        $period['id'] = (int) $period['id'];
        $period['is_locked'] = (string) $period['is_locked'] === '1';
        $period['is_active'] = (string) $period['is_active'] === '1';
        return $period;
    }, $periods);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Accounting periods fetched successfully.',
        'data' => $normalised,
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks AccountingPeriod/List] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
