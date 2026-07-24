<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/accounting_period_helpers.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can review accounting-period locks.');
    smartbooksRequirePeriodSchema($conn);

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }
    $periodId = (int) ($data['id'] ?? $data['period_id'] ?? 0);
    $period = smartbooksAccountingPeriodById($conn, $periodId);
    if (!$period) {
        throw new RuntimeException('Accounting period not found.', 404);
    }

    $preview = smartbooksBuildPeriodLockPreview($conn, $period);
    jsonResponse([
        'status' => 'Success',
        'message' => $preview['can_lock']
            ? 'Accounting period is ready to be locked.'
            : 'Resolve the listed blockers before locking this period.',
        'data' => $preview,
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks AccountingPeriod/PreviewLock] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
