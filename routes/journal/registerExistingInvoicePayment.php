<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_payment_registration_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can register an existing journal as an invoice payment.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
$journalId = (int) ($payload['journal_id'] ?? 0);
$previewToken = trim((string) ($payload['preview_token'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException('Enter the invoice number to settle.', 422);
}
if ($journalId <= 0) {
    throw new RuntimeException('Select a valid journal.', 422);
}
if ($previewToken === '') {
    throw new RuntimeException('Preview the invoice-payment registration before saving it.', 422);
}

$metadata = [
    'payment_method' => trim((string) ($payload['payment_method'] ?? '')),
    'transaction_reference' => trim((string) ($payload['transaction_reference'] ?? '')),
    'notes' => trim((string) ($payload['notes'] ?? '')),
];

$conn->begin_transaction();
try {
    $lockStmt = $conn->prepare(
        'SELECT invoice_number FROM invoice_table WHERE invoice_number = ? LIMIT 1 FOR UPDATE'
    );
    if (!$lockStmt) {
        throw new RuntimeException('Unable to lock the invoice for payment registration.', 500);
    }
    $lockStmt->bind_param('s', $invoiceNumber);
    $lockStmt->execute();
    $lockedInvoice = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if (!$lockedInvoice) {
        throw new RuntimeException("Invoice #{$invoiceNumber} was not found.", 404);
    }

    $invoice = fetchInvoiceBundle($conn, $invoiceNumber);
    $storedJournal = invoicePaymentManualLinkLoadJournal($conn, $journalId, true);
    $journal = invoicePaymentRegistrationNormalisePersistedJournal($storedJournal);
    $analysis = invoicePaymentRegistrationAnalyse($conn, $invoice, $journal, $journalId);
    $payment = invoicePaymentRegistrationPersist(
        $conn,
        $invoice,
        $analysis,
        $journalId,
        $user,
        $metadata,
        $previewToken
    );

    $conn->commit();
    jsonResponse([
        'status' => 'Success',
        'message' => 'The journal was registered as a validated invoice payment without posting another journal.',
        'data' => $payment,
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
