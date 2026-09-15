<?php
declare(strict_types=1);

require_once __DIR__ . '/text_normalization.php';

const SMARTBOOKS_COST_CENTER_ACCESS_ALL = 'all';
const SMARTBOOKS_COST_CENTER_ACCESS_RESTRICTED = 'restricted';
const SMARTBOOKS_COST_CENTER_ACCESS_MODES = [
    SMARTBOOKS_COST_CENTER_ACCESS_ALL,
    SMARTBOOKS_COST_CENTER_ACCESS_RESTRICTED,
];

function normalizeCostCenterAccessMode(mixed $value): string
{
    $mode = strtolower(trim((string) $value));
    return in_array($mode, SMARTBOOKS_COST_CENTER_ACCESS_MODES, true)
        ? $mode
        : SMARTBOOKS_COST_CENTER_ACCESS_ALL;
}

function normalizeCostCenterName(string $value): string
{
    $value = smartbooksCanonicalName($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function userHasAllCostCenterAccess(array $user): bool
{
    return normalizeCostCenterAccessMode($user['cost_center_access_mode'] ?? SMARTBOOKS_COST_CENTER_ACCESS_ALL)
        === SMARTBOOKS_COST_CENTER_ACCESS_ALL;
}

function fetchUserCostCenterAccess(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        'SELECT c.id, c.name
         FROM user_cost_center_access ucca
         INNER JOIN cost_center_table c ON c.id = ucca.cost_center_id
         WHERE ucca.user_id = ?
         ORDER BY c.name ASC'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load cost centre access.', 500);
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
    ], $rows);
}

function costCenterIdsFromPayload(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $item) {
        $id = (int) (is_array($item) ? ($item['id'] ?? 0) : $item);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function validateCostCenterSelection(mysqli $conn, string $mode, array $costCenterIds): array
{
    $mode = normalizeCostCenterAccessMode($mode);
    if ($mode === SMARTBOOKS_COST_CENTER_ACCESS_ALL) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $costCenterIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        throw new RuntimeException('Select at least one cost centre for restricted access.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT id FROM cost_center_table WHERE is_active = 1 AND id IN ($placeholders)");
    if (!$stmt) {
        throw new RuntimeException('Unable to validate selected cost centres.', 500);
    }
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $valid = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $stmt->close();

    sort($valid);
    $expected = $ids;
    sort($expected);
    if ($valid !== $expected) {
        throw new RuntimeException('One or more selected cost centres are invalid or inactive.', 400);
    }

    return $ids;
}

function replaceUserCostCenterAccess(
    mysqli $conn,
    int $userId,
    string $mode,
    array $costCenterIds,
    string $actorEmail
): void {
    $mode = normalizeCostCenterAccessMode($mode);
    $ids = validateCostCenterSelection($conn, $mode, $costCenterIds);

    $delete = $conn->prepare('DELETE FROM user_cost_center_access WHERE user_id = ?');
    if (!$delete) {
        throw new RuntimeException('Unable to update cost centre access.', 500);
    }
    $delete->bind_param('i', $userId);
    $delete->execute();
    $delete->close();

    if ($mode === SMARTBOOKS_COST_CENTER_ACCESS_ALL) {
        return;
    }

    $insert = $conn->prepare(
        'INSERT INTO user_cost_center_access (user_id, cost_center_id, created_by)
         VALUES (?, ?, ?)'
    );
    if (!$insert) {
        throw new RuntimeException('Unable to save cost centre access.', 500);
    }

    foreach ($ids as $costCenterId) {
        $insert->bind_param('iis', $userId, $costCenterId, $actorEmail);
        $insert->execute();
    }
    $insert->close();
}

function hydrateUserCostCenterAccess(mysqli $conn, array $user): array
{
    $user['cost_center_access_mode'] = normalizeCostCenterAccessMode(
        $user['cost_center_access_mode'] ?? SMARTBOOKS_COST_CENTER_ACCESS_ALL
    );
    $user['cost_centers'] = $user['cost_center_access_mode'] === SMARTBOOKS_COST_CENTER_ACCESS_RESTRICTED
        ? fetchUserCostCenterAccess($conn, (int) ($user['id'] ?? 0))
        : [];
    return $user;
}

function userCanAccessCostCenter(mysqli $conn, array $user, string $costCenter): bool
{
    if (userHasAllCostCenterAccess($user)) {
        return true;
    }

    $normalized = normalizeCostCenterName($costCenter);
    if ($normalized === '') {
        return false;
    }

    $userId = (int) ($user['id'] ?? 0);
    $stmt = $conn->prepare(
        'SELECT 1
         FROM user_cost_center_access ucca
         INNER JOIN cost_center_table c ON c.id = ucca.cost_center_id
         WHERE ucca.user_id = ?
           AND c.normalized_name = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate cost centre access.', 500);
    }
    $stmt->bind_param('is', $userId, $normalized);
    $stmt->execute();
    $allowed = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $allowed;
}

function requireCostCenterAccess(
    mysqli $conn,
    array $user,
    string $costCenter,
    string $message = 'You do not have access to this cost centre.'
): void {
    if (!userCanAccessCostCenter($conn, $user, $costCenter)) {
        // Deliberately use 404 so restricted users cannot probe the existence of another division's records.
        throw new RuntimeException($message, 404);
    }
}

function allowedCostCenterNames(mysqli $conn, array $user): ?array
{
    if (userHasAllCostCenterAccess($user)) {
        return null;
    }

    return array_map(
        static fn(array $row): string => (string) $row['name'],
        fetchUserCostCenterAccess($conn, (int) ($user['id'] ?? 0))
    );
}

/**
 * Appends a cost-centre visibility predicate to an existing WHERE clause.
 * The caller must pass a trusted/static column expression (for example
 * `journal_table.cost_center`). The authenticated user id is appended to the
 * supplied bind parameter arrays when restricted access is active.
 */
function appendCostCenterVisibilityScope(
    array $user,
    string $columnExpression,
    string &$sql,
    array &$params,
    string &$types
): void {
    if (userHasAllCostCenterAccess($user)) {
        return;
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        // Authentication should already guarantee a valid id. Fail closed if a
        // malformed user object ever reaches a scoped query.
        $sql .= ' AND 1 = 0';
        return;
    }

    $sql .= " AND EXISTS (
        SELECT 1
        FROM user_cost_center_access sb_ucca
        INNER JOIN cost_center_table sb_cc
            ON sb_cc.id = sb_ucca.cost_center_id
        WHERE sb_ucca.user_id = ?
          AND sb_cc.normalized_name = LOWER(TRIM({$columnExpression}))
    )";
    $params[] = $userId;
    $types .= 'i';
}

/**
 * Loads a journal header and enforces cost-centre visibility before an action
 * continues. A not-found response is intentional for restricted users so the
 * existence of another division's journal cannot be probed by id.
 */
function requireJournalCostCenterAccess(
    mysqli $conn,
    array $user,
    int $journalId,
    bool $forUpdate = false
): array {
    if ($journalId <= 0) {
        throw new RuntimeException('Journal not found.', 404);
    }

    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT id, journal_id, cost_center
         FROM journal_table
         WHERE journal_id = ?
         LIMIT 1{$suffix}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate journal access.', 500);
    }

    $stmt->bind_param('i', $journalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Journal not found.', 404);
    }

    requireCostCenterAccess(
        $conn,
        $user,
        (string) ($row['cost_center'] ?? ''),
        'Journal not found.'
    );

    return $row;
}

/**
 * Keeps the cost-centre master in sync with values posted by unrestricted
 * users. Restricted users can only post centres already assigned to them, so
 * their values necessarily already exist in the master table.
 */
function ensureCostCenterMasterRecord(mysqli $conn, array $user, string $costCenter): void
{
    if (!userHasAllCostCenterAccess($user)) {
        return;
    }

    $name = smartbooksCanonicalName($costCenter);
    if ($name === '') {
        return;
    }

    $normalized = normalizeCostCenterName($name);
    $actor = trim((string) ($user['email'] ?? 'system')) ?: 'system';

    $stmt = $conn->prepare(
        'INSERT INTO cost_center_table (name, normalized_name, is_active, created_by, updated_by)
         VALUES (?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            is_active = 1,
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to synchronise the cost centre.', 500);
    }
    $stmt->bind_param('ssss', $name, $normalized, $actor, $actor);
    $stmt->execute();
    $stmt->close();
}

/**
 * Loads an invoice header and enforces the authenticated user's cost-centre
 * scope. Legacy invoices created before dedicated invoice cost centres existed
 * fall back to the client name, matching the historic invoice-journal behaviour.
 */
function requireInvoiceCostCenterAccess(
    mysqli $conn,
    array $user,
    string $invoiceNumber,
    bool $forUpdate = false
): array {
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        throw new RuntimeException('Invoice not found.', 404);
    }

    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT id, invoice_number, clients_name,
                COALESCE(NULLIF(TRIM(cost_center), ''), clients_name) AS cost_center
         FROM invoice_table
         WHERE invoice_number = ?
         LIMIT 1{$suffix}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate invoice access.', 500);
    }

    $stmt->bind_param('s', $invoiceNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Invoice not found.', 404);
    }

    requireCostCenterAccess(
        $conn,
        $user,
        (string) ($row['cost_center'] ?? ''),
        'Invoice not found.'
    );

    return $row;
}

/**
 * Enforces that a submitted invoice cost centre is visible to the current user
 * and, for unrestricted users, synchronises the value into the master list.
 */
function validateInvoiceCostCenterSelection(mysqli $conn, array $user, string $costCenter): string
{
    $costCenter = smartbooksCanonicalName($costCenter);
    if ($costCenter === '') {
        throw new RuntimeException('Select a cost centre for this invoice.', 422);
    }

    if (!userHasAllCostCenterAccess($user)) {
        requireCostCenterAccess(
            $conn,
            $user,
            $costCenter,
            'You do not have access to the selected invoice cost centre.'
        );
    } else {
        ensureCostCenterMasterRecord($conn, $user, $costCenter);
    }

    return $costCenter;
}

/**
 * Returns a SQL predicate for read-only aggregation/report queries where using
 * bind-array mutation would make the surrounding legacy query unnecessarily
 * complex. The user id is cast to int and the column expression is restricted
 * to a simple table/column identifier before interpolation.
 */
function costCenterVisibilityPredicate(array $user, string $columnExpression): string
{
    if (userHasAllCostCenterAccess($user)) {
        return '1=1';
    }

    if (!preg_match('/^[A-Za-z0-9_.]+$/', $columnExpression)) {
        throw new InvalidArgumentException('Invalid cost-centre column expression.');
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return '1=0';
    }

    return "EXISTS (
        SELECT 1
        FROM user_cost_center_access sb_ucca
        INNER JOIN cost_center_table sb_cc
            ON sb_cc.id = sb_ucca.cost_center_id
        WHERE sb_ucca.user_id = {$userId}
          AND sb_cc.normalized_name = LOWER(TRIM({$columnExpression}))
    )";
}

/**
 * Returns an SQL fragment that limits main-journal rows to the authenticated
 * user's assigned cost centres. The column expression must be a trusted/static
 * SQL identifier such as `m.cost_center` or `main_journal_table.cost_center`.
 *
 * This variant embeds only the authenticated integer user id, which makes it
 * practical for report queries that already have complex prepared bindings.
 */
function costCenterReportScopeSql(array $user, string $columnExpression): string
{
    if (userHasAllCostCenterAccess($user)) {
        return '';
    }

    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $columnExpression)) {
        throw new InvalidArgumentException('Invalid cost-centre report column expression.');
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return ' AND 1 = 0';
    }

    return " AND EXISTS (
        SELECT 1
        FROM user_cost_center_access sb_report_ucca
        INNER JOIN cost_center_table sb_report_cc
            ON sb_report_cc.id = sb_report_ucca.cost_center_id
        WHERE sb_report_ucca.user_id = {$userId}
          AND sb_report_cc.normalized_name = LOWER(TRIM({$columnExpression}))
    )";
}

function costCenterScopeMeta(array $user): array
{
    return [
        'mode' => userHasAllCostCenterAccess($user) ? 'all' : 'restricted',
    ];
}

/**
 * Validates a generic transaction/report cost centre against the current user.
 * Restricted users may only select assigned centres; unrestricted users keep
 * the historic free-form behaviour and the master list is synchronised.
 */
function validateTransactionalCostCenterSelection(
    mysqli $conn,
    array $user,
    string $costCenter,
    string $fieldLabel = 'cost centre'
): string {
    $costCenter = smartbooksCanonicalName($costCenter);
    if ($costCenter === '') {
        throw new RuntimeException("Select a {$fieldLabel}.", 422);
    }

    if (!userHasAllCostCenterAccess($user)) {
        requireCostCenterAccess(
            $conn,
            $user,
            $costCenter,
            "You do not have access to the selected {$fieldLabel}."
        );
    } else {
        ensureCostCenterMasterRecord($conn, $user, $costCenter);
    }

    return $costCenter;
}

/**
 * Global controls such as period locks and fiscal-year closing affect the
 * entire company ledger and therefore cannot safely be executed from a
 * restricted cost-centre session.
 */
function requireAllCostCenterAccessForGlobalAccounting(
    array $user,
    string $message = 'This company-wide accounting action requires All Cost Centres access.'
): void {
    if (!userHasAllCostCenterAccess($user)) {
        throw new RuntimeException($message, 403);
    }
}

/**
 * Loads a bank reconciliation and enforces its persisted cost-centre scope.
 */
function requireBankReconCostCenterAccess(
    mysqli $conn,
    array $user,
    int $reconId,
    bool $forUpdate = false,
    string $table = 'bank_recons',
    string $idColumn = 'id'
): array {
    if ($reconId <= 0) {
        throw new RuntimeException('Reconciliation not found.', 404);
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $idColumn)) {
        throw new InvalidArgumentException('Invalid reconciliation table configuration.');
    }

    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT *, COALESCE(NULLIF(TRIM(cost_center), ''), NULLIF(TRIM(company_name), '')) AS sb_cost_center\n"
        . "FROM {$table} WHERE {$idColumn} = ? LIMIT 1{$suffix}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate reconciliation access.', 500);
    }
    $stmt->bind_param('i', $reconId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Reconciliation not found.', 404);
    }

    requireCostCenterAccess(
        $conn,
        $user,
        (string) ($row['sb_cost_center'] ?? ''),
        'Reconciliation not found.'
    );

    return $row;
}
