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
    'Only Admin or Controller users can validate a journal invoice payment.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException('Enter the invoice number to settle.', 422);
}
$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
$journalId = (int) ($payload['journal_id'] ?? 0);
$paymentId = (int) ($payload['payment_id'] ?? 0);

if ($paymentId > 0 && $journalId <= 0) {
    throw new RuntimeException('Select the linked journal before managing its payment.', 422);
}

if ($journalId > 0) {
    $storedJournal = invoicePaymentManualLinkLoadJournal($conn, $journalId, false);
    $journal = invoicePaymentRegistrationNormalisePersistedJournal($storedJournal);
} else {
    $draftPayload = isset($payload['journal']) && is_array($payload['journal'])
        ? $payload['journal']
        : $payload;
    $journal = invoicePaymentRegistrationDraftJournal($conn, $draftPayload);
}

if ($paymentId > 0) {
    $linkedPayment = invoicePaymentManualLinkLoadPayment($conn, $paymentId);
    if ((int) ($linkedPayment['journal_id'] ?? 0) !== $journalId) {
        throw new RuntimeException('The selected payment is not linked to this journal.', 409);
    }
    if (strcasecmp((string) ($linkedPayment['status'] ?? ''), 'Active') !== 0) {
        throw new RuntimeException('Only an active payment link can be managed.', 409);
    }
    if (!empty($linkedPayment['reversal_journal_id'])) {
        throw new RuntimeException('A reversed payment link cannot be managed.', 409);
    }
}

$preview = invoicePaymentRegistrationAnalyse(
    $conn,
    $invoice,
    $journal,
    $journalId,
    $paymentId
);

jsonResponse([
    'status' => 'Success',
    'message' => 'The journal matches the invoice settlement and can be registered.',
    'data' => $preview,
]);
