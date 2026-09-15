<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';
require_once 'utils/text_normalization.php';
require_once 'utils/activity_log_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = authenticateUser();
    requirePermission($conn, $actor, 'cost_centre.create', 'You do not have permission to create cost centres.');
    if (!userHasAllCostCenterAccess($actor)) {
        throw new RuntimeException('Cost Centre Administration requires All Cost Centres access.', 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $name = smartbooksCanonicalName($data['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Cost centre name is required.', 400);
    }
    if (mb_strlen($name) > 255) {
        throw new RuntimeException('Cost centre name cannot exceed 255 characters.', 400);
    }

    $normalized = normalizeCostCenterName($name);
    $actorEmail = trim((string) ($actor['email'] ?? 'system')) ?: 'system';

    $conn->begin_transaction();
    $duplicate = $conn->prepare('SELECT id, name, is_active FROM cost_center_table WHERE normalized_name = ? LIMIT 1 FOR UPDATE');
    if (!$duplicate) {
        throw new RuntimeException('Unable to validate cost centre.', 500);
    }
    $duplicate->bind_param('s', $normalized);
    $duplicate->execute();
    $existing = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();

    $restored = false;
    if ($existing) {
        if ((int) $existing['is_active'] === 1) {
            throw new RuntimeException('A cost centre with this name already exists.', 409);
        }

        $id = (int) $existing['id'];
        $restore = $conn->prepare(
            'UPDATE cost_center_table
             SET name = ?, is_active = 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if (!$restore) {
            throw new RuntimeException('Unable to reactivate cost centre.', 500);
        }
        $restore->bind_param('ssi', $name, $actorEmail, $id);
        $restore->execute();
        $restore->close();
        $restored = true;
    } else {
        $insert = $conn->prepare(
            'INSERT INTO cost_center_table (name, normalized_name, created_by, updated_by)
             VALUES (?, ?, ?, ?)'
        );
        if (!$insert) {
            throw new RuntimeException('Unable to create cost centre.', 500);
        }
        $insert->bind_param('ssss', $name, $normalized, $actorEmail, $actorEmail);
        $insert->execute();
        $id = (int) $insert->insert_id;
        $insert->close();
    }

    logActivity(
        $conn,
        $actor,
        $restored ? "Reactivated cost centre {$name}" : "Created cost centre {$name}",
        'Users & Access',
        $restored ? 'reactivate' : 'create',
        [
            'entity_type' => 'cost_center',
            'entity_id' => (string) $id,
            'description' => $restored ? 'Reactivated an existing cost centre.' : 'Created a new cost centre.',
            'after' => ['id' => $id, 'name' => $name, 'is_active' => true],
        ],
        true
    );

    $conn->commit();
    jsonResponse([
        'status' => 'Success',
        'message' => $restored ? 'Cost centre reactivated successfully.' : 'Cost centre created successfully.',
        'data' => ['id' => $id, 'name' => $name, 'is_active' => true],
    ], $restored ? 200 : 201);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('[Smartbooks Cost Centre/Create] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
