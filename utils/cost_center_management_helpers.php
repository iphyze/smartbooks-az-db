<?php
declare(strict_types=1);

require_once __DIR__ . '/cost_center_access_helpers.php';

function costCenterManagementReferenceTables(mysqli $conn): array
{
    $sql = "SELECT TABLE_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME = 'cost_center'
            ORDER BY TABLE_NAME ASC";
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Unable to inspect cost centre references.', 500);
    }

    $tables = [];
    while ($row = $result->fetch_assoc()) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        if ($table !== '' && preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $tables[] = $table;
        }
    }
    return array_values(array_unique($tables));
}

function costCenterManagementFind(mysqli $conn, int $id, bool $forUpdate = false): array
{
    if ($id <= 0) {
        throw new RuntimeException('Cost centre not found.', 404);
    }

    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT id, name, normalized_name, is_active, created_at, created_by, updated_at, updated_by
         FROM cost_center_table
         WHERE id = ?
         LIMIT 1{$suffix}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load cost centre.', 500);
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Cost centre not found.', 404);
    }

    $row['id'] = (int) $row['id'];
    $row['is_active'] = (bool) ((int) $row['is_active']);
    return $row;
}

function costCenterManagementUsage(mysqli $conn, string $normalizedName, int $costCenterId): array
{
    $references = [];
    $referenceTotal = 0;

    foreach (costCenterManagementReferenceTables($conn) as $table) {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM {$quotedTable} WHERE LOWER(TRIM(cost_center)) = ?");
        if (!$stmt) {
            throw new RuntimeException('Unable to inspect cost centre usage.', 500);
        }
        $stmt->bind_param('s', $normalizedName);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($count > 0) {
            $references[$table] = $count;
            $referenceTotal += $count;
        }
    }

    $assignedStmt = $conn->prepare('SELECT COUNT(*) AS total FROM user_cost_center_access WHERE cost_center_id = ?');
    if (!$assignedStmt) {
        throw new RuntimeException('Unable to inspect cost centre assignments.', 500);
    }
    $assignedStmt->bind_param('i', $costCenterId);
    $assignedStmt->execute();
    $assignedUsers = (int) ($assignedStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $assignedStmt->close();

    return [
        'reference_count' => $referenceTotal,
        'assigned_users' => $assignedUsers,
        'references' => $references,
        'can_hard_delete' => $referenceTotal === 0 && $assignedUsers === 0,
    ];
}

function costCenterManagementRenameReferences(mysqli $conn, string $oldNormalizedName, string $newName): void
{
    foreach (costCenterManagementReferenceTables($conn) as $table) {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $stmt = $conn->prepare("UPDATE {$quotedTable} SET cost_center = ? WHERE LOWER(TRIM(cost_center)) = ?");
        if (!$stmt) {
            throw new RuntimeException('Unable to update cost centre references.', 500);
        }
        $stmt->bind_param('ss', $newName, $oldNormalizedName);
        $stmt->execute();
        $stmt->close();
    }
}

function costCenterManagementDecorate(mysqli $conn, array $row): array
{
    $normalized = (string) ($row['normalized_name'] ?? normalizeCostCenterName((string) ($row['name'] ?? '')));
    $usage = costCenterManagementUsage($conn, $normalized, (int) $row['id']);

    return array_merge($row, [
        'usage' => $usage,
    ]);
}
