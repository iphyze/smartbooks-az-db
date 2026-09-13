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
    requireAllCostCenterAccessForGlobalAccounting($user, 'Accounting-period locks and fiscal-year closing are company-wide and require All Cost Centres access.');
    requirePermission($conn, $user, 'accounting_period.reverse', 'You do not have permission to preview a fiscal-year close reversal.');

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }
    $preview = smartbooksBuildFiscalYearCloseReversalPreview($conn, (int) ($data['closure_id'] ?? 0));

    jsonResponse([
        'status' => 'Success',
        'message' => $preview['can_reverse']
            ? 'Fiscal-year close reversal is ready for review.'
            : 'This fiscal-year closure cannot be reversed.',
        'data' => $preview,
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks FiscalYearClose/PreviewReversal] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
