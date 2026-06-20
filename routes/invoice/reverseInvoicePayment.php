<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can reverse invoice payments.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$paymentId = (int) ($payload['payment_id'] ?? 0);
$reason = trim((string) ($payload['reason'] ?? ''));

if ($paymentId <= 0) {
    throw new RuntimeException('Select a valid payment to reverse.', 422);
}
if (mb_strlen($reason) < 5) {
    throw new RuntimeException('Enter a clear reversal reason of at least 5 characters.', 422);
}
if (mb_strlen($reason) > 500) {
    throw new RuntimeException('Reversal reason cannot exceed 500 characters.', 422);
}

$conn->begin_transaction();

try {
    $paymentStmt = $conn->prepare(
        'SELECT id, payment_code, invoice_number, amount, currency, status
         FROM invoice_payments
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$paymentStmt) {
        throw new RuntimeException('Unable to load the payment.', 500);
    }
    $paymentStmt->bind_param('i', $paymentId);
    $paymentStmt->execute();
    $payment = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();

    if (!$payment) {
        throw new RuntimeException('Payment record not found.', 404);
    }
    if (strcasecmp((string) $payment['status'], 'Reversed') === 0) {
        throw new RuntimeException('This payment has already been reversed.', 409);
    }

    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));

    $updateStmt = $conn->prepare(
        "UPDATE invoice_payments
         SET status = 'Reversed',
             reversed_at = NOW(),
             reversed_by_user_id = ?,
             reversed_by_email = ?,
             reversal_reason = ?,
             updated_at = NOW()
         WHERE id = ?
           AND status = 'Active'"
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to reverse the payment.', 500);
    }
    $updateStmt->bind_param('issi', $userId, $userEmail, $reason, $paymentId);
    $updateStmt->execute();
    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new RuntimeException('The payment could not be reversed.', 409);
    }
    $updateStmt->close();

    $invoiceNumber = (string) $payment['invoice_number'];
    $updatedSummary = syncInvoicePaymentState(
        $conn,
        $invoiceNumber,
        $user,
        "Payment {$payment['payment_code']} was reversed: {$reason}"
    );

    notifyAccountingUsers(
        $conn,
        'invoice_payment_reversed',
        'invoice',
        "Payment reversed for Invoice #{$invoiceNumber}",
        "{$userEmail} reversed payment {$payment['payment_code']}. Reason: {$reason}",
        'warning',
        'invoice',
        $invoiceNumber,
        "/invoice/view/{$invoiceNumber}",
        ['payment_code' => $payment['payment_code'], 'reason' => $reason],
        $userId
    );

    $conn->commit();

    jsonResponse([
        'status' => 'Success',
        'message' => 'Payment reversed successfully.',
        'data' => [
            'payment_id' => $paymentId,
            'payment_summary' => $updatedSummary,
        ],
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
