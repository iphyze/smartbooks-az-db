<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';
require_once __DIR__ . '/../../utils/invoice_payment_manual_journal_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can unlink a manual payment journal.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}
[$paymentId, $paymentCode] = invoicePaymentManualLinkResolvePaymentIdentifier($payload);
$reason = trim((string) ($payload['reason'] ?? ''));
if (mb_strlen($reason) < 5) {
    throw new RuntimeException('Enter a clear unlink reason of at least 5 characters.', 422);
}
if (mb_strlen($reason) > 500) {
    throw new RuntimeException('Unlink reason cannot exceed 500 characters.', 422);
}

$conn->begin_transaction();
try {
    $payment = invoicePaymentManualLinkLoadPayment($conn, $paymentId, $paymentCode, true);
    if (strcasecmp((string) $payment['status'], 'Active') !== 0) {
        throw new RuntimeException('Only an active payment can have its manual journal unlinked.', 409);
    }
    if (strcasecmp((string) $payment['journal_origin'], 'Manual') !== 0 || empty($payment['journal_id'])) {
        throw new RuntimeException('This payment does not have a linked manual journal.', 409);
    }
    if (!empty($payment['reversal_journal_id'])) {
        throw new RuntimeException('A reversed payment journal cannot be unlinked.', 409);
    }

    $journalId = (int) $payment['journal_id'];
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));

    $updateStmt = $conn->prepare(
        "UPDATE invoice_payments
         SET journal_id = NULL,
             journal_origin = 'Unposted',
             journal_validation_status = 'Pending',
             journal_validation_hash = NULL,
             journal_validation_snapshot = NULL,
             journal_narration = NULL,
             bank_ledger_number = NULL,
             journal_posted_at = NULL,
             journal_linked_at = NULL,
             journal_linked_by_user_id = NULL,
             journal_linked_by_email = NULL,
             journal_unlinked_at = NOW(),
             journal_unlinked_by_user_id = ?,
             journal_unlinked_by_email = ?,
             journal_unlink_reason = ?,
             updated_at = NOW()
         WHERE id = ?
           AND journal_id = ?
           AND journal_origin = 'Manual'"
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to unlink the manual journal.', 500);
    }
    $actualPaymentId = (int) $payment['id'];
    $updateStmt->bind_param('issii', $userId, $userEmail, $reason, $actualPaymentId, $journalId);
    $updateStmt->execute();
    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new RuntimeException('The journal could not be unlinked because the payment state changed.', 409);
    }
    $updateStmt->close();

    invoicePaymentManualLinkRecordEvent(
        $conn,
        $payment,
        $journalId,
        'Unlinked',
        'Pending',
        $user,
        $reason,
        (string) ($payment['journal_validation_hash'] ?? ''),
        null
    );

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if ($logStmt) {
        $action = "{$userEmail} unlinked manual Journal #{$journalId} from invoice payment {$payment['payment_code']}. Reason: {$reason}";
        $logStmt->bind_param('iss', $userId, $action, $userEmail);
        $logStmt->execute();
        $logStmt->close();
    }

    notifyAccountingUsers(
        $conn,
        'invoice_payment_journal_unlinked',
        'invoice',
        "Manual journal unlinked from payment {$payment['payment_code']}",
        "{$userEmail} unlinked Journal #{$journalId}. Reason: {$reason}",
        'warning',
        'invoice',
        (string) $payment['invoice_number'],
        "/invoice/view/{$payment['invoice_number']}",
        [
            'payment_id' => (int) $payment['id'],
            'payment_code' => (string) $payment['payment_code'],
            'journal_id' => $journalId,
            'reason' => $reason,
        ],
        $userId
    );

    $conn->commit();
    jsonResponse([
        'status' => 'Success',
        'message' => 'The manual journal was unlinked. The payment now requires a validated journal.',
        'data' => [
            'payment_id' => (int) $payment['id'],
            'payment_code' => (string) $payment['payment_code'],
            'journal_id' => $journalId,
            'journal_origin' => 'Unposted',
            'journal_validation_status' => 'Pending',
        ],
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
