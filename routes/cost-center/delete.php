<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';
require_once 'utils/cost_center_management_helpers.php';
require_once 'utils/activity_log_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $actor = authenticateUser();
    requirePermission($conn, $actor, 'cost_centre.delete', 'You do not have permission to delete or deactivate cost centres.');
    if (!userHasAllCostCenterAccess($actor)) {
        throw new RuntimeException('Cost Centre Administration requires All Cost Centres access.', 403);
    }

    $id = (int) ($_GET['id'] ?? 0);
    $actorEmail = trim((string) ($actor['email'] ?? 'system')) ?: 'system';

    $conn->begin_transaction();
    $before = costCenterManagementFind($conn, $id, true);
    $usage = costCenterManagementUsage($conn, (string) $before['normalized_name'], $id);

    if ($usage['can_hard_delete']) {
        $stmt = $conn->prepare('DELETE FROM cost_center_table WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to delete cost centre.', 500);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Cost centre deleted permanently.';
        $actionType = 'delete';
    } else {
        $stmt = $conn->prepare(
            'UPDATE cost_center_table
             SET is_active = 0, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to deactivate cost centre.', 500);
        }
        $stmt->bind_param('si', $actorEmail, $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Cost centre is already in use, so it was deactivated instead of deleted.';
        $actionType = 'deactivate';
    }

    logActivity(
        $conn,
        $actor,
        $actionType === 'delete' ? "Deleted cost centre {$before['name']}" : "Deactivated cost centre {$before['name']}",
        'Users & Access',
        $actionType,
        [
            'entity_type' => 'cost_center',
            'entity_id' => (string) $id,
            'description' => $message,
            'before' => $before,
            'metadata' => ['usage' => $usage],
            'severity' => $actionType === 'delete' ? 'warning' : 'info',
        ],
        true
    );

    $conn->commit();
    jsonResponse([
        'status' => 'Success',
        'message' => $message,
        'mode' => $actionType === 'delete' ? 'deleted' : 'deactivated',
        'usage' => $usage,
    ]);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('[Smartbooks Cost Centre/Delete] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
