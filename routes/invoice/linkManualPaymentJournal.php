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
    'Only Admin or Controller users can link a manual payment journal.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

[$paymentId, $paymentCode] = invoicePaymentManualLinkResolvePaymentIdentifier($payload);
$journalId = (int) ($payload['journal_id'] ?? 0);
$previewToken = trim((string) ($payload['preview_token'] ?? ''));
if ($journalId <= 0) {
    throw new RuntimeException('Select a journal to link.', 422);
}
if ($previewToken === '') {
    throw new RuntimeException('Preview and validate the manual journal before linking it.', 422);
}

$conn->begin_transaction();
try {
    $payment = invoicePaymentManualLinkLoadPayment($conn, $paymentId, $paymentCode, true);
    $preview = invoicePaymentManualLinkValidate($conn, $payment, $journalId, true);
    if (!$preview['can_link']) {
        throw new RuntimeException(
            'The journal no longer satisfies the payment validation requirements. Generate a new preview and correct the listed blockers.',
            409
        );
    }
    if (!hash_equals((string) $preview['preview_token'], $previewToken)) {
        throw new RuntimeException(
            'The payment or journal changed after the preview was generated. Refresh the preview before linking.',
            409
        );
    }

    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $journalNarration = (string) $preview['journal']['description'];
    $receivingLedger = (int) ($preview['candidate_debit_ledger_number'] ?? 0);
    if ($receivingLedger <= 0) {
        throw new RuntimeException('The receiving ledger could not be identified from the journal.', 409);
    }
    $validationHash = (string) $preview['preview_token'];
    $snapshotJson = json_encode(
        $preview['validation_snapshot'],
        JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
    );

    $updateStmt = $conn->prepare(
        "UPDATE invoice_payments
         SET journal_id = ?,
             journal_origin = 'Manual',
             journal_validation_status = 'Validated',
             journal_validation_hash = ?,
             journal_validation_snapshot = ?,
             journal_narration = ?,
             bank_ledger_number = ?,
             journal_posted_at = NOW(),
             journal_linked_at = NOW(),
             journal_linked_by_user_id = ?,
             journal_linked_by_email = ?,
             journal_unlinked_at = NULL,
             journal_unlinked_by_user_id = NULL,
             journal_unlinked_by_email = NULL,
             journal_unlink_reason = NULL,
             updated_at = NOW()
         WHERE id = ?
           AND status = 'Active'
           AND post_journal = 0
           AND (journal_id IS NULL OR journal_id = ?)"
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to link the manual journal to the payment.', 500);
    }
    $actualPaymentId = (int) $payment['id'];
    $updateStmt->bind_param(
        'isssiisii',
        $journalId,
        $validationHash,
        $snapshotJson,
        $journalNarration,
        $receivingLedger,
        $userId,
        $userEmail,
        $actualPaymentId,
        $journalId
    );
    $updateStmt->execute();
    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new RuntimeException('The payment could not be linked because its state changed.', 409);
    }
    $updateStmt->close();

    invoicePaymentManualLinkRecordEvent(
        $conn,
        $payment,
        $journalId,
        'Linked',
        'Validated',
        $user,
        null,
        $validationHash,
        $preview['validation_snapshot']
    );

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if ($logStmt) {
        $action = "{$userEmail} linked manual Journal #{$journalId} to invoice payment {$payment['payment_code']}";
        $logStmt->bind_param('iss', $userId, $action, $userEmail);
        $logStmt->execute();
        $logStmt->close();
    }

    notifyAccountingUsers(
        $conn,
        'invoice_payment_journal_linked',
        'invoice',
        "Manual journal linked to payment {$payment['payment_code']}",
        "{$userEmail} validated and linked Journal #{$journalId} to Invoice #{$payment['invoice_number']}.",
        'info',
        'invoice',
        (string) $payment['invoice_number'],
        "/invoice/view/{$payment['invoice_number']}",
        [
            'payment_id' => (int) $payment['id'],
            'payment_code' => (string) $payment['payment_code'],
            'journal_id' => $journalId,
            'journal_origin' => 'Manual',
            'journal_validation_status' => 'Validated',
        ],
        $userId
    );

    $conn->commit();

    jsonResponse([
        'status' => 'Success',
        'message' => 'The manual journal was validated and linked to the payment.',
        'data' => [
            'payment_id' => (int) $payment['id'],
            'payment_code' => (string) $payment['payment_code'],
            'invoice_number' => (string) $payment['invoice_number'],
            'journal_id' => $journalId,
            'journal_origin' => 'Manual',
            'journal_validation_status' => 'Validated',
            'receiving_ledger_number' => $receivingLedger,
            'validation' => $preview,
        ],
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
