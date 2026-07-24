<?php
declare(strict_types=1);

/**
 * Shared accounting-period controls.
 *
 * All financial posting routes should use smartbooksAssertPostingDateOpen().
 * Fiscal-year closing is the only controlled route allowed to post inside a
 * locked period, because it validates that the full range is locked first.
 */

const SMARTBOOKS_RETAINED_EARNINGS_LEDGER = 11000001;
const SMARTBOOKS_PERIOD_LOCK_PREVIEW_VERSION = 1;
const SMARTBOOKS_YEAR_CLOSE_PREVIEW_VERSION = 1;

function smartbooksPeriodValidateDate(string $value, string $label = 'date'): string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("Enter a valid {$label} in YYYY-MM-DD format.", 422);
    }
    return $value;
}

function smartbooksPeriodValueIsTrue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'locked', 'true', 'yes', 'active'], true);
}

function smartbooksPeriodTableExists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function smartbooksPeriodSchemaReady(mysqli $conn): bool
{
    return smartbooksPeriodTableExists($conn, 'accounting_sequences')
        && smartbooksPeriodTableExists($conn, 'accounting_period_events')
        && smartbooksPeriodTableExists($conn, 'fiscal_year_closures')
        && smartbooksPeriodTableExists($conn, 'fiscal_year_closure_lines');
}

function smartbooksRequirePeriodSchema(mysqli $conn): void
{
    if (!smartbooksPeriodSchemaReady($conn)) {
        throw new RuntimeException(
            'The accounting-period database migration has not been applied. Run 20260723_period_lock_backend.sql first.',
            503
        );
    }
}

function smartbooksAccountingPeriodById(mysqli $conn, int $periodId, bool $forUpdate = false): ?array
{
    if ($periodId <= 0) {
        return null;
    }
    $sql = 'SELECT id, start_date, end_date, is_locked, is_active, lock_reason,
                   created_by, created_at, updated_by, updated_at
            FROM accounting_periods WHERE id = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load the accounting period.', 500);
    }
    $stmt->bind_param('i', $periodId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) {
        $row['id'] = (int) $row['id'];
        $row['is_locked'] = smartbooksPeriodValueIsTrue($row['is_locked']);
        $row['is_active'] = smartbooksPeriodValueIsTrue($row['is_active']);
    }
    return $row;
}

function smartbooksAccountingPeriodForDate(mysqli $conn, string $postingDate, bool $forUpdate = false): ?array
{
    $postingDate = smartbooksPeriodValidateDate($postingDate, 'posting date');
    $sql = "SELECT id, start_date, end_date, is_locked, is_active, lock_reason
            FROM accounting_periods
            WHERE start_date <= ?
              AND end_date >= ?
              AND LOWER(TRIM(CAST(is_active AS CHAR))) IN ('1', 'active', 'true', 'yes')
            ORDER BY start_date DESC, id DESC
            LIMIT 2";
    if ($forUpdate) {
        // Mutation routes call this inside their transaction. Locking the covering
        // period serialises posting with a concurrent lock/unlock operation.
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the accounting period.', 500);
    }
    $stmt->bind_param('ss', $postingDate, $postingDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($rows) > 1) {
        throw new RuntimeException(
            "Posting date {$postingDate} is covered by overlapping active accounting periods. Resolve the period setup before posting.",
            409
        );
    }
    $row = $rows[0] ?? null;
    if ($row) {
        $row['is_locked'] = smartbooksPeriodValueIsTrue($row['is_locked']);
        $row['is_active'] = smartbooksPeriodValueIsTrue($row['is_active']);
    }
    return $row;
}

function smartbooksLockedPeriodForDate(mysqli $conn, string $postingDate): ?array
{
    $period = smartbooksAccountingPeriodForDate($conn, $postingDate, false);
    return $period && smartbooksPeriodValueIsTrue($period['is_locked'] ?? false) ? $period : null;
}

function smartbooksAssertPostingDateOpen(mysqli $conn, string $postingDate, string $context = 'Posting date'): void
{
    $postingDate = smartbooksPeriodValidateDate($postingDate, strtolower($context));
    $period = smartbooksAccountingPeriodForDate($conn, $postingDate, true);
    if (!$period || !smartbooksPeriodValueIsTrue($period['is_locked'] ?? false)) {
        return;
    }
    $reason = trim((string) ($period['lock_reason'] ?? ''));
    $message = "{$context} {$postingDate} falls within the locked accounting period "
        . "{$period['start_date']} to {$period['end_date']}.";
    if ($reason !== '') {
        $message .= " Reason: {$reason}";
    }
    throw new RuntimeException($message, 409);
}

function smartbooksAssertPostingRangeOpen(mysqli $conn, string $startDate, string $endDate, string $context = 'Posting range'): void
{
    $startDate = smartbooksPeriodValidateDate($startDate, 'start date');
    $endDate = smartbooksPeriodValidateDate($endDate, 'end date');
    if ($startDate > $endDate) {
        throw new RuntimeException('Start date cannot be later than end date.', 422);
    }
    $stmt = $conn->prepare(
        "SELECT id, start_date, end_date, is_locked, lock_reason
         FROM accounting_periods
         WHERE start_date <= ? AND end_date >= ?
           AND LOWER(TRIM(CAST(is_active AS CHAR))) IN ('1', 'active', 'true', 'yes')
         ORDER BY start_date ASC, id ASC
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the accounting period range.', 500);
    }
    $stmt->bind_param('ss', $endDate, $startDate);
    $stmt->execute();
    $periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $previousEnd = null;
    foreach ($periods as $period) {
        $periodStart = new DateTimeImmutable((string) $period['start_date']);
        $periodEnd = new DateTimeImmutable((string) $period['end_date']);
        if ($previousEnd instanceof DateTimeImmutable && $periodStart <= $previousEnd) {
            throw new RuntimeException(
                "{$context} is covered by overlapping active accounting periods. Resolve the period setup before changing postings.",
                409
            );
        }
        $previousEnd = $periodEnd;
    }

    foreach ($periods as $period) {
        if (!smartbooksPeriodValueIsTrue($period['is_locked'] ?? false)) {
            continue;
        }
        $reason = trim((string) ($period['lock_reason'] ?? ''));
        $message = "{$context} overlaps the locked accounting period {$period['start_date']} to {$period['end_date']}.";
        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }
        throw new RuntimeException($message, 409);
    }
}

function smartbooksAssertManualJournalTypeAllowed(string $transactionType): void
{
    $normalised = strtolower(trim($transactionType));
    if (in_array($normalised, ['year end closing', 'year end closing reversal'], true)) {
        throw new RuntimeException(
            'Year-end closing transaction types are reserved for the controlled fiscal-year close workflow.',
            409
        );
    }
}

function smartbooksAssertJournalOpenForMutation(mysqli $conn, int $journalId, string $action = 'change'): array
{
    if ($journalId <= 0) {
        throw new RuntimeException('A valid journal ID is required.', 422);
    }

    if (smartbooksPeriodTableExists($conn, 'fiscal_year_closures')) {
        $systemStmt = $conn->prepare(
            'SELECT id, closure_code, journal_id, reversal_journal_id
             FROM fiscal_year_closures
             WHERE journal_id = ? OR reversal_journal_id = ?
             LIMIT 1'
        );
        if (!$systemStmt) {
            throw new RuntimeException('Unable to validate the system-generated journal.', 500);
        }
        $systemStmt->bind_param('ii', $journalId, $journalId);
        $systemStmt->execute();
        $systemJournal = $systemStmt->get_result()->fetch_assoc();
        $systemStmt->close();
        if ($systemJournal) {
            throw new RuntimeException(
                "Journal {$journalId} belongs to fiscal-year closure {$systemJournal['closure_code']} and cannot be manually {$action}. Use the controlled fiscal-year close reversal workflow.",
                409
            );
        }
    }

    if (smartbooksPeriodTableExists($conn, 'invoice_payments')) {
        $paymentStmt = $conn->prepare(
            "SELECT id, payment_code, invoice_number, status, journal_id, reversal_journal_id
             FROM invoice_payments
             WHERE journal_id = ? OR reversal_journal_id = ?
             LIMIT 1"
        );
        if (!$paymentStmt) {
            throw new RuntimeException('Unable to validate the invoice-payment journal link.', 500);
        }
        $paymentStmt->bind_param('ii', $journalId, $journalId);
        $paymentStmt->execute();
        $linkedPayment = $paymentStmt->get_result()->fetch_assoc();
        $paymentStmt->close();
        if ($linkedPayment) {
            $linkRole = (int) ($linkedPayment['reversal_journal_id'] ?? 0) === $journalId
                ? 'the reversal journal for'
                : 'linked to';
            throw new RuntimeException(
                "Journal {$journalId} is {$linkRole} invoice payment {$linkedPayment['payment_code']} and cannot be manually {$action}. Use the controlled payment link or reversal workflow.",
                409
            );
        }
    }
    $stmt = $conn->prepare(
        'SELECT journal_id, MIN(journal_date) AS first_date, MAX(journal_date) AS last_date
         FROM main_journal_table WHERE journal_id = ? GROUP BY journal_id LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the journal period.', 500);
    }
    $stmt->bind_param('i', $journalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        $stmt = $conn->prepare('SELECT journal_id, journal_date AS first_date, journal_date AS last_date FROM journal_table WHERE journal_id = ? LIMIT 1');
        $stmt->bind_param('i', $journalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$row) {
        throw new RuntimeException('Journal not found.', 404);
    }
    smartbooksAssertPostingRangeOpen(
        $conn,
        smartbooksPeriodValidateDate((string) $row['first_date'], 'journal date'),
        smartbooksPeriodValidateDate((string) $row['last_date'], 'journal date'),
        "The journal cannot be {$action} because its posting dates"
    );
    return $row;
}


function smartbooksAssertLedgerHasNoLockedPostings(mysqli $conn, int $ledgerNumber, string $action = 'changed'): void
{
    if ($ledgerNumber <= 0) {
        throw new RuntimeException('A valid ledger number is required.', 422);
    }
    $stmt = $conn->prepare(
        "SELECT p.id, p.start_date, p.end_date, p.is_locked
         FROM accounting_periods p
         WHERE LOWER(TRIM(CAST(p.is_active AS CHAR))) IN ('1', 'active', 'true', 'yes')
           AND EXISTS (
                SELECT 1
                FROM main_journal_table m
                WHERE m.ledger_number = ?
                  AND m.journal_date BETWEEN p.start_date AND p.end_date
           )
         ORDER BY p.start_date ASC, p.id ASC
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate locked-period ledger activity.', 500);
    }
    $stmt->bind_param('i', $ledgerNumber);
    $stmt->execute();
    $periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($periods as $period) {
        if (!smartbooksPeriodValueIsTrue($period['is_locked'] ?? false)) {
            continue;
        }
        throw new RuntimeException(
            "Ledger {$ledgerNumber} cannot be {$action} because it has postings in the locked accounting period "
            . "{$period['start_date']} to {$period['end_date']}.",
            409
        );
    }
}

function smartbooksRecordPeriodEvent(
    mysqli $conn,
    int $periodId,
    string $eventType,
    array $before,
    array $after,
    int $userId,
    string $userEmail,
    string $reason = ''
): void {
    if (!smartbooksPeriodTableExists($conn, 'accounting_period_events')) {
        return;
    }
    $beforeJson = json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $afterJson = json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare(
        'INSERT INTO accounting_period_events
         (period_id, event_type, reason, before_state, after_state, performed_by_user_id, performed_by_email)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to record the accounting-period audit event.', 500);
    }
    $stmt->bind_param('issssis', $periodId, $eventType, $reason, $beforeJson, $afterJson, $userId, $userEmail);
    $stmt->execute();
    $stmt->close();
}

function smartbooksPeriodJournalDiagnostics(mysqli $conn, string $startDate, string $endDate): array
{
    $startDate = smartbooksPeriodValidateDate($startDate, 'period start date');
    $endDate = smartbooksPeriodValidateDate($endDate, 'period end date');

    $summaryStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT journal_id) AS journal_count,
                COUNT(*) AS line_count,
                COALESCE(SUM(CAST(debit_ngn AS DECIMAL(24,6))), 0) AS total_debit_ngn,
                COALESCE(SUM(CAST(credit_ngn AS DECIMAL(24,6))), 0) AS total_credit_ngn,
                COALESCE(MAX(updated_at), '1970-01-01 00:00:00') AS latest_update,
                COALESCE(SUM(id), 0) AS line_id_sum,
                COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', id, journal_id, journal_date, debit_ngn, credit_ngn, updated_at))), 0) AS journal_signature
         FROM main_journal_table
         WHERE journal_date BETWEEN ? AND ?"
    );
    $summaryStmt->bind_param('ss', $startDate, $endDate);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    $unbalancedStmt = $conn->prepare(
        "SELECT journal_id,
                MIN(journal_date) AS journal_date,
                ROUND(SUM(CAST(debit_ngn AS DECIMAL(24,6))), 2) AS debit_ngn,
                ROUND(SUM(CAST(credit_ngn AS DECIMAL(24,6))), 2) AS credit_ngn,
                ROUND(SUM(CAST(debit_ngn AS DECIMAL(24,6))) - SUM(CAST(credit_ngn AS DECIMAL(24,6))), 2) AS difference_ngn
         FROM main_journal_table
         WHERE journal_date BETWEEN ? AND ?
         GROUP BY journal_id
         HAVING ABS(SUM(CAST(debit_ngn AS DECIMAL(24,6))) - SUM(CAST(credit_ngn AS DECIMAL(24,6)))) > 0.01
         ORDER BY journal_id ASC LIMIT 50"
    );
    $unbalancedStmt->bind_param('ss', $startDate, $endDate);
    $unbalancedStmt->execute();
    $unbalanced = $unbalancedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $unbalancedStmt->close();

    $orphanHeaderStmt = $conn->prepare(
        "SELECT h.journal_id, h.journal_date
         FROM journal_table h
         LEFT JOIN main_journal_table m ON m.journal_id = h.journal_id
         WHERE h.journal_date BETWEEN ? AND ? AND m.id IS NULL
         ORDER BY h.journal_id ASC LIMIT 50"
    );
    $orphanHeaderStmt->bind_param('ss', $startDate, $endDate);
    $orphanHeaderStmt->execute();
    $orphanHeaders = $orphanHeaderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $orphanHeaderStmt->close();

    $orphanLineStmt = $conn->prepare(
        "SELECT DISTINCT m.journal_id, MIN(m.journal_date) AS journal_date
         FROM main_journal_table m
         LEFT JOIN journal_table h ON h.journal_id = m.journal_id
         WHERE m.journal_date BETWEEN ? AND ? AND h.id IS NULL
         GROUP BY m.journal_id
         ORDER BY m.journal_id ASC LIMIT 50"
    );
    $orphanLineStmt->bind_param('ss', $startDate, $endDate);
    $orphanLineStmt->execute();
    $orphanLines = $orphanLineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $orphanLineStmt->close();

    $headerMismatchStmt = $conn->prepare(
        "SELECT h.journal_id, h.journal_date,
                ROUND(CAST(h.debit_ngn AS DECIMAL(24,6)), 2) AS header_debit_ngn,
                ROUND(CAST(h.credit_ngn AS DECIMAL(24,6)), 2) AS header_credit_ngn,
                ROUND(SUM(CAST(m.debit_ngn AS DECIMAL(24,6))), 2) AS line_debit_ngn,
                ROUND(SUM(CAST(m.credit_ngn AS DECIMAL(24,6))), 2) AS line_credit_ngn
         FROM journal_table h
         INNER JOIN main_journal_table m ON m.journal_id = h.journal_id
         WHERE h.journal_date BETWEEN ? AND ?
         GROUP BY h.id, h.journal_id, h.journal_date, h.debit_ngn, h.credit_ngn
         HAVING ABS(CAST(h.debit_ngn AS DECIMAL(24,6)) - SUM(CAST(m.debit_ngn AS DECIMAL(24,6)))) > 0.01
             OR ABS(CAST(h.credit_ngn AS DECIMAL(24,6)) - SUM(CAST(m.credit_ngn AS DECIMAL(24,6)))) > 0.01
         ORDER BY h.journal_id ASC LIMIT 50"
    );
    $headerMismatchStmt->bind_param('ss', $startDate, $endDate);
    $headerMismatchStmt->execute();
    $headerMismatches = $headerMismatchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $headerMismatchStmt->close();

    return [
        'summary' => [
            'journal_count' => (int) ($summary['journal_count'] ?? 0),
            'line_count' => (int) ($summary['line_count'] ?? 0),
            'total_debit_ngn' => round((float) ($summary['total_debit_ngn'] ?? 0), 2),
            'total_credit_ngn' => round((float) ($summary['total_credit_ngn'] ?? 0), 2),
            'latest_update' => (string) ($summary['latest_update'] ?? ''),
            'line_id_sum' => (int) ($summary['line_id_sum'] ?? 0),
            'journal_signature' => (string) ($summary['journal_signature'] ?? '0'),
        ],
        'unbalanced_journals' => $unbalanced,
        'orphan_headers' => $orphanHeaders,
        'orphan_lines' => $orphanLines,
        'header_line_mismatches' => $headerMismatches,
    ];
}

function smartbooksBuildPeriodLockPreview(mysqli $conn, array $period): array
{
    $startDate = smartbooksPeriodValidateDate((string) $period['start_date'], 'period start date');
    $endDate = smartbooksPeriodValidateDate((string) $period['end_date'], 'period end date');
    $diagnostics = smartbooksPeriodJournalDiagnostics($conn, $startDate, $endDate);
    $blockers = [];

    // The mutation route performs the same check again while holding row locks.
    // Including it in the preview prevents the user from confirming a period
    // that can never be locked because legacy or concurrently-created coverage
    // overlaps it.
    $periodId = (int) ($period['id'] ?? 0);
    $overlapStmt = $conn->prepare(
        'SELECT id, start_date, end_date FROM accounting_periods
         WHERE id <> ? AND start_date <= ? AND end_date >= ?
         ORDER BY start_date ASC, id ASC LIMIT 1'
    );
    if (!$overlapStmt) {
        throw new RuntimeException('Unable to validate overlapping accounting periods.', 500);
    }
    $overlapStmt->bind_param('iss', $periodId, $endDate, $startDate);
    $overlapStmt->execute();
    $overlap = $overlapStmt->get_result()->fetch_assoc();
    $overlapStmt->close();
    if ($overlap) {
        $blockers[] = [
            'code' => 'OVERLAPPING_PERIOD',
            'message' => "This period overlaps accounting period {$overlap['start_date']} to {$overlap['end_date']}.",
        ];
    }

    if (smartbooksPeriodValueIsTrue($period['is_locked'] ?? false)) {
        $blockers[] = ['code' => 'ALREADY_LOCKED', 'message' => 'This accounting period is already locked.'];
    }
    if (!smartbooksPeriodValueIsTrue($period['is_active'] ?? false)) {
        $blockers[] = ['code' => 'PERIOD_INACTIVE', 'message' => 'Activate the period before locking it.'];
    }
    if (!empty($diagnostics['unbalanced_journals'])) {
        $blockers[] = ['code' => 'UNBALANCED_JOURNALS', 'message' => 'One or more journals are not balanced in NGN.'];
    }
    if (!empty($diagnostics['orphan_headers']) || !empty($diagnostics['orphan_lines'])) {
        $blockers[] = ['code' => 'ORPHAN_JOURNALS', 'message' => 'One or more journal headers or lines are incomplete.'];
    }
    $warnings = [];
    if (!empty($diagnostics['header_line_mismatches'])) {
        $warnings[] = [
            'code' => 'HEADER_LINE_MISMATCH',
            'message' => 'Some legacy journal header totals differ from their journal lines. Journal lines remain the reporting authority.',
        ];
    }
    if (($diagnostics['summary']['journal_count'] ?? 0) === 0) {
        $warnings[] = ['code' => 'NO_JOURNALS', 'message' => 'There are no journal postings in this period.'];
    }

    $basis = [
        'version' => SMARTBOOKS_PERIOD_LOCK_PREVIEW_VERSION,
        'period_id' => (int) ($period['id'] ?? 0),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'is_active' => smartbooksPeriodValueIsTrue($period['is_active'] ?? false),
        'updated_at' => (string) ($period['updated_at'] ?? ''),
        'summary' => $diagnostics['summary'],
        'blockers' => $blockers,
    ];

    return [
        'period' => [
            'id' => (int) ($period['id'] ?? 0),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_locked' => smartbooksPeriodValueIsTrue($period['is_locked'] ?? false),
            'is_active' => smartbooksPeriodValueIsTrue($period['is_active'] ?? false),
            'lock_reason' => (string) ($period['lock_reason'] ?? ''),
        ],
        'diagnostics' => $diagnostics,
        'blockers' => $blockers,
        'warnings' => $warnings,
        'can_lock' => empty($blockers),
        'preview_token' => hash('sha256', json_encode($basis, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
    ];
}

function smartbooksActiveFiscalClosureOverlapping(mysqli $conn, string $startDate, string $endDate): ?array
{
    if (!smartbooksPeriodTableExists($conn, 'fiscal_year_closures')) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, closure_code, period_start, period_end, journal_id, status
         FROM fiscal_year_closures
         WHERE status = 'Posted' AND period_start <= ? AND period_end >= ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('ss', $endDate, $startDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function smartbooksLockedPeriodCoverage(mysqli $conn, string $startDate, string $endDate, bool $forUpdate = false): array
{
    $startDate = smartbooksPeriodValidateDate($startDate, 'fiscal-year start date');
    $endDate = smartbooksPeriodValidateDate($endDate, 'fiscal-year end date');
    if ($startDate > $endDate) {
        throw new RuntimeException('Fiscal-year start date cannot be later than its end date.', 422);
    }
    $coverageSql = "SELECT id, start_date, end_date, is_locked, is_active, lock_reason, updated_at
                    FROM accounting_periods
                    WHERE start_date <= ? AND end_date >= ?
                    ORDER BY start_date ASC, end_date ASC, id ASC";
    if ($forUpdate) {
        $coverageSql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($coverageSql);
    $stmt->bind_param('ss', $endDate, $startDate);
    $stmt->execute();
    $periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $blockers = [];
    if (empty($periods)) {
        $blockers[] = ['code' => 'NO_PERIODS', 'message' => 'No accounting periods cover the selected fiscal year.'];
        return ['periods' => [], 'blockers' => $blockers, 'complete' => false];
    }

    // Legacy data may contain overlaps even though the current create/update
    // routes prohibit them. A fiscal close must never proceed across ambiguous
    // period coverage.
    $previousCoverageEnd = null;
    foreach ($periods as $period) {
        $periodStart = new DateTimeImmutable((string) $period['start_date']);
        $periodEnd = new DateTimeImmutable((string) $period['end_date']);
        if ($previousCoverageEnd instanceof DateTimeImmutable && $periodStart <= $previousCoverageEnd) {
            $blockers[] = [
                'code' => 'OVERLAPPING_PERIODS',
                'message' => "Accounting period {$period['start_date']} to {$period['end_date']} overlaps another period in the fiscal-year range.",
            ];
            break;
        }
        $previousCoverageEnd = $periodEnd;
    }

    $cursor = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    $covered = [];
    foreach ($periods as $period) {
        $pStart = new DateTimeImmutable((string) $period['start_date']);
        $pEnd = new DateTimeImmutable((string) $period['end_date']);
        if ($pEnd < $cursor) {
            continue;
        }
        if ($pStart > $cursor) {
            $blockers[] = [
                'code' => 'PERIOD_GAP',
                'message' => 'There is no accounting period covering ' . $cursor->format('Y-m-d') . '.',
            ];
            break;
        }
        if (!smartbooksPeriodValueIsTrue($period['is_active'] ?? false)) {
            $blockers[] = ['code' => 'INACTIVE_PERIOD', 'message' => "Period {$period['start_date']} to {$period['end_date']} is inactive."];
        }
        if (!smartbooksPeriodValueIsTrue($period['is_locked'] ?? false)) {
            $blockers[] = ['code' => 'UNLOCKED_PERIOD', 'message' => "Period {$period['start_date']} to {$period['end_date']} is not locked."];
        }
        $covered[] = [
            'id' => (int) $period['id'],
            'start_date' => (string) $period['start_date'],
            'end_date' => (string) $period['end_date'],
            'is_locked' => smartbooksPeriodValueIsTrue($period['is_locked']),
            'is_active' => smartbooksPeriodValueIsTrue($period['is_active']),
            'updated_at' => (string) ($period['updated_at'] ?? ''),
        ];
        $cursor = $pEnd->modify('+1 day');
        if ($cursor > $end) {
            break;
        }
    }
    if ($cursor <= $end && !array_filter($blockers, static fn(array $b): bool => $b['code'] === 'PERIOD_GAP')) {
        $blockers[] = ['code' => 'PERIOD_GAP', 'message' => 'The accounting periods do not cover the full fiscal-year range.'];
    }
    if ($covered) {
        $first = $covered[0];
        $last = $covered[array_key_last($covered)];
        if ((string) $first['start_date'] !== $startDate) {
            $blockers[] = [
                'code' => 'START_NOT_PERIOD_BOUNDARY',
                'message' => "The fiscal-year start date must match the first covered accounting-period start date ({$first['start_date']}).",
            ];
        }
        if ((string) $last['end_date'] !== $endDate) {
            $blockers[] = [
                'code' => 'END_NOT_PERIOD_BOUNDARY',
                'message' => "The fiscal-year end date must match the final covered accounting-period end date ({$last['end_date']}).",
            ];
        }
    }

    return ['periods' => $covered, 'blockers' => $blockers, 'complete' => empty($blockers)];
}

function smartbooksRetainedEarningsLedger(mysqli $conn, int $ledgerNumber = SMARTBOOKS_RETAINED_EARNINGS_LEDGER): array
{
    $stmt = $conn->prepare(
        'SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
         FROM ledger_table WHERE ledger_number = ? LIMIT 1'
    );
    $stmt->bind_param('i', $ledgerNumber);
    $stmt->execute();
    $ledger = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ledger) {
        throw new RuntimeException("Retained Earnings ledger {$ledgerNumber} is not configured.", 503);
    }
    if (strcasecmp(trim((string) $ledger['ledger_class']), 'Equity') !== 0) {
        throw new RuntimeException('The configured Retained Earnings ledger must be classified as Equity.', 503);
    }
    $ledgerName = trim((string) ($ledger['ledger_name'] ?? ''));
    $ledgerType = trim((string) ($ledger['ledger_type'] ?? ''));
    if (strcasecmp($ledgerName, 'Retained Earnings') !== 0
        && strcasecmp($ledgerType, 'Retained Earnings') !== 0) {
        throw new RuntimeException(
            'The fiscal-year close must post to a ledger configured specifically as Retained Earnings.',
            503
        );
    }
    return $ledger;
}

function smartbooksFiscalYearFxReadiness(mysqli $conn, string $startDate, string $endDate): array
{
    if (!function_exists('smartbooksFxAllowedCurrencies')
        || !function_exists('smartbooksFxLoadCurrencyBalances')
        || !function_exists('smartbooksFxRateForDate')
        || !function_exists('smartbooksFxBuildRevaluationPreview')) {
        throw new RuntimeException(
            'The FX accounting helper is unavailable. Load fx_helpers.php before preparing a fiscal-year close.',
            503
        );
    }

    smartbooksFxRequireSchema($conn);
    $items = [];
    $blockers = [];
    foreach (array_keys(smartbooksFxAllowedCurrencies()) as $currency) {
        $balances = smartbooksFxLoadCurrencyBalances($conn, $currency, $endDate);
        if (empty($balances)) {
            $items[] = [
                'currency' => $currency,
                'has_open_balances' => false,
                'open_ledger_count' => 0,
                'pending_adjustment_count' => 0,
                'is_ready' => true,
            ];
            continue;
        }

        $rateData = smartbooksFxRateForDate($conn, $currency, $endDate, false);
        $preview = smartbooksFxBuildRevaluationPreview($conn, $startDate, $endDate, $currency, $rateData);
        $pendingCount = count($preview['pending_journals'] ?? []);
        $summary = $preview['summary'] ?? [];
        $item = [
            'currency' => $currency,
            'has_open_balances' => true,
            'open_ledger_count' => count($balances),
            'rate_date' => (string) ($rateData['rate_date'] ?? ''),
            'closing_rate' => round((float) ($rateData['closing_rate'] ?? 0), 8),
            'pending_adjustment_count' => $pendingCount,
            'pending_gain_ngn' => round((float) ($summary['grand_total_gain'] ?? 0), 2),
            'pending_loss_ngn' => round((float) ($summary['grand_total_loss'] ?? 0), 2),
            'pending_net_ngn' => round((float) ($summary['grand_total_net'] ?? 0), 2),
            'is_ready' => $pendingCount === 0,
        ];
        $items[] = $item;
        if ($pendingCount > 0) {
            $blockers[] = [
                'code' => 'FX_REVALUATION_REQUIRED',
                'currency' => $currency,
                'message' => "{$currency} has {$pendingCount} foreign-currency monetary balance(s) requiring revaluation at {$endDate}. Post the standard FX revaluation, relock the affected period, and generate a new fiscal-year close preview.",
            ];
        }
    }

    return [
        'items' => $items,
        'blockers' => $blockers,
        'is_ready' => empty($blockers),
    ];
}

function smartbooksProfitAndLossClosingLines(mysqli $conn, string $startDate, string $endDate): array
{
    $stmt = $conn->prepare(
        "SELECT m.ledger_name, m.ledger_number, m.ledger_class, m.ledger_class_code,
                m.ledger_sub_class, m.ledger_type,
                ROUND(COALESCE(SUM(CAST(m.debit_ngn AS DECIMAL(24,6))), 0), 2) AS total_debit_ngn,
                ROUND(COALESCE(SUM(CAST(m.credit_ngn AS DECIMAL(24,6))), 0), 2) AS total_credit_ngn
         FROM main_journal_table m
         WHERE m.journal_date BETWEEN ? AND ?
           AND (
                LOWER(TRIM(m.ledger_class)) IN ('revenue', 'expense')
                OR (m.ledger_sub_class = 'Revenue' AND m.ledger_type IN ('Revenue', 'Other Income'))
                OR (m.ledger_sub_class = 'Cost of Services' AND m.ledger_type = 'Cost of Services')
                OR (m.ledger_sub_class = 'Administrative Expenses' AND m.ledger_type = 'Administrative Expenses')
                OR (m.ledger_sub_class = 'Selling Expenses' AND m.ledger_type = 'Selling Expenses')
                OR (m.ledger_sub_class = 'Depreciation Expenses' AND m.ledger_type = 'Depreciation, Amortization & Impairment (Expenses)')
                OR (m.ledger_sub_class = 'Finance Cost' AND m.ledger_type = 'Finance Cost')
                OR (m.ledger_sub_class = 'Taxation' AND m.ledger_type = 'Income & Other Taxes')
           )
           AND NOT EXISTS (
                SELECT 1 FROM fiscal_year_closures c
                WHERE c.journal_id = m.journal_id OR c.reversal_journal_id = m.journal_id
           )
         GROUP BY m.ledger_name, m.ledger_number, m.ledger_class, m.ledger_class_code, m.ledger_sub_class, m.ledger_type
         ORDER BY m.ledger_number ASC"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate the fiscal-year profit and loss balances.', 500);
    }
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $lines = [];
    $originalDebit = 0.0;
    $originalCredit = 0.0;
    $closingDebit = 0.0;
    $closingCredit = 0.0;
    while ($row = $result->fetch_assoc()) {
        $debit = round((float) $row['total_debit_ngn'], 2);
        $credit = round((float) $row['total_credit_ngn'], 2);
        $balance = round($debit - $credit, 2);
        if (abs($balance) <= 0.009) {
            continue;
        }
        $lineDebit = $balance < 0 ? abs($balance) : 0.0;
        $lineCredit = $balance > 0 ? $balance : 0.0;
        $originalDebit += $debit;
        $originalCredit += $credit;
        $closingDebit += $lineDebit;
        $closingCredit += $lineCredit;
        $lines[] = [
            'ledger_name' => (string) $row['ledger_name'],
            'ledger_number' => (int) $row['ledger_number'],
            'ledger_class' => (string) $row['ledger_class'],
            'ledger_class_code' => (int) $row['ledger_class_code'],
            'ledger_sub_class' => (string) $row['ledger_sub_class'],
            'ledger_type' => (string) $row['ledger_type'],
            'period_debit_ngn' => $debit,
            'period_credit_ngn' => $credit,
            'balance_before_close_ngn' => $balance,
            'debit_ngn' => $lineDebit,
            'credit_ngn' => $lineCredit,
        ];
    }
    $stmt->close();

    return [
        'lines' => $lines,
        'original_debit_ngn' => round($originalDebit, 2),
        'original_credit_ngn' => round($originalCredit, 2),
        'closing_debit_before_retained_ngn' => round($closingDebit, 2),
        'closing_credit_before_retained_ngn' => round($closingCredit, 2),
        'net_profit_loss_ngn' => round($closingDebit - $closingCredit, 2),
    ];
}

function smartbooksBuildFiscalYearClosePreview(
    mysqli $conn,
    string $startDate,
    string $endDate,
    int $retainedEarningsLedgerNumber = SMARTBOOKS_RETAINED_EARNINGS_LEDGER,
    bool $lockPeriodsForUpdate = false
): array {
    smartbooksRequirePeriodSchema($conn);
    $startDate = smartbooksPeriodValidateDate($startDate, 'fiscal-year start date');
    $endDate = smartbooksPeriodValidateDate($endDate, 'fiscal-year end date');
    if ($startDate > $endDate) {
        throw new RuntimeException('Fiscal-year start date cannot be later than its end date.', 422);
    }
    $coverage = smartbooksLockedPeriodCoverage($conn, $startDate, $endDate, $lockPeriodsForUpdate);
    $diagnostics = smartbooksPeriodJournalDiagnostics($conn, $startDate, $endDate);
    $blockers = $coverage['blockers'];
    if (!empty($diagnostics['unbalanced_journals'])) {
        $blockers[] = ['code' => 'UNBALANCED_JOURNALS', 'message' => 'Resolve unbalanced journals before closing the fiscal year.'];
    }
    if (!empty($diagnostics['orphan_headers']) || !empty($diagnostics['orphan_lines'])) {
        $blockers[] = ['code' => 'ORPHAN_JOURNALS', 'message' => 'Resolve incomplete journal headers or lines before closing the fiscal year.'];
    }
    $warnings = [];
    if (!empty($diagnostics['header_line_mismatches'])) {
        $warnings[] = [
            'code' => 'HEADER_LINE_MISMATCH',
            'message' => 'Some legacy journal header totals differ from their journal lines. The closing calculation uses the journal lines.',
        ];
    }

    $existing = smartbooksActiveFiscalClosureOverlapping($conn, $startDate, $endDate);
    if ($existing) {
        $blockers[] = [
            'code' => 'ACTIVE_CLOSURE_EXISTS',
            'message' => "An active fiscal-year closure already exists for {$existing['period_start']} to {$existing['period_end']}.",
        ];
    }

    $retained = smartbooksRetainedEarningsLedger($conn, $retainedEarningsLedgerNumber);
    $fxReadiness = smartbooksFiscalYearFxReadiness($conn, $startDate, $endDate);
    $blockers = array_merge($blockers, $fxReadiness['blockers']);
    $pl = smartbooksProfitAndLossClosingLines($conn, $startDate, $endDate);
    if (empty($pl['lines'])) {
        $blockers[] = ['code' => 'NO_PL_BALANCE', 'message' => 'There are no non-zero profit-and-loss balances to close.'];
    }

    $net = (float) $pl['net_profit_loss_ngn'];
    $retainedLine = null;
    $journalLines = $pl['lines'];
    if (abs($net) > 0.009) {
        $retainedLine = [
            'ledger_name' => (string) $retained['ledger_name'],
            'ledger_number' => (int) $retained['ledger_number'],
            'ledger_class' => (string) $retained['ledger_class'],
            'ledger_class_code' => (int) $retained['ledger_class_code'],
            'ledger_sub_class' => (string) $retained['ledger_sub_class'],
            'ledger_type' => (string) $retained['ledger_type'],
            'debit_ngn' => $net < 0 ? abs($net) : 0.0,
            'credit_ngn' => $net > 0 ? $net : 0.0,
        ];
        $journalLines[] = $retainedLine;
    }
    $totalDebit = round(
        (float) $pl['closing_debit_before_retained_ngn'] + (float) ($retainedLine['debit_ngn'] ?? 0),
        2
    );
    $totalCredit = round(
        (float) $pl['closing_credit_before_retained_ngn'] + (float) ($retainedLine['credit_ngn'] ?? 0),
        2
    );
    if (abs($totalDebit - $totalCredit) > 0.01) {
        $blockers[] = ['code' => 'CLOSING_JOURNAL_UNBALANCED', 'message' => 'The calculated closing journal is not balanced.'];
    }

    $basis = [
        'version' => SMARTBOOKS_YEAR_CLOSE_PREVIEW_VERSION,
        'period_start' => $startDate,
        'period_end' => $endDate,
        'retained_earnings_ledger_number' => $retainedEarningsLedgerNumber,
        'covered_periods' => $coverage['periods'],
        'journal_summary' => $diagnostics['summary'],
        'fx_readiness' => $fxReadiness,
        'journal_lines' => $journalLines,
        'blockers' => $blockers,
    ];

    return [
        'period_start' => $startDate,
        'period_end' => $endDate,
        'closing_date' => $endDate,
        'covered_periods' => $coverage['periods'],
        'diagnostics' => $diagnostics,
        'fx_readiness' => $fxReadiness,
        'profit_and_loss_lines' => $pl['lines'],
        'retained_earnings_line' => $retainedLine,
        'journal_lines' => $journalLines,
        'total_debit_ngn' => $totalDebit,
        'total_credit_ngn' => $totalCredit,
        'net_profit_loss_ngn' => $net,
        'result' => $net > 0.009 ? 'Profit' : ($net < -0.009 ? 'Loss' : 'Break-even'),
        'blockers' => $blockers,
        'warnings' => $warnings,
        'can_post' => empty($blockers),
        'preview_token' => hash('sha256', json_encode($basis, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
    ];
}



function smartbooksBuildFiscalYearCloseReversalPreview(mysqli $conn, int $closureId, bool $forUpdate = false): array
{
    smartbooksRequirePeriodSchema($conn);
    if ($closureId <= 0) {
        throw new RuntimeException('Select a valid fiscal-year closure.', 422);
    }
    $sql = 'SELECT id, closure_code, period_start, period_end, closing_date, journal_id,
                   journal_description, status, reversal_journal_id, net_profit_loss_ngn
            FROM fiscal_year_closures WHERE id = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $closureId);
    $stmt->execute();
    $closure = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$closure) {
        throw new RuntimeException('Fiscal-year closure not found.', 404);
    }

    $lineStmt = $conn->prepare(
        'SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code,
                ledger_sub_class, ledger_type, balance_before_close_ngn,
                debit_ngn, credit_ngn, journal_line_id
         FROM fiscal_year_closure_lines WHERE closure_id = ? ORDER BY id ASC'
    );
    $lineStmt->bind_param('i', $closureId);
    $lineStmt->execute();
    $lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lineStmt->close();

    $coverage = smartbooksLockedPeriodCoverage(
        $conn,
        (string) $closure['period_start'],
        (string) $closure['period_end'],
        $forUpdate
    );
    $blockers = $coverage['blockers'];
    if (strcasecmp((string) $closure['status'], 'Posted') !== 0 || !empty($closure['reversal_journal_id'])) {
        $blockers[] = ['code' => 'ALREADY_REVERSED', 'message' => 'This fiscal-year closure is not active.'];
    }
    if (empty($lines)) {
        $blockers[] = ['code' => 'NO_CLOSING_LINES', 'message' => 'The original closing journal lines are unavailable.'];
    }

    $journalIntegrity = [
        'expected_line_count' => count($lines),
        'matching_line_count' => 0,
    ];
    if ($lines) {
        $integrityStmt = $conn->prepare(
            "SELECT COUNT(*) AS matching_line_count
             FROM fiscal_year_closure_lines l
             INNER JOIN main_journal_table m
               ON m.id = l.journal_line_id
              AND m.journal_id = ?
              AND ABS(CAST(m.debit_ngn AS DECIMAL(24,6)) - l.debit_ngn) <= 0.01
              AND ABS(CAST(m.credit_ngn AS DECIMAL(24,6)) - l.credit_ngn) <= 0.01
              AND m.ledger_number = l.ledger_number
             WHERE l.closure_id = ?"
        );
        if (!$integrityStmt) {
            throw new RuntimeException('Unable to verify the original closing journal.', 500);
        }
        $closingJournalId = (int) $closure['journal_id'];
        $integrityStmt->bind_param('ii', $closingJournalId, $closureId);
        $integrityStmt->execute();
        $journalIntegrity['matching_line_count'] = (int) (
            $integrityStmt->get_result()->fetch_assoc()['matching_line_count'] ?? 0
        );
        $integrityStmt->close();
        if ($journalIntegrity['matching_line_count'] !== $journalIntegrity['expected_line_count']) {
            $blockers[] = [
                'code' => 'CLOSING_JOURNAL_CHANGED',
                'message' => 'The original closing journal no longer matches its stored fiscal-close audit lines.',
            ];
        }
    }

    $reversalLines = array_map(static function (array $line): array {
        return [
            'ledger_name' => (string) $line['ledger_name'],
            'ledger_number' => (int) $line['ledger_number'],
            'ledger_class' => (string) $line['ledger_class'],
            'ledger_class_code' => (int) $line['ledger_class_code'],
            'ledger_sub_class' => (string) $line['ledger_sub_class'],
            'ledger_type' => (string) $line['ledger_type'],
            'debit_ngn' => round((float) $line['credit_ngn'], 2),
            'credit_ngn' => round((float) $line['debit_ngn'], 2),
            'original_journal_line_id' => (int) $line['journal_line_id'],
        ];
    }, $lines);
    $totalDebit = round(array_sum(array_column($reversalLines, 'debit_ngn')), 2);
    $totalCredit = round(array_sum(array_column($reversalLines, 'credit_ngn')), 2);
    if (abs($totalDebit - $totalCredit) > 0.01) {
        $blockers[] = ['code' => 'REVERSAL_UNBALANCED', 'message' => 'The reversal journal is not balanced.'];
    }

    $basis = [
        'version' => 1,
        'closure_id' => $closureId,
        'closure_code' => (string) $closure['closure_code'],
        'status' => (string) $closure['status'],
        'journal_id' => (int) $closure['journal_id'],
        'closing_date' => (string) $closure['closing_date'],
        'covered_periods' => $coverage['periods'],
        'journal_integrity' => $journalIntegrity,
        'lines' => $reversalLines,
        'blockers' => $blockers,
    ];

    return [
        'closure' => $closure,
        'reversal_date' => (string) $closure['closing_date'],
        'covered_periods' => $coverage['periods'],
        'journal_integrity' => $journalIntegrity,
        'journal_lines' => $reversalLines,
        'total_debit_ngn' => $totalDebit,
        'total_credit_ngn' => $totalCredit,
        'blockers' => $blockers,
        'can_reverse' => empty($blockers),
        'preview_token' => hash('sha256', json_encode($basis, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
    ];
}

function smartbooksPeriodRateSnapshot(mysqli $conn, string $asOfDate): array
{
    $asOfDate = smartbooksPeriodValidateDate($asOfDate, 'rate date');
    $stmt = $conn->prepare(
        'SELECT effective_date, ngn_rate, usd_rate, eur_rate, gbp_rate
         FROM currency_table WHERE effective_date <= ?
         ORDER BY effective_date DESC, id DESC LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load the exchange-rate snapshot.', 500);
    }
    $stmt->bind_param('s', $asOfDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException("No exchange-rate record exists on or before {$asOfDate}.", 422);
    }
    return [
        'rate_date' => (string) $row['effective_date'],
        'ngn_rate' => (float) $row['ngn_rate'],
        'usd_rate' => (float) $row['usd_rate'],
        'eur_rate' => (float) $row['eur_rate'],
        'gbp_rate' => (float) $row['gbp_rate'],
    ];
}

function smartbooksNextJournalId(mysqli $conn): int
{
    $stmt = $conn->prepare(
        "UPDATE accounting_sequences
         SET sequence_value = LAST_INSERT_ID(sequence_value + 1)
         WHERE sequence_name = 'journal_id'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to allocate the next journal ID. Apply the accounting-period migration first.', 503);
    }
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The journal ID sequence is not configured.', 503);
    }
    $stmt->close();

    $valueStmt = $conn->prepare('SELECT LAST_INSERT_ID() AS journal_id');
    if (!$valueStmt) {
        throw new RuntimeException('Unable to read the allocated journal ID.', 500);
    }
    $valueStmt->execute();
    $journalId = (int) ($valueStmt->get_result()->fetch_assoc()['journal_id'] ?? 0);
    $valueStmt->close();
    if ($journalId <= 100) {
        throw new RuntimeException('The allocated journal ID is invalid.', 500);
    }
    return $journalId;
}
