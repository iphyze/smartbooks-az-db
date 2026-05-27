<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER, SMARTBOOKS_ROLE_TIMESHEET], 'You are not authorised to delete timesheets.');
    $staffScope = timesheetStaffScope($conn, $user);

    $data = json_decode(file_get_contents('php://input'), true);
    $rawIds = $data['ids'] ?? [];
    if (!is_array($rawIds) || count($rawIds) === 0 || count($rawIds) > 100) {
        throw new RuntimeException('Select between 1 and 100 timesheet entries to delete.', 400);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn (int $id): bool => $id > 0)));
    if (count($ids) !== count(array_unique($rawIds))) {
        throw new RuntimeException('Invalid timesheet entry selection.', 400);
    }

    if ($staffScope !== null) {
        foreach ($ids as $id) {
            assertTimesheetEntryAccess($conn, $user, $id);
        }
    }

    $conn->begin_transaction();
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "DELETE FROM timesheet_table WHERE id IN ($placeholders)";
        $params = $ids;

        if ($staffScope !== null) {
            $sql .= ' AND staff_id = ?';
            $types .= 'i';
            $params[] = (int) $staffScope['staff_id'];
        }

        $delete = $conn->prepare($sql);
        if (!$delete) {
            throw new RuntimeException('Unable to delete timesheet entries.', 500);
        }
        $delete->bind_param($types, ...$params);
        $delete->execute();
        $deletedCount = $delete->affected_rows;
        $delete->close();

        if ($deletedCount !== count($ids)) {
            throw new RuntimeException('One or more timesheet entries could not be deleted.', 404);
        }

        $actorId = (int) $user['id'];
        $actorEmail = (string) $user['email'];
        $action = $actorEmail . ' deleted timesheet entry IDs: ' . implode(', ', $ids);
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actorId, $action, $actorEmail);
        $log->execute();
        $log->close();

        $conn->commit();
        jsonResponse(['status' => 'Success', 'message' => 'Timesheet entry(s) deleted successfully.']);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
} catch (Throwable $exception) {
    error_log('[Smartbooks Timesheet/Delete] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
