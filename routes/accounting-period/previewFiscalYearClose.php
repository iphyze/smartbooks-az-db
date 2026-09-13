<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/accounting_period_helpers.php';
require_once __DIR__ . '/../../utils/fx_helpers.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $user = authenticateUser();
    requireAllCostCenterAccessForGlobalAccounting($user, 'Accounting-period locks and fiscal-year closing are company-wide and require All Cost Centres access.');
    requirePermission($conn, $user, 'accounting_period.close', 'You do not have permission to preview a fiscal-year close.');

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }
    $startDate = (string) ($data['period_start'] ?? $data['start_date'] ?? '');
    $endDate = (string) ($data['period_end'] ?? $data['end_date'] ?? '');
    $retainedLedger = (int) ($data['retained_earnings_ledger_number'] ?? SMARTBOOKS_RETAINED_EARNINGS_LEDGER);

    $preview = smartbooksBuildFiscalYearClosePreview($conn, $startDate, $endDate, $retainedLedger);
    jsonResponse([
        'status' => 'Success',
        'message' => $preview['can_post']
            ? 'Fiscal-year closing journal is ready for review.'
            : 'Resolve the listed blockers before posting the fiscal-year close.',
        'data' => $preview,
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks FiscalYearClose/Preview] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
