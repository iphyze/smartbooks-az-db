<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';
require_once __DIR__ . '/../../utils/accounting_period_helpers.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can create accounting periods.');
    smartbooksRequirePeriodSchema($conn);

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $startDate = smartbooksPeriodValidateDate((string) ($data['start_date'] ?? ''), 'start date');
    $endDate = smartbooksPeriodValidateDate((string) ($data['end_date'] ?? ''), 'end date');
    if ($startDate > $endDate) {
        throw new RuntimeException('Start date cannot be later than end date.', 422);
    }

    if (array_key_exists('is_locked', $data) && !is_bool($data['is_locked'])) {
        throw new RuntimeException('is_locked must be true or false.', 422);
    }
    if (array_key_exists('is_active', $data) && !is_bool($data['is_active'])) {
        throw new RuntimeException('is_active must be true or false.', 422);
    }
    if ((bool) ($data['is_locked'] ?? false)) {
        throw new RuntimeException('Create the accounting period first, review its lock preview, and then lock it.', 422);
    }

    $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
    $actorId = (int) ($user['id'] ?? 0);
    $actorEmail = trim((string) ($user['email'] ?? 'system'));

    $conn->begin_transaction();
    try {
        $overlap = $conn->prepare(
            'SELECT id, start_date, end_date FROM accounting_periods
             WHERE start_date <= ? AND end_date >= ? LIMIT 1 FOR UPDATE'
        );
        $overlap->bind_param('ss', $endDate, $startDate);
        $overlap->execute();
        $existing = $overlap->get_result()->fetch_assoc();
        $overlap->close();
        if ($existing) {
            throw new RuntimeException(
                "The selected dates overlap accounting period {$existing['start_date']} to {$existing['end_date']}.",
                409
            );
        }

        $lockedValue = 0;
        $activeValue = $isActive ? 1 : 0;
        $emptyReason = '';
        $stmt = $conn->prepare(
            'INSERT INTO accounting_periods
             (start_date, end_date, is_locked, is_active, lock_reason, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssiisss', $startDate, $endDate, $lockedValue, $activeValue, $emptyReason, $actorEmail, $actorEmail);
        $stmt->execute();
        $periodId = (int) $conn->insert_id;
        $stmt->close();

        $after = [
            'id' => $periodId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_locked' => false,
            'is_active' => $isActive,
            'lock_reason' => '',
        ];
        smartbooksRecordPeriodEvent($conn, $periodId, 'Created', [], $after, $actorId, $actorEmail);

        $action = "{$actorEmail} created accounting period #{$periodId} ({$startDate} to {$endDate})";
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actorId, $action, $actorEmail);
        $log->execute();
        $log->close();

        notifyAccountingUsers(
            $conn,
            'accounting_period_created',
            'accounting_period',
            'A new accounting period was created',
            "{$actorEmail} created the period {$startDate} to {$endDate}.",
            'info',
            'accounting_period',
            $periodId,
            '/lock-period/home',
            ['start_date' => $startDate, 'end_date' => $endDate, 'is_locked' => false],
            $actorId
        );

        $conn->commit();
        jsonResponse([
            'status' => 'Success',
            'message' => 'Accounting period created successfully. Review the lock preview before locking it.',
            'data' => $after,
        ], 201);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
} catch (Throwable $exception) {
    error_log('[Smartbooks AccountingPeriod/Create] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
