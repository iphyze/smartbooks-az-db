<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/notification_helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can manage accounting periods.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $id = (int) ($data['id'] ?? 0);
    $startDate = trim((string) ($data['start_date'] ?? ''));
    $endDate = trim((string) ($data['end_date'] ?? ''));
    $reason = trim((string) ($data['lock_reason'] ?? ''));

    if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new RuntimeException('A valid accounting period and date range are required.', 400);
    }
    if ($startDate > $endDate) {
        throw new RuntimeException('Start date cannot be later than end date.', 400);
    }
    if (!array_key_exists('is_locked', $data) || !is_bool($data['is_locked'])) {
        throw new RuntimeException('is_locked must be true or false.', 400);
    }

    $isLocked = (bool) $data['is_locked'];
    $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
    if ($isLocked && $reason === '') {
        throw new RuntimeException('A reason is required when locking a period.', 400);
    }

    $overlap = $conn->prepare('SELECT id FROM accounting_periods WHERE id <> ? AND start_date <= ? AND end_date >= ? LIMIT 1');
    $overlap->bind_param('iss', $id, $endDate, $startDate);
    $overlap->execute();
    if ($overlap->get_result()->fetch_assoc()) {
        throw new RuntimeException('This accounting period overlaps an existing period.', 409);
    }
    $overlap->close();

    $actorId = (int) $user['id'];
    $actorEmail = (string) $user['email'];
    $lockedValue = $isLocked ? '1' : '0';
    $activeValue = $isActive ? '1' : '0';

    $stmt = $conn->prepare('UPDATE accounting_periods SET start_date = ?, end_date = ?, is_locked = ?, is_active = ?, lock_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ssssssi', $startDate, $endDate, $lockedValue, $activeValue, $reason, $actorEmail, $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        $check = $conn->prepare('SELECT id FROM accounting_periods WHERE id = ? LIMIT 1');
        $check->bind_param('i', $id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            throw new RuntimeException('Accounting period not found.', 404);
        }
        $check->close();
    }
    $stmt->close();

    $action = "{$actorEmail} updated accounting period #{$id} ({$startDate} to {$endDate})";
    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    notifyAccountingUsers(
        $conn,
        $isLocked ? 'accounting_period_locked' : 'accounting_period_unlocked',
        'accounting_period',
        $isLocked ? 'Accounting period locked' : 'Accounting period unlocked',
        "{$actorEmail} " . ($isLocked ? 'locked' : 'unlocked') . " the period {$startDate} to {$endDate}.",
        $isLocked ? 'warning' : 'info',
        'accounting_period',
        $id,
        '/lock-period/home',
        ['start_date' => $startDate, 'end_date' => $endDate, 'is_locked' => $isLocked],
        $actorId
    );

    jsonResponse([
        'status' => 'Success',
        'message' => $isLocked ? 'Accounting period locked successfully.' : 'Accounting period unlocked successfully.',
        'data' => [
            'id' => $id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_locked' => $isLocked,
            'is_active' => $isActive,
            'lock_reason' => $reason,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks AccountingPeriod/Update] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
