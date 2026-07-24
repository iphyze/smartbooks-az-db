<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_payment_manual_journal_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can validate a manual payment journal.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

[$paymentId, $paymentCode] = invoicePaymentManualLinkResolvePaymentIdentifier($payload);
$journalId = (int) ($payload['journal_id'] ?? 0);
if ($journalId <= 0) {
    throw new RuntimeException('Select a journal to validate.', 422);
}

$payment = invoicePaymentManualLinkLoadPayment($conn, $paymentId, $paymentCode, false);
$preview = invoicePaymentManualLinkValidate($conn, $payment, $journalId, false);

jsonResponse([
    'status' => 'Success',
    'message' => $preview['can_link']
        ? 'The manual journal matches the payment and can be linked.'
        : 'The manual journal does not yet match the payment.',
    'data' => $preview,
]);
