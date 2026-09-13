<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';
require_once 'utils/cost_center_management_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requireAnyPermission(
        $conn,
        $actor,
        ['cost_centre.view', 'cost_centre.edit', 'cost_centre.delete'],
        'You do not have permission to browse Cost Centre Administration.'
    );
    if (!userHasAllCostCenterAccess($actor)) {
        throw new RuntimeException('Cost Centre Administration requires All Cost Centres access.', 403);
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    if (!in_array($status, ['all', 'active', 'inactive'], true)) {
        $status = 'all';
    }

    $sql = 'SELECT id, name, normalized_name, is_active, created_at, created_by, updated_at, updated_by
            FROM cost_center_table
            WHERE 1=1';
    $params = [];
    $types = '';

    if ($status === 'active') {
        $sql .= ' AND is_active = 1';
    } elseif ($status === 'inactive') {
        $sql .= ' AND is_active = 0';
    }

    if ($search !== '') {
        $sql .= ' AND name LIKE ?';
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $sql .= ' ORDER BY is_active DESC, name ASC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load cost centres.', 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $summary = ['total' => 0, 'active' => 0, 'inactive' => 0, 'assigned' => 0];
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) ((int) $row['is_active']);
        $row = costCenterManagementDecorate($conn, $row);
        $summary['total']++;
        $summary[$row['is_active'] ? 'active' : 'inactive']++;
        if (($row['usage']['assigned_users'] ?? 0) > 0) {
            $summary['assigned']++;
        }
    }
    unset($row);

    jsonResponse(['status' => 'Success', 'data' => $rows, 'summary' => $summary]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Cost Centre/Manage List] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
