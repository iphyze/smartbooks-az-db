<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';
require_once __DIR__ . '/../../utils/accounting_period_helpers.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can manage accounting periods.');
    smartbooksRequirePeriodSchema($conn);

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }
    $periodId = (int) ($data['id'] ?? 0);
    if ($periodId <= 0) {
        throw new RuntimeException('A valid accounting period is required.', 422);
    }
    if (!array_key_exists('is_locked', $data) || !is_bool($data['is_locked'])) {
        throw new RuntimeException('is_locked must be true or false.', 422);
    }
    if (array_key_exists('is_active', $data) && !is_bool($data['is_active'])) {
        throw new RuntimeException('is_active must be true or false.', 422);
    }

    $actorId = (int) ($user['id'] ?? 0);
    $actorEmail = trim((string) ($user['email'] ?? 'system'));
    $requestedLocked = (bool) $data['is_locked'];
    $previewToken = trim((string) ($data['preview_token'] ?? ''));
    $lockReason = trim((string) ($data['lock_reason'] ?? ''));
    $unlockReason = trim((string) ($data['unlock_reason'] ?? $data['reason'] ?? ''));

    $conn->begin_transaction();
    try {
        $current = smartbooksAccountingPeriodById($conn, $periodId, true);
        if (!$current) {
            throw new RuntimeException('Accounting period not found.', 404);
        }

        $startDate = array_key_exists('start_date', $data)
            ? smartbooksPeriodValidateDate((string) $data['start_date'], 'start date')
            : (string) $current['start_date'];
        $endDate = array_key_exists('end_date', $data)
            ? smartbooksPeriodValidateDate((string) $data['end_date'], 'end date')
            : (string) $current['end_date'];
        if ($startDate > $endDate) {
            throw new RuntimeException('Start date cannot be later than end date.', 422);
        }
        $requestedActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $current['is_active'];
        $dateChanged = $startDate !== (string) $current['start_date'] || $endDate !== (string) $current['end_date'];
        $lockChanged = $requestedLocked !== (bool) $current['is_locked'];

        if ((bool) $current['is_locked'] && ($dateChanged || !$requestedActive)) {
            throw new RuntimeException('Unlock the accounting period before changing its dates or deactivating it.', 409);
        }
        if ((bool) $current['is_locked'] && $requestedLocked
            && $lockReason !== '' && $lockReason !== (string) $current['lock_reason']) {
            throw new RuntimeException('The lock reason is part of the audit record. Unlock the period before changing it.', 409);
        }
        if ($dateChanged && $requestedLocked) {
            throw new RuntimeException('Save the revised dates first, then generate a new lock preview before locking.', 409);
        }

        $overlap = $conn->prepare(
            'SELECT id, start_date, end_date FROM accounting_periods
             WHERE id <> ? AND start_date <= ? AND end_date >= ? LIMIT 1 FOR UPDATE'
        );
        $overlap->bind_param('iss', $periodId, $endDate, $startDate);
        $overlap->execute();
        $existing = $overlap->get_result()->fetch_assoc();
        $overlap->close();
        if ($existing) {
            throw new RuntimeException(
                "The selected dates overlap accounting period {$existing['start_date']} to {$existing['end_date']}.",
                409
            );
        }

        if ((bool) $current['is_locked'] && !$requestedLocked) {
            if (mb_strlen($unlockReason) < 5 || mb_strlen($unlockReason) > 500) {
                throw new RuntimeException('Enter an unlock reason between 5 and 500 characters.', 422);
            }
            $closure = smartbooksActiveFiscalClosureOverlapping($conn, (string) $current['start_date'], (string) $current['end_date']);
            if ($closure) {
                throw new RuntimeException(
                    "Reverse fiscal-year closure {$closure['closure_code']} before unlocking this accounting period.",
                    409
                );
            }
        }

        if (!(bool) $current['is_locked'] && $requestedLocked) {
            if (!$requestedActive) {
                throw new RuntimeException('Activate the accounting period before locking it.', 422);
            }
            if (mb_strlen($lockReason) < 5 || mb_strlen($lockReason) > 500) {
                throw new RuntimeException('Enter a lock reason between 5 and 500 characters.', 422);
            }
            if ($previewToken === '') {
                throw new RuntimeException('Generate and confirm the accounting-period lock preview before locking.', 428);
            }
            $previewPeriod = $current;
            $previewPeriod['start_date'] = $startDate;
            $previewPeriod['end_date'] = $endDate;
            $previewPeriod['is_active'] = $requestedActive;
            $preview = smartbooksBuildPeriodLockPreview($conn, $previewPeriod);
            if (!hash_equals((string) $preview['preview_token'], $previewToken)) {
                throw new RuntimeException('The accounting-period data changed after the preview. Generate a new preview.', 409);
            }
            if (!$preview['can_lock']) {
                throw new RuntimeException('Resolve the accounting-period preview blockers before locking.', 409);
            }
        }

        $lockedValue = $requestedLocked ? 1 : 0;
        $activeValue = $requestedActive ? 1 : 0;
        $storedReason = $requestedLocked
            ? ($lockReason !== '' ? $lockReason : (string) $current['lock_reason'])
            : (string) $current['lock_reason'];

        if ($lockChanged && $requestedLocked) {
            $stmt = $conn->prepare(
                'UPDATE accounting_periods
                 SET start_date = ?, end_date = ?, is_locked = ?, is_active = ?, lock_reason = ?,
                     locked_at = NOW(), locked_by_user_id = ?, locked_by_email = ?,
                     unlocked_at = NULL, unlocked_by_user_id = NULL, unlocked_by_email = NULL,
                     updated_by = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('ssiisissi', $startDate, $endDate, $lockedValue, $activeValue, $storedReason, $actorId, $actorEmail, $actorEmail, $periodId);
        } elseif ($lockChanged && !$requestedLocked) {
            $stmt = $conn->prepare(
                'UPDATE accounting_periods
                 SET start_date = ?, end_date = ?, is_locked = ?, is_active = ?,
                     unlocked_at = NOW(), unlocked_by_user_id = ?, unlocked_by_email = ?, updated_by = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('ssiiissi', $startDate, $endDate, $lockedValue, $activeValue, $actorId, $actorEmail, $actorEmail, $periodId);
        } else {
            $stmt = $conn->prepare(
                'UPDATE accounting_periods
                 SET start_date = ?, end_date = ?, is_locked = ?, is_active = ?, lock_reason = ?, updated_by = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('ssiissi', $startDate, $endDate, $lockedValue, $activeValue, $storedReason, $actorEmail, $periodId);
        }
        $stmt->execute();
        $stmt->close();

        $after = smartbooksAccountingPeriodById($conn, $periodId, false);
        if (!$after) {
            throw new RuntimeException('Unable to reload the updated accounting period.', 500);
        }
        $eventType = $lockChanged ? ($requestedLocked ? 'Locked' : 'Unlocked') : 'Updated';
        $eventReason = $eventType === 'Locked' ? $storedReason : ($eventType === 'Unlocked' ? $unlockReason : '');
        smartbooksRecordPeriodEvent($conn, $periodId, $eventType, $current, $after, $actorId, $actorEmail, $eventReason);

        $action = "{$actorEmail} " . strtolower($eventType) . " accounting period #{$periodId} ({$startDate} to {$endDate})";
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actorId, $action, $actorEmail);
        $log->execute();
        $log->close();

        $notificationType = $eventType === 'Locked'
            ? 'accounting_period_locked'
            : ($eventType === 'Unlocked' ? 'accounting_period_unlocked' : 'accounting_period_updated');
        $notificationTitle = $eventType === 'Locked'
            ? 'Accounting period locked'
            : ($eventType === 'Unlocked' ? 'Accounting period unlocked' : 'Accounting period updated');
        notifyAccountingUsers(
            $conn,
            $notificationType,
            'accounting_period',
            $notificationTitle,
            "{$actorEmail} " . strtolower($eventType) . " the period {$startDate} to {$endDate}.",
            $eventType === 'Locked' ? 'warning' : 'info',
            'accounting_period',
            $periodId,
            '/lock-period/home',
            ['start_date' => $startDate, 'end_date' => $endDate, 'is_locked' => $requestedLocked],
            $actorId
        );

        $conn->commit();
        jsonResponse([
            'status' => 'Success',
            'message' => $lockChanged
                ? ($requestedLocked ? 'Accounting period locked successfully.' : 'Accounting period unlocked successfully.')
                : 'Accounting period updated successfully.',
            'data' => $after,
        ]);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
} catch (Throwable $exception) {
    error_log('[Smartbooks AccountingPeriod/Update] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
