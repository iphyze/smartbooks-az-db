<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);
$data = json_decode(file_get_contents('php://input'), true);
$invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required.', 400);
}

$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
$invoiceDate = new DateTimeImmutable('today');
$storedTermsDays = $invoice['payment_terms_days'] === null ? null : (int) $invoice['payment_terms_days'];
if ($storedTermsDays !== null) {
    $termDays = max(0, $storedTermsDays);
    $dueDate = $invoiceDate->modify("+{$termDays} days");
} else {
    $sourceInvoiceDate = new DateTimeImmutable((string) $invoice['invoice_date']);
    $sourceDueDate = new DateTimeImmutable((string) $invoice['due_date']);
    $termDays = max(0, (int) $sourceInvoiceDate->diff($sourceDueDate)->format('%r%a'));
    $dueDate = $invoiceDate->modify("+{$termDays} days");
}

$payload = [
    'invoiceDetails' => [
        'invoice_date' => $invoiceDate->format('Y-m-d'),
        'due_date' => $dueDate->format('Y-m-d'),
        'payment_terms_days' => $storedTermsDays,
        'payment_terms_label' => (string) ($invoice['payment_terms_label'] ?? ($storedTermsDays === 0 ? 'Due on receipt' : "Net {$termDays} days")),
        'clients_name' => (string) $invoice['clients_name'],
        'clients_id' => (string) $invoice['clients_id'],
        'project' => (string) ($invoice['project'] ?? ''),
        'currency' => (string) ($invoice['currency'] ?? 'NGN'),
        'tin_number' => (string) ($invoice['tin_number'] ?? 'No'),
        'bank_id' => null,
        'bank_name' => (string) ($invoice['bank_name'] ?? ''),
        'account_name' => (string) ($invoice['account_name'] ?? ''),
        'account_number' => (string) ($invoice['account_number'] ?? ''),
        'account_currency' => (string) ($invoice['account_currency'] ?? ''),
        'rate_date' => (string) ($invoice['rate_date'] ?? ''),
        'post_jv' => 'No',
    ],
    'invoiceItems' => array_map(static fn (array $item, int $index): array => [
        'sn' => $index + 1,
        'service_catalogue_id' => isset($item['service_catalogue_id']) ? (int) $item['service_catalogue_id'] : null,
        'description' => (string) ($item['description'] ?? ''),
        'amount' => (string) ($item['amount'] ?? ''),
        'discount' => (string) ($item['discount_percent'] ?? '0'),
        'vat' => (string) ($item['vat_percent'] ?? '0'),
        'wht' => (string) ($item['wht_percent'] ?? '0'),
    ], $invoice['items'], array_keys($invoice['items'])),
    'source_invoice_number' => $invoiceNumber,
];

$draftUuid = generateUuidV4();
$draftKey = 'duplicate:' . $draftUuid;
$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$userId = (int) $user['id'];
$userEmail = (string) $user['email'];
$mode = 'create';

$stmt = $conn->prepare(
    'INSERT INTO invoice_drafts
        (draft_uuid, draft_key, mode, invoice_number, payload, created_by_user_id, created_by_email, last_saved_at)
     VALUES (?, ?, ?, NULL, ?, ?, ?, CURRENT_TIMESTAMP)'
);
$stmt->bind_param('ssssis', $draftUuid, $draftKey, $mode, $payloadJson, $userId, $userEmail);
$stmt->execute();
$stmt->close();

$logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
$action = "{$userEmail} prepared a duplicate draft from Invoice #{$invoiceNumber}";
$logStmt->bind_param('iss', $userId, $action, $userEmail);
$logStmt->execute();
$logStmt->close();

jsonResponse([
    'status' => 'Success',
    'message' => 'A duplicate invoice draft has been prepared.',
    'data' => [
        'draft_uuid' => $draftUuid,
        'source_invoice_number' => $invoiceNumber,
    ],
]);
