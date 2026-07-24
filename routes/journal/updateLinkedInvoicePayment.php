<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_payment_registration_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can manage a linked journal payment.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$paymentId = (int) ($payload['payment_id'] ?? 0);
$journalId = (int) ($payload['journal_id'] ?? 0);
$invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
$previewToken = trim((string) ($payload['preview_token'] ?? ''));

if ($paymentId <= 0) {
    throw new RuntimeException('Select a valid linked payment.', 422);
}
if ($journalId <= 0) {
    throw new RuntimeException('Select a valid linked journal.', 422);
}
if ($invoiceNumber === '') {
    throw new RuntimeException('Select the invoice to allocate this journal payment to.', 422);
}
if ($previewToken === '') {
    throw new RuntimeException('Preview and validate the payment link before saving it.', 422);
}

$metadata = [
    'payment_method' => trim((string) ($payload['payment_method'] ?? '')),
    'transaction_reference' => trim((string) ($payload['transaction_reference'] ?? '')),
    'notes' => trim((string) ($payload['notes'] ?? '')),
];

$conn->begin_transaction();
try {
    $payment = invoicePaymentManualLinkLoadPayment($conn, $paymentId, '', true);
    if ((int) ($payment['journal_id'] ?? 0) !== $journalId) {
        throw new RuntimeException('The selected payment is not linked to this journal.', 409);
    }
    if (strcasecmp((string) ($payment['journal_validation_status'] ?? ''), 'Validated') !== 0) {
        throw new RuntimeException('Only a validated journal payment link can be managed.', 409);
    }
    if (strcasecmp((string) ($payment['status'] ?? ''), 'Active') !== 0) {
        throw new RuntimeException('Only an active payment link can be managed.', 409);
    }
    if (!empty($payment['reversal_journal_id'])) {
        throw new RuntimeException('A reversed payment link cannot be managed.', 409);
    }

    $invoiceNumbersToLock = array_values(array_unique(array_filter([
        trim((string) ($payment['invoice_number'] ?? '')),
        $invoiceNumber,
    ])));
    sort($invoiceNumbersToLock, SORT_STRING);

    $invoiceLockStmt = $conn->prepare(
        'SELECT invoice_number FROM invoice_table WHERE invoice_number = ? LIMIT 1 FOR UPDATE'
    );
    if (!$invoiceLockStmt) {
        throw new RuntimeException('Unable to lock the invoice for payment-link management.', 500);
    }
    foreach ($invoiceNumbersToLock as $numberToLock) {
        $invoiceLockStmt->bind_param('s', $numberToLock);
        $invoiceLockStmt->execute();
        $lockedInvoice = $invoiceLockStmt->get_result()->fetch_assoc();
        if (!$lockedInvoice) {
            $invoiceLockStmt->close();
            throw new RuntimeException("Invoice #{$numberToLock} was not found.", 404);
        }
    }
    $invoiceLockStmt->close();

    $invoice = fetchInvoiceBundle($conn, $invoiceNumber);
    $storedJournal = invoicePaymentManualLinkLoadJournal($conn, $journalId, true);
    $journal = invoicePaymentRegistrationNormalisePersistedJournal($storedJournal);
    $analysis = invoicePaymentRegistrationAnalyse(
        $conn,
        $invoice,
        $journal,
        $journalId,
        $paymentId
    );

    $updatedPayment = invoicePaymentRegistrationUpdateLinkedPayment(
        $conn,
        $payment,
        $analysis,
        $user,
        $metadata,
        $previewToken
    );

    $conn->commit();
    jsonResponse([
        'status' => 'Success',
        'message' => 'The invoice payment link was revalidated and updated without changing the journal entry.',
        'data' => $updatedPayment,
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
