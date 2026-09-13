<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireAnyPermission(
        $conn,
        $user,
        ['cost_centre.view', 'journal.create', 'journal.edit', 'journal.import', 'invoice.create', 'invoice.edit', 'bank_reconciliation.create', 'bank_reconciliation.edit', 'user.create', 'user.edit'],
        'You do not have permission to load cost-centre reference data.'
    );

    if (userHasAllCostCenterAccess($user)) {
        $stmt = $conn->prepare(
            'SELECT id, name, is_active, created_at, created_by, updated_at, updated_by
             FROM cost_center_table
             WHERE is_active = 1
             ORDER BY name ASC'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to load cost centres.', 500);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $userId = (int) $user['id'];
        $stmt = $conn->prepare(
            'SELECT c.id, c.name, c.is_active, c.created_at, c.created_by, c.updated_at, c.updated_by
             FROM user_cost_center_access ucca
             INNER JOIN cost_center_table c ON c.id = ucca.cost_center_id
             WHERE ucca.user_id = ? AND c.is_active = 1
             ORDER BY c.name ASC'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to load cost centres.', 500);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) ((int) $row['is_active']);
    }
    unset($row);

    jsonResponse([
        'status' => 'Success',
        'data' => $rows,
        'access_mode' => normalizeCostCenterAccessMode($user['cost_center_access_mode'] ?? 'all'),
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Cost Centre/List] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
