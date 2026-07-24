<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/accounting_period_helpers.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can view fiscal-year closures.');
    smartbooksRequirePeriodSchema($conn);

    $status = trim((string) ($_GET['status'] ?? ''));
    $sql = 'SELECT id, closure_code, period_start, period_end, closing_date,
                   retained_earnings_ledger_number, net_profit_loss_ngn,
                   total_debit_ngn, total_credit_ngn, journal_id, journal_description,
                   status, posted_by_user_id, posted_by_email, posted_at,
                   reversal_journal_id, reversal_reason, reversed_by_user_id,
                   reversed_by_email, reversed_at
            FROM fiscal_year_closures';
    if ($status !== '') {
        $sql .= ' WHERE status = ?';
    }
    $sql .= ' ORDER BY period_end DESC, id DESC';
    $stmt = $conn->prepare($sql);
    if ($status !== '') {
        $stmt->bind_param('s', $status);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        foreach (['id', 'retained_earnings_ledger_number', 'journal_id', 'posted_by_user_id'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['reversal_journal_id', 'reversed_by_user_id'] as $field) {
            $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
        }
        foreach (['net_profit_loss_ngn', 'total_debit_ngn', 'total_credit_ngn'] as $field) {
            $row[$field] = (float) $row[$field];
        }
        $row['result'] = $row['net_profit_loss_ngn'] > 0.009
            ? 'Profit'
            : ($row['net_profit_loss_ngn'] < -0.009 ? 'Loss' : 'Break-even');
    }
    unset($row);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Fiscal-year closures fetched successfully.',
        'data' => $rows,
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks FiscalYearClose/List] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
