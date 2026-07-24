<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_payment_manual_journal_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can view manual payment-journal candidates.'
);

$paymentId = (int) ($_GET['payment_id'] ?? 0);
$paymentCode = trim((string) ($_GET['payment_code'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
if ($paymentId <= 0 && $paymentCode === '') {
    throw new RuntimeException('Select a payment.', 422);
}

$payment = invoicePaymentManualLinkLoadPayment($conn, $paymentId, $paymentCode, false);
if (strcasecmp((string) $payment['status'], 'Active') !== 0) {
    throw new RuntimeException('Only an active payment can be linked to a journal.', 409);
}
if ((bool) $payment['post_journal']) {
    throw new RuntimeException('This payment already uses automatic journal posting.', 409);
}

$sql =
    "SELECT j.journal_id, j.journal_date, j.journal_type, j.transaction_type,
            j.journal_currency, j.journal_description, j.debit_ngn, j.credit_ngn,
            j.created_by, j.created_at
     FROM journal_table j
     WHERE j.journal_date = ?
       AND NOT EXISTS (
           SELECT 1 FROM invoice_payments p
           WHERE p.journal_id = j.journal_id AND p.id <> ?
       )";
$searchMode = 'none';
$searchJournalId = 0;
$searchLike = '';
if ($search !== '') {
    if (ctype_digit($search)) {
        $sql .= ' AND j.journal_id = ?';
        $searchMode = 'id';
        $searchJournalId = (int) $search;
    } else {
        $sql .= ' AND (j.journal_description LIKE ? OR j.created_by LIKE ?)';
        $searchMode = 'text';
        $searchLike = '%' . $search . '%';
    }
}
$sql .= ' ORDER BY j.journal_id DESC LIMIT 100';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    throw new RuntimeException('Unable to load candidate journals.', 500);
}
$paymentDate = (string) $payment['payment_date'];
$actualPaymentId = (int) $payment['id'];
if ($searchMode === 'id') {
    $stmt->bind_param('sii', $paymentDate, $actualPaymentId, $searchJournalId);
} elseif ($searchMode === 'text') {
    $stmt->bind_param('siss', $paymentDate, $actualPaymentId, $searchLike, $searchLike);
} else {
    $stmt->bind_param('si', $paymentDate, $actualPaymentId);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$candidates = [];
$seen = [];
foreach ($rows as $row) {
    $journalId = (int) $row['journal_id'];
    if (isset($seen[$journalId])) {
        continue;
    }
    $seen[$journalId] = true;
    try {
        $validation = invoicePaymentManualLinkValidate($conn, $payment, $journalId, false);
        $candidates[] = [
            'journal_id' => $journalId,
            'journal_date' => (string) $row['journal_date'],
            'journal_type' => (string) $row['journal_type'],
            'transaction_type' => (string) $row['transaction_type'],
            'journal_currency' => strtoupper((string) $row['journal_currency']),
            'description' => (string) $row['journal_description'],
            'total_debit_ngn' => (float) $row['debit_ngn'],
            'total_credit_ngn' => (float) $row['credit_ngn'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
            'can_link' => (bool) $validation['can_link'],
            'blockers' => $validation['blockers'],
            'warnings' => $validation['warnings'],
            'preview_token' => $validation['preview_token'],
        ];
    } catch (Throwable $error) {
        $candidates[] = [
            'journal_id' => $journalId,
            'journal_date' => (string) $row['journal_date'],
            'journal_type' => (string) $row['journal_type'],
            'transaction_type' => (string) $row['transaction_type'],
            'journal_currency' => strtoupper((string) $row['journal_currency']),
            'description' => (string) $row['journal_description'],
            'total_debit_ngn' => (float) $row['debit_ngn'],
            'total_credit_ngn' => (float) $row['credit_ngn'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
            'can_link' => false,
            'blockers' => [[
                'code' => 'VALIDATION_ERROR',
                'message' => publicErrorMessage($error),
            ]],
            'warnings' => [],
            'preview_token' => null,
        ];
    }
}

usort($candidates, static function (array $a, array $b): int {
    if ($a['can_link'] !== $b['can_link']) {
        return $a['can_link'] ? -1 : 1;
    }
    return $b['journal_id'] <=> $a['journal_id'];
});

jsonResponse([
    'status' => 'Success',
    'data' => [
        'payment' => [
            'id' => (int) $payment['id'],
            'payment_code' => (string) $payment['payment_code'],
            'invoice_number' => (string) $payment['invoice_number'],
            'payment_date' => (string) $payment['payment_date'],
            'invoice_currency' => (string) $payment['invoice_currency'],
            'invoice_amount_settled' => (float) $payment['invoice_amount_settled'],
            'payment_currency' => (string) $payment['payment_currency'],
            'payment_amount_received' => (float) $payment['payment_amount_received'],
            'journal_origin' => (string) $payment['journal_origin'],
            'journal_validation_status' => (string) $payment['journal_validation_status'],
        ],
        'candidates' => $candidates,
    ],
]);
