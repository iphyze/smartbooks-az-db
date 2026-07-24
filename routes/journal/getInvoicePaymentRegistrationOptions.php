<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can load invoice payment registration options.'
);

$search = trim((string) ($_GET['search'] ?? ''));
$includeFullyRegistered = filter_var(
    $_GET['include_fully_registered'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);
$excludeJournalId = max(0, (int) ($_GET['exclude_journal_id'] ?? 0));
$limit = (int) ($_GET['limit'] ?? 100);
$limit = max(1, min($limit, 500));

$params = [];
$types = '';
$registrationJournalFilter = '';
if ($excludeJournalId > 0) {
    $registrationJournalFilter = ' AND p.journal_id <> ?';
    $params[] = $excludeJournalId;
    $types .= 'i';
}

$where = '';
if ($search !== '') {
    $where = 'WHERE (
        i.invoice_number LIKE ?
        OR i.clients_name LIKE ?
        OR i.currency LIKE ?
        OR i.status LIKE ?
        OR i.workflow_status LIKE ?
    )';
    $likeSearch = '%' . $search . '%';
    foreach (range(1, 5) as $_) {
        $params[] = $likeSearch;
        $types .= 's';
    }
}

$invoiceTotalExpression = "CAST(REPLACE(COALESCE(NULLIF(TRIM(i.invoice_amount), ''), '0'), ',', '') AS DECIMAL(18,2))";
$registeredExpression = 'COALESCE(reg.registered_amount, 0)';
$availableExpression = "GREATEST({$invoiceTotalExpression} - {$registeredExpression}, 0)";

$sql = "
    SELECT
        i.id,
        i.invoice_number,
        i.invoice_date,
        i.clients_name,
        i.clients_id,
        i.currency,
        i.status,
        i.workflow_status,
        i.due_date,
        CAST(COALESCE(i.paid, 0) AS DECIMAL(18,2)) AS recorded_paid,
        {$invoiceTotalExpression} AS invoice_total,
        {$registeredExpression} AS registered_amount,
        {$availableExpression} AS available_to_register
    FROM invoice_table i
    LEFT JOIN (
        SELECT
            a.invoice_number,
            SUM(a.allocated_amount) AS registered_amount
        FROM invoice_payment_allocations a
        INNER JOIN invoice_payments p ON p.id = a.payment_id
        WHERE p.status = 'Active'
          AND p.journal_id IS NOT NULL
          AND p.journal_validation_status = 'Validated'
          {$registrationJournalFilter}
        GROUP BY a.invoice_number
    ) reg ON reg.invoice_number = i.invoice_number
    {$where}
";

if (!$includeFullyRegistered) {
    $sql .= " HAVING available_to_register > 0.009\n";
}

$sql .= "
    ORDER BY
        CASE WHEN available_to_register > 0.009 THEN 0 ELSE 1 END ASC,
        COALESCE(STR_TO_DATE(i.invoice_date, '%Y-%m-%d'), i.created_at) DESC,
        i.id DESC
    LIMIT ?
";

$params[] = $limit;
$types .= 'i';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare the invoice payment option list.', 500);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$options = array_map(static function (array $row): array {
    $invoiceTotal = round((float) ($row['invoice_total'] ?? 0), 2);
    $registeredAmount = round((float) ($row['registered_amount'] ?? 0), 2);
    $available = round(max(0, (float) ($row['available_to_register'] ?? 0)), 2);

    return [
        'id' => (int) $row['id'],
        'invoice_number' => trim((string) $row['invoice_number']),
        'invoice_date' => (string) $row['invoice_date'],
        'clients_name' => trim((string) $row['clients_name']),
        'clients_id' => (int) $row['clients_id'],
        'currency' => strtoupper(trim((string) $row['currency'])),
        'status' => trim((string) $row['status']),
        'workflow_status' => trim((string) $row['workflow_status']),
        'due_date' => (string) $row['due_date'],
        'invoice_total' => $invoiceTotal,
        'recorded_paid' => round((float) ($row['recorded_paid'] ?? 0), 2),
        'registered_amount' => $registeredAmount,
        'available_to_register' => $available,
        'is_fully_registered' => $available <= 0.009,
        'registration_status' => $available <= 0.009
            ? 'Fully registered'
            : ($registeredAmount > 0.009 ? 'Partially registered' : 'Not registered'),
    ];
}, $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Invoice payment registration options fetched successfully.',
    'data' => $options,
    'meta' => [
        'count' => count($options),
        'limit' => $limit,
        'search' => $search,
        'include_fully_registered' => $includeFullyRegistered,
        'exclude_journal_id' => $excludeJournalId,
    ],
]);
