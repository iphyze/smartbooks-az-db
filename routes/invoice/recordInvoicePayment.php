<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';
require_once __DIR__ . '/../../utils/invoice_payment_journal_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can record invoice payments.'
);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid request payload.', 400);
}

$invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
$paymentDate = trim((string) ($payload['payment_date'] ?? ''));
$amount = round((float) ($payload['amount'] ?? 0), 2);
$currency = strtoupper(trim((string) ($payload['currency'] ?? '')));
$paymentMethod = trim((string) ($payload['payment_method'] ?? ''));
$bankId = isset($payload['bank_id']) && $payload['bank_id'] !== '' ? (int) $payload['bank_id'] : null;
$transactionReference = trim((string) ($payload['transaction_reference'] ?? ''));
$notes = trim((string) ($payload['notes'] ?? ''));
$postJournal = filter_var($payload['post_journal'] ?? false, FILTER_VALIDATE_BOOLEAN);
$bankLedgerNumber = (int) ($payload['bank_ledger_number'] ?? 0);
$creditLedgerNumber = (int) ($payload['credit_ledger_number'] ?? 0);

if ($invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required.', 422);
}

$date = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
if (!$date || $date->format('Y-m-d') !== $paymentDate) {
    throw new RuntimeException('Enter a valid payment date.', 422);
}
if ($paymentDate > date('Y-m-d')) {
    throw new RuntimeException('Payment date cannot be in the future.', 422);
}
if ($amount <= 0) {
    throw new RuntimeException('Payment amount must be greater than zero.', 422);
}

$allowedMethods = ['Bank Transfer', 'Cash', 'Cheque', 'Card', 'Other'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    throw new RuntimeException('Select a valid payment method.', 422);
}

$conn->begin_transaction();

try {
    $invoice = fetchInvoiceBundle($conn, $invoiceNumber);
    $workflowStatus = (string) ($invoice['workflow_status'] ?? 'Issued');
    if (in_array($workflowStatus, ['Cancelled', 'Void'], true)) {
        throw new RuntimeException("A {$workflowStatus} invoice cannot receive payments.", 409);
    }

    $invoiceCurrency = strtoupper(trim((string) ($invoice['currency'] ?? '')));
    if ($currency === '') {
        $currency = $invoiceCurrency;
    }
    if ($currency !== $invoiceCurrency) {
        throw new RuntimeException("Payments for this invoice must be recorded in {$invoiceCurrency}.", 422);
    }

    $summary = invoicePaymentSummary($conn, $invoiceNumber, (float) ($invoice['invoice_amount'] ?? 0));
    $balanceDue = (float) $summary['balance_due'];
    if ($balanceDue <= 0.009) {
        throw new RuntimeException('This invoice is already fully paid.', 409);
    }
    if ($amount > $balanceDue + 0.009) {
        throw new RuntimeException(
            'Payment amount cannot be greater than the outstanding balance of ' . number_format($balanceDue, 2) . ' ' . $currency . '.',
            422
        );
    }

    $requiresBank = in_array($paymentMethod, ['Bank Transfer', 'Cheque', 'Card'], true);
    if ($requiresBank && (!$bankId || $bankId <= 0)) {
        throw new RuntimeException('Select the bank account that received this payment.', 422);
    }
    if ($postJournal && $bankLedgerNumber <= 0) {
        throw new RuntimeException('Select the ledger to debit for this receipt.', 422);
    }

    $bankName = null;
    $accountName = null;
    $accountNumber = null;
    if ($bankId && $bankId > 0) {
        $bankStmt = $conn->prepare(
            'SELECT id, bank_name, account_name, account_number, account_currency
             FROM bank_table
             WHERE id = ?
             LIMIT 1'
        );
        if (!$bankStmt) {
            throw new RuntimeException('Unable to validate the selected bank account.', 500);
        }
        $bankStmt->bind_param('i', $bankId);
        $bankStmt->execute();
        $bank = $bankStmt->get_result()->fetch_assoc();
        $bankStmt->close();

        if (!$bank) {
            throw new RuntimeException('The selected bank account is no longer available.', 422);
        }
        if (strtoupper((string) $bank['account_currency']) !== $currency) {
            throw new RuntimeException("Select a {$currency} bank account for this payment.", 422);
        }

        $bankName = (string) $bank['bank_name'];
        $accountName = (string) $bank['account_name'];
        $accountNumber = (string) $bank['account_number'];
    }

    if ($transactionReference !== '') {
        $duplicateStmt = $conn->prepare(
            "SELECT id
             FROM invoice_payments
             WHERE invoice_number = ?
               AND transaction_reference = ?
               AND status = 'Active'
             LIMIT 1"
        );
        if (!$duplicateStmt) {
            throw new RuntimeException('Unable to validate the payment reference.', 500);
        }
        $duplicateStmt->bind_param('ss', $invoiceNumber, $transactionReference);
        $duplicateStmt->execute();
        $duplicate = $duplicateStmt->get_result()->fetch_assoc();
        $duplicateStmt->close();

        if ($duplicate) {
            throw new RuntimeException('This transaction reference has already been used for the invoice.', 409);
        }
    }

    $paymentCode = generateInvoicePaymentCode($conn);
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $nullableBankId = $bankId && $bankId > 0 ? $bankId : null;
    $nullableTransactionReference = $transactionReference !== '' ? $transactionReference : null;
    $nullableNotes = $notes !== '' ? $notes : null;
    $postJournalFlag = $postJournal ? 1 : 0;

    $paymentStmt = $conn->prepare(
        'INSERT INTO invoice_payments
            (payment_code, invoice_number, payment_date, amount, currency, payment_method,
             bank_id, bank_name, account_name, account_number, transaction_reference, notes,
             post_journal, status, recorded_by_user_id, recorded_by_email)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Active\', ?, ?)'
    );
    if (!$paymentStmt) {
        throw new RuntimeException('Unable to record the payment.', 500);
    }

    $paymentStmt->bind_param(
        'sssdssisssssiis',
        $paymentCode,
        $invoiceNumber,
        $paymentDate,
        $amount,
        $currency,
        $paymentMethod,
        $nullableBankId,
        $bankName,
        $accountName,
        $accountNumber,
        $nullableTransactionReference,
        $nullableNotes,
        $postJournalFlag,
        $userId,
        $userEmail
    );
    $paymentStmt->execute();
    $paymentId = (int) $paymentStmt->insert_id;
    $paymentStmt->close();

    $allocationStmt = $conn->prepare(
        'INSERT INTO invoice_payment_allocations (payment_id, invoice_number, allocated_amount)
         VALUES (?, ?, ?)'
    );
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to allocate the payment to the invoice.', 500);
    }
    $allocationStmt->bind_param('isd', $paymentId, $invoiceNumber, $amount);
    $allocationStmt->execute();
    $allocationStmt->close();

    $journalPosting = null;
    if ($postJournal) {
        $isCompleteReceipt = round(max(0, $balanceDue - $amount), 2) <= 0.009;
        $journalPosting = postInvoicePaymentJournal(
            $conn,
            $invoice,
            $paymentDate,
            $amount,
            $bankLedgerNumber,
            $creditLedgerNumber,
            $isCompleteReceipt,
            $user
        );

        $journalId = (int) $journalPosting['journal_id'];
        $journalNarration = (string) $journalPosting['narration'];
        $customerLedgerNumber = (int) $journalPosting['credit_ledger']['ledger_number'];
        $updatePaymentJournalStmt = $conn->prepare(
            'UPDATE invoice_payments
             SET journal_id = ?,
                 journal_narration = ?,
                 bank_ledger_number = ?,
                 customer_ledger_number = ?,
                 journal_posted_at = NOW()
             WHERE id = ?'
        );
        if (!$updatePaymentJournalStmt) {
            throw new RuntimeException('Unable to link the receipt journal to the payment.', 500);
        }
        $updatePaymentJournalStmt->bind_param(
            'isiii',
            $journalId,
            $journalNarration,
            $bankLedgerNumber,
            $customerLedgerNumber,
            $paymentId
        );
        $updatePaymentJournalStmt->execute();
        $updatePaymentJournalStmt->close();
    }

    $updatedSummary = syncInvoicePaymentState(
        $conn,
        $invoiceNumber,
        $user,
        "Payment {$paymentCode} was recorded."
    );

    notifyAccountingUsers(
        $conn,
        'invoice_payment_recorded',
        'invoice',
        $updatedSummary['status'] === 'Paid'
            ? "Invoice #{$invoiceNumber} is fully paid"
            : "Payment recorded for Invoice #{$invoiceNumber}",
        number_format($amount, 2) . " {$currency} was recorded by {$userEmail}." .
            ($journalPosting ? " Receipt journal #{$journalPosting['journal_id']} was posted." : ''),
        'info',
        'invoice',
        $invoiceNumber,
        "/invoice/view/{$invoiceNumber}",
        [
            'payment_code' => $paymentCode,
            'amount' => $amount,
            'currency' => $currency,
            'journal_id' => $journalPosting['journal_id'] ?? null,
        ],
        $userId
    );

    $conn->commit();

    jsonResponse([
        'status' => 'Success',
        'message' => $postJournal
            ? ($updatedSummary['status'] === 'Paid'
                ? 'Payment recorded and complete receipt journal posted.'
                : 'Payment recorded and part receipt journal posted.')
            : ($updatedSummary['status'] === 'Paid'
                ? 'Payment recorded. The invoice is now fully paid.'
                : 'Payment recorded successfully.'),
        'data' => [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'payment_summary' => $updatedSummary,
            'journal' => $journalPosting ? [
                'journal_id' => (int) $journalPosting['journal_id'],
                'narration' => (string) $journalPosting['narration'],
                'rate_date' => (string) $journalPosting['rate_date'],
            ] : null,
        ],
    ], 201);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
