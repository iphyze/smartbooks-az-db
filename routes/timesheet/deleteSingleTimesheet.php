<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/accounting_period_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER, SMARTBOOKS_ROLE_TIMESHEET], 'You are not authorised to delete timesheets.');
    $staffScope = timesheetStaffScope($conn, $user);

    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($data['id'] ?? $data['timesheet_id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('A valid timesheet ID is required.', 400);
    }

    if ($staffScope !== null) {
        assertTimesheetEntryAccess($conn, $user, $id);
    }

    $conn->begin_transaction();
    try {
        $lockSql = 'SELECT id, date FROM timesheet_table WHERE id = ?';
        $lockTypes = 'i';
        $lockParams = [$id];
        if ($staffScope !== null) {
            $lockSql .= ' AND staff_id = ?';
            $lockTypes .= 'i';
            $lockParams[] = (int) $staffScope['staff_id'];
        }
        $lockSql .= ' FOR UPDATE';
        $lock = $conn->prepare($lockSql);
        $lock->bind_param($lockTypes, ...$lockParams);
        $lock->execute();
        $entry = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$entry) {
            throw new RuntimeException('Timesheet entry not found.', 404);
        }
        smartbooksAssertPostingDateOpen(
            $conn,
            smartbooksPeriodValidateDate((string) $entry['date'], 'timesheet work date'),
            'Timesheet work date'
        );

        $sql = 'DELETE FROM timesheet_table WHERE id = ?';
        $types = 'i';
        $params = [$id];
        if ($staffScope !== null) {
            $sql .= ' AND staff_id = ?';
            $types .= 'i';
            $params[] = (int) $staffScope['staff_id'];
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Timesheet entry not found.', 404);
        }
        $stmt->close();

        $actorId = (int) $user['id'];
        $actorEmail = (string) $user['email'];
        $action = "{$actorEmail} deleted timesheet entry #{$id}";
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actorId, $action, $actorEmail);
        $log->execute();
        $log->close();

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    jsonResponse(['status' => 'Success', 'message' => 'Timesheet entry deleted successfully.']);
} catch (Throwable $exception) {
    error_log('[Smartbooks Timesheet/DeleteSingle] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
