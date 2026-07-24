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
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can view accounting periods.');
    smartbooksRequirePeriodSchema($conn);

    $search = trim((string) ($_GET['search'] ?? ''));
    $sql = "SELECT p.id, p.start_date, p.end_date, p.is_locked, p.is_active, p.lock_reason,
                   p.locked_at, p.locked_by_user_id, p.locked_by_email,
                   p.unlocked_at, p.unlocked_by_user_id, p.unlocked_by_email,
                   p.created_by, p.created_at, p.updated_by, p.updated_at,
                   c.id AS fiscal_closure_id, c.closure_code, c.journal_id AS closing_journal_id,
                   c.net_profit_loss_ngn, c.status AS fiscal_closure_status
            FROM accounting_periods p
            LEFT JOIN fiscal_year_closures c
              ON c.status = 'Posted' AND c.period_start <= p.end_date AND c.period_end >= p.start_date";
    $params = [];
    $types = '';
    if ($search !== '') {
        $sql .= ' WHERE CAST(p.start_date AS CHAR) LIKE ? OR CAST(p.end_date AS CHAR) LIKE ? OR p.lock_reason LIKE ? OR c.closure_code LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
        $types = 'ssss';
    }
    $sql .= ' ORDER BY p.end_date DESC, p.id DESC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load accounting periods.', 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $normalised = array_map(static function (array $period): array {
        $period['id'] = (int) $period['id'];
        $period['is_locked'] = smartbooksPeriodValueIsTrue($period['is_locked']);
        $period['is_active'] = smartbooksPeriodValueIsTrue($period['is_active']);
        $period['locked_by_user_id'] = $period['locked_by_user_id'] !== null ? (int) $period['locked_by_user_id'] : null;
        $period['unlocked_by_user_id'] = $period['unlocked_by_user_id'] !== null ? (int) $period['unlocked_by_user_id'] : null;
        $period['fiscal_closure_id'] = $period['fiscal_closure_id'] !== null ? (int) $period['fiscal_closure_id'] : null;
        $period['closing_journal_id'] = $period['closing_journal_id'] !== null ? (int) $period['closing_journal_id'] : null;
        $period['net_profit_loss_ngn'] = $period['net_profit_loss_ngn'] !== null ? (float) $period['net_profit_loss_ngn'] : null;
        $period['has_active_fiscal_close'] = $period['fiscal_closure_id'] !== null;
        return $period;
    }, $periods);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Accounting periods fetched successfully.',
        'data' => $normalised,
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks AccountingPeriod/List] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
