<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can create accounting periods.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $startDate = trim((string) ($data['start_date'] ?? ''));
    $endDate = trim((string) ($data['end_date'] ?? ''));
    $reason = trim((string) ($data['lock_reason'] ?? ''));
    $isLocked = (bool) ($data['is_locked'] ?? false);
    $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new RuntimeException('Valid start and end dates are required.', 400);
    }
    if ($startDate > $endDate) {
        throw new RuntimeException('Start date cannot be later than end date.', 400);
    }
    if ($isLocked && $reason === '') {
        throw new RuntimeException('A reason is required when locking a period.', 400);
    }

    $overlap = $conn->prepare('SELECT id FROM accounting_periods WHERE start_date <= ? AND end_date >= ? LIMIT 1');
    $overlap->bind_param('ss', $endDate, $startDate);
    $overlap->execute();
    if ($overlap->get_result()->fetch_assoc()) {
        throw new RuntimeException('This accounting period overlaps an existing period.', 409);
    }
    $overlap->close();

    $actorId = (int) $user['id'];
    $actorEmail = (string) $user['email'];
    $lockedValue = $isLocked ? '1' : '0';
    $activeValue = $isActive ? '1' : '0';

    $stmt = $conn->prepare('INSERT INTO accounting_periods (start_date, end_date, is_locked, is_active, lock_reason, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssss', $startDate, $endDate, $lockedValue, $activeValue, $reason, $actorEmail, $actorEmail);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    $action = "{$actorEmail} created accounting period #{$id} ({$startDate} to {$endDate})";
    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $log->bind_param('iss', $actorId, $action, $actorEmail);
    $log->execute();
    $log->close();

    jsonResponse([
        'status' => 'Success',
        'message' => 'Accounting period created successfully.',
        'data' => [
            'id' => $id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_locked' => $isLocked,
            'is_active' => $isActive,
            'lock_reason' => $reason,
        ],
    ], 201);
} catch (Throwable $exception) {
    error_log('[Smartbooks AccountingPeriod/Create] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], publicErrorStatus($exception));
}
