<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';
require_once 'utils/text_normalization.php';
require_once 'utils/cost_center_management_helpers.php';
require_once 'utils/activity_log_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $actor = authenticateUser();
    requirePermission($conn, $actor, 'cost_centre.edit', 'You do not have permission to update cost centres.');
    if (!userHasAllCostCenterAccess($actor)) {
        throw new RuntimeException('Cost Centre Administration requires All Cost Centres access.', 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $id = (int) ($data['id'] ?? 0);
    $name = smartbooksCanonicalName($data['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Cost centre name is required.', 400);
    }
    if (mb_strlen($name) > 255) {
        throw new RuntimeException('Cost centre name cannot exceed 255 characters.', 400);
    }
    $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
    $normalized = normalizeCostCenterName($name);
    $actorEmail = trim((string) ($actor['email'] ?? 'system')) ?: 'system';

    $conn->begin_transaction();
    $before = costCenterManagementFind($conn, $id, true);

    $dupe = $conn->prepare('SELECT id FROM cost_center_table WHERE normalized_name = ? AND id <> ? LIMIT 1');
    if (!$dupe) {
        throw new RuntimeException('Unable to validate cost centre name.', 500);
    }
    $dupe->bind_param('si', $normalized, $id);
    $dupe->execute();
    $duplicate = $dupe->get_result()->fetch_assoc();
    $dupe->close();
    if ($duplicate) {
        throw new RuntimeException('A cost centre with this name already exists.', 409);
    }

    $oldNormalized = (string) $before['normalized_name'];
    if ($normalized !== $oldNormalized || $name !== (string) $before['name']) {
        costCenterManagementRenameReferences($conn, $oldNormalized, $name);
    }

    $activeValue = $isActive ? 1 : 0;
    $stmt = $conn->prepare(
        'UPDATE cost_center_table
         SET name = ?, normalized_name = ?, is_active = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to update cost centre.', 500);
    }
    $stmt->bind_param('ssisi', $name, $normalized, $activeValue, $actorEmail, $id);
    $stmt->execute();
    $stmt->close();

    $after = costCenterManagementFind($conn, $id);
    logActivity(
        $conn,
        $actor,
        "Updated cost centre {$name}",
        'Users & Access',
        'update',
        [
            'entity_type' => 'cost_center',
            'entity_id' => (string) $id,
            'description' => "Updated cost centre {$name}",
            'before' => $before,
            'after' => $after,
        ],
        true
    );
    $conn->commit();

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cost centre updated successfully.',
        'data' => costCenterManagementDecorate($conn, $after),
    ]);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('[Smartbooks Cost Centre/Update] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
