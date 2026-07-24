<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';
require_once __DIR__ . '/../../utils/invoice_payment_journal_helpers.php';
require_once __DIR__ . '/../../utils/invoice_payment_manual_journal_helpers.php';

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

$nullableFloat = static function (array $source, array $keys): ?float {
    foreach ($keys as $key) {
        if (array_key_exists($key, $source) && $source[$key] !== '' && $source[$key] !== null) {
            return (float) $source[$key];
        }
    }
    return null;
};

$invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
$paymentDate = trim((string) ($payload['payment_date'] ?? ''));
$invoiceAmountSettled = round((float) ($payload['invoice_amount_settled'] ?? $payload['amount'] ?? 0), 2);
$requestedPaymentCurrency = strtoupper(trim((string) ($payload['payment_currency'] ?? $payload['currency'] ?? '')));
$paymentAmountReceived = $nullableFloat($payload, ['payment_amount_received', 'received_amount']);
$crossCurrencyRate = $nullableFloat($payload, ['cross_currency_rate', 'payment_exchange_rate']);
$paymentCurrencyRateNgn = $nullableFloat($payload, ['payment_currency_rate_ngn']);
$rateEffectiveDate = trim((string) ($payload['payment_rate_date'] ?? $payload['settlement_rate_date'] ?? $paymentDate));
$paymentMethod = trim((string) ($payload['payment_method'] ?? ''));
$bankId = isset($payload['bank_id']) && $payload['bank_id'] !== '' ? (int) $payload['bank_id'] : null;
$transactionReference = trim((string) ($payload['transaction_reference'] ?? ''));
$notes = trim((string) ($payload['notes'] ?? ''));
$postJournal = filter_var($payload['post_journal'] ?? false, FILTER_VALIDATE_BOOLEAN);
$bankLedgerNumber = (int) ($payload['bank_ledger_number'] ?? $payload['debit_ledger_number'] ?? 0);
$creditLedgerNumber = (int) ($payload['credit_ledger_number'] ?? 0);
$journalPreviewToken = trim((string) ($payload['journal_preview_token'] ?? $payload['preview_token'] ?? ''));

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
$rateDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rateEffectiveDate);
if (!$rateDate || $rateDate->format('Y-m-d') !== $rateEffectiveDate) {
    throw new RuntimeException('Enter a valid payment rate date.', 422);
}
if ($rateEffectiveDate > $paymentDate) {
    throw new RuntimeException('Payment rate date cannot be later than the payment date.', 422);
}
if ($invoiceAmountSettled <= 0) {
    throw new RuntimeException('Invoice amount settled must be greater than zero.', 422);
}

$allowedMethods = ['Bank Transfer', 'Cash', 'Cheque', 'Card', 'Other'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    throw new RuntimeException('Select a valid payment method.', 422);
}
if ($postJournal && $bankLedgerNumber <= 0) {
    throw new RuntimeException('Select the ledger to debit for this receipt.', 422);
}
if ($postJournal && $journalPreviewToken === '') {
    throw new RuntimeException('Preview the payment journal before posting it.', 422);
}

$conn->begin_transaction();

try {
    // A payment changes the invoice subledger even when journal posting is disabled.
    // The check is inside the transaction so it serialises with a concurrent period lock.
    assertInvoicePaymentPostingDateOpen($conn, $paymentDate);

    // Serialize receipts against the same invoice so concurrent requests cannot
    // allocate more than the outstanding invoice-currency balance.
    $invoiceLockStmt = $conn->prepare(
        'SELECT id FROM invoice_table WHERE invoice_number = ? LIMIT 1 FOR UPDATE'
    );
    if (!$invoiceLockStmt) {
        throw new RuntimeException('Unable to lock the invoice for payment recording.', 500);
    }
    $invoiceLockStmt->bind_param('s', $invoiceNumber);
    $invoiceLockStmt->execute();
    $lockedInvoice = $invoiceLockStmt->get_result()->fetch_assoc();
    $invoiceLockStmt->close();
    if (!$lockedInvoice) {
        throw new RuntimeException('Invoice not found.', 404);
    }

    $invoice = fetchInvoiceBundle($conn, $invoiceNumber);
    $workflowStatus = (string) ($invoice['workflow_status'] ?? 'Issued');
    if (in_array($workflowStatus, ['Cancelled', 'Void'], true)) {
        throw new RuntimeException("A {$workflowStatus} invoice cannot receive payments.", 409);
    }

    $invoiceCurrency = invoicePaymentNormaliseCurrency(
        (string) ($invoice['currency'] ?? ''),
        'Invoice currency'
    );
    $paymentCurrency = $requestedPaymentCurrency !== ''
        ? invoicePaymentNormaliseCurrency($requestedPaymentCurrency, 'Payment currency')
        : $invoiceCurrency;
    if ($paymentAmountReceived === null && $invoiceCurrency === $paymentCurrency) {
        $paymentAmountReceived = $invoiceAmountSettled;
    }
    $amounts = normaliseInvoicePaymentAmounts(
        $invoiceCurrency,
        $paymentCurrency,
        $invoiceAmountSettled,
        $paymentAmountReceived,
        $crossCurrencyRate
    );

    $summary = invoicePaymentSummary($conn, $invoiceNumber, (float) ($invoice['invoice_amount'] ?? 0));
    $balanceDue = (float) $summary['balance_due'];
    if ($balanceDue <= 0.009) {
        throw new RuntimeException('This invoice is already fully paid.', 409);
    }
    if ((float) $amounts['invoice_amount_settled'] > $balanceDue + 0.009) {
        throw new RuntimeException(
            'Invoice amount settled cannot be greater than the outstanding balance of ' .
            number_format($balanceDue, 2) . ' ' . $invoiceCurrency . '.',
            422
        );
    }

    $requiresBank = in_array($paymentMethod, ['Bank Transfer', 'Cheque', 'Card'], true);
    if ($requiresBank && (!$bankId || $bankId <= 0)) {
        throw new RuntimeException('Select the bank account that received this payment.', 422);
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
        if (strtoupper((string) $bank['account_currency']) !== $paymentCurrency) {
            throw new RuntimeException("Select a {$paymentCurrency} bank account for this payment.", 422);
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

    // Calculate and retain the expected accounting values even when the user
    // chooses to post the journal manually later.
    $settlement = calculateInvoicePaymentSettlement(
        $conn,
        $invoice,
        $paymentDate,
        (float) $amounts['invoice_amount_settled'],
        $paymentCurrency,
        (float) $amounts['payment_amount_received'],
        (float) $amounts['cross_currency_rate'],
        $creditLedgerNumber,
        $rateEffectiveDate,
        $paymentCurrencyRateNgn
    );

    $paymentCode = generateInvoicePaymentCode($conn);
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $nullableBankId = $bankId && $bankId > 0 ? $bankId : null;
    $nullableTransactionReference = $transactionReference !== '' ? $transactionReference : null;
    $nullableNotes = $notes !== '' ? $notes : null;
    $postJournalFlag = $postJournal ? 1 : 0;
    $nullableBankLedgerNumber = $bankLedgerNumber > 0 ? $bankLedgerNumber : null;
    $customerLedgerNumber = (int) $settlement['credit_ledger']['ledger_number'];
    $realizedFxLedgerNumber = $settlement['realized_fx_ledger_number'] !== null
        ? (int) $settlement['realized_fx_ledger_number']
        : null;

    $paymentStmt = $conn->prepare(
        'INSERT INTO invoice_payments
            (payment_code, invoice_number, payment_date, amount, currency,
             invoice_currency, invoice_amount_settled, payment_currency, payment_amount_received,
             cross_currency_rate, payment_rate_date, payment_currency_rate_ngn,
             payment_method, bank_id, bank_name, account_name, account_number,
             transaction_reference, notes, post_journal, bank_ledger_number, customer_ledger_number,
             settlement_rate_date, settlement_rate, settlement_value_ngn, carrying_rate,
             carrying_value_settled_ngn, realized_fx_gain_ngn, realized_fx_loss_ngn,
             realized_fx_ledger_number, status, recorded_by_user_id, recorded_by_email)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Active\', ?, ?)'
    );
    if (!$paymentStmt) {
        throw new RuntimeException('Unable to record the payment.', 500);
    }

    $paymentAmount = (float) $settlement['payment_amount_received'];
    $invoiceSettled = (float) $settlement['invoice_amount_settled'];
    $crossRate = (float) $settlement['cross_currency_rate'];
    $paymentRateNgn = (float) $settlement['payment_currency_rate_ngn'];
    $settlementRateDate = (string) $settlement['rate_date'];
    $settlementRate = (float) $settlement['settlement_rate'];
    $settlementValueNgn = (float) $settlement['settlement_value_ngn'];
    $carryingRate = (float) $settlement['carrying_rate'];
    $carryingValueSettledNgn = (float) $settlement['carrying_value_settled_ngn'];
    $realizedFxGainNgn = (float) $settlement['realized_fx_gain_ngn'];
    $realizedFxLossNgn = (float) $settlement['realized_fx_loss_ngn'];

    $paymentStmt->bind_param(
        'sssdssdsddsdsisssssiiisddddddiis',
        $paymentCode,
        $invoiceNumber,
        $paymentDate,
        $paymentAmount,
        $paymentCurrency,
        $invoiceCurrency,
        $invoiceSettled,
        $paymentCurrency,
        $paymentAmount,
        $crossRate,
        $rateEffectiveDate,
        $paymentRateNgn,
        $paymentMethod,
        $nullableBankId,
        $bankName,
        $accountName,
        $accountNumber,
        $nullableTransactionReference,
        $nullableNotes,
        $postJournalFlag,
        $nullableBankLedgerNumber,
        $customerLedgerNumber,
        $settlementRateDate,
        $settlementRate,
        $settlementValueNgn,
        $carryingRate,
        $carryingValueSettledNgn,
        $realizedFxGainNgn,
        $realizedFxLossNgn,
        $realizedFxLedgerNumber,
        $userId,
        $userEmail
    );
    $paymentStmt->execute();
    $paymentId = (int) $paymentStmt->insert_id;
    $paymentStmt->close();

    $allocationStmt = $conn->prepare(
        'INSERT INTO invoice_payment_allocations
            (payment_id, invoice_number, allocated_amount, allocation_currency)
         VALUES (?, ?, ?, ?)'
    );
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to allocate the payment to the invoice.', 500);
    }
    $allocationStmt->bind_param('isds', $paymentId, $invoiceNumber, $invoiceSettled, $invoiceCurrency);
    $allocationStmt->execute();
    $allocationStmt->close();

    $journalPosting = null;
    if ($postJournal) {
        $isCompleteReceipt = round(max(0, $balanceDue - $invoiceSettled), 2) <= 0.009;
        $journalPosting = postInvoicePaymentJournal(
            $conn,
            $invoice,
            $paymentDate,
            $invoiceSettled,
            $paymentCurrency,
            $paymentAmount,
            $crossRate,
            $bankLedgerNumber,
            $creditLedgerNumber,
            $isCompleteReceipt,
            $user,
            $journalPreviewToken,
            $rateEffectiveDate,
            $paymentCurrencyRateNgn
        );

        $journalId = (int) $journalPosting['journal_id'];
        $journalNarration = (string) $journalPosting['narration'];
        $customerLedgerNumber = (int) $journalPosting['credit_ledger']['ledger_number'];
        $settlementRateDate = (string) $journalPosting['rate_date'];
        $paymentRateNgn = (float) $journalPosting['payment_currency_rate_ngn'];
        $settlementRate = (float) $journalPosting['settlement_rate'];
        $settlementValueNgn = (float) $journalPosting['settlement_value_ngn'];
        $carryingRate = (float) $journalPosting['carrying_rate'];
        $carryingValueSettledNgn = (float) $journalPosting['carrying_value_settled_ngn'];
        $realizedFxGainNgn = (float) $journalPosting['realized_fx_gain_ngn'];
        $realizedFxLossNgn = (float) $journalPosting['realized_fx_loss_ngn'];
        $realizedFxLedgerNumber = $journalPosting['realized_fx_ledger_number'] !== null
            ? (int) $journalPosting['realized_fx_ledger_number']
            : null;
        $automaticValidationHash = (string) $journalPosting['preview_token'];
        $automaticValidationSnapshotData = [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'invoice_number' => $invoiceNumber,
            'journal_id' => $journalId,
            'journal_origin' => 'Automatic',
            'lines' => array_map(static function (array $line): array {
                return [
                    'purpose' => (string) ($line['purpose'] ?? ''),
                    'ledger_number' => (int) ($line['ledger']['ledger_number'] ?? 0),
                    'currency' => (string) ($line['currency'] ?? ''),
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'debit_ngn' => (float) ($line['debit_ngn'] ?? 0),
                    'credit_ngn' => (float) ($line['credit_ngn'] ?? 0),
                    'rate' => (float) ($line['rate'] ?? 0),
                ];
            }, $journalPosting['lines']),
        ];
        $automaticValidationSnapshot = json_encode(
            $automaticValidationSnapshotData,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
        );
        $updatePaymentJournalStmt = $conn->prepare(
            "UPDATE invoice_payments
             SET journal_id = ?,
                 journal_origin = 'Automatic',
                 journal_validation_status = 'Validated',
                 journal_validation_hash = ?,
                 journal_validation_snapshot = ?,
                 journal_narration = ?,
                 bank_ledger_number = ?,
                 customer_ledger_number = ?,
                 settlement_rate_date = ?,
                 payment_currency_rate_ngn = ?,
                 settlement_rate = ?,
                 settlement_value_ngn = ?,
                 carrying_rate = ?,
                 carrying_value_settled_ngn = ?,
                 realized_fx_gain_ngn = ?,
                 realized_fx_loss_ngn = ?,
                 realized_fx_ledger_number = ?,
                 journal_posted_at = NOW(),
                 journal_linked_at = NOW(),
                 journal_linked_by_user_id = ?,
                 journal_linked_by_email = ?
             WHERE id = ?"
        );
        if (!$updatePaymentJournalStmt) {
            throw new RuntimeException('Unable to link the receipt journal to the payment.', 500);
        }
        $updatePaymentJournalStmt->bind_param(
            'isssiisdddddddiisi',
            $journalId,
            $automaticValidationHash,
            $automaticValidationSnapshot,
            $journalNarration,
            $bankLedgerNumber,
            $customerLedgerNumber,
            $settlementRateDate,
            $paymentRateNgn,
            $settlementRate,
            $settlementValueNgn,
            $carryingRate,
            $carryingValueSettledNgn,
            $realizedFxGainNgn,
            $realizedFxLossNgn,
            $realizedFxLedgerNumber,
            $userId,
            $userEmail,
            $paymentId
        );
        $updatePaymentJournalStmt->execute();
        $updatePaymentJournalStmt->close();

        invoicePaymentManualLinkRecordEvent(
            $conn,
            ['id' => $paymentId, 'payment_code' => $paymentCode],
            $journalId,
            'Linked',
            'Validated',
            $user,
            null,
            $automaticValidationHash,
            $automaticValidationSnapshotData
        );
    }

    $updatedSummary = syncInvoicePaymentState(
        $conn,
        $invoiceNumber,
        $user,
        "Payment {$paymentCode} was recorded."
    );

    $paymentDescription = number_format($paymentAmount, 2) . " {$paymentCurrency}";
    if ($paymentCurrency !== $invoiceCurrency) {
        $paymentDescription .= ' settled ' . number_format($invoiceSettled, 2) . " {$invoiceCurrency}";
    }
    notifyAccountingUsers(
        $conn,
        'invoice_payment_recorded',
        'invoice',
        $updatedSummary['status'] === 'Paid'
            ? "Invoice #{$invoiceNumber} is fully paid"
            : "Payment recorded for Invoice #{$invoiceNumber}",
        $paymentDescription . " was recorded by {$userEmail}." .
            ($journalPosting ? " Receipt journal #{$journalPosting['journal_id']} was posted." : ''),
        'info',
        'invoice',
        $invoiceNumber,
        "/invoice/view/{$invoiceNumber}",
        [
            'payment_code' => $paymentCode,
            'invoice_currency' => $invoiceCurrency,
            'invoice_amount_settled' => $invoiceSettled,
            'payment_currency' => $paymentCurrency,
            'payment_amount_received' => $paymentAmount,
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
                ? 'Payment recorded. The invoice is now fully paid; link a validated manual journal to complete the accounting posting.'
                : 'Payment recorded. Link a validated manual journal to complete the accounting posting.'),
        'data' => [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'payment_summary' => $updatedSummary,
            'settlement' => [
                'invoice_currency' => $invoiceCurrency,
                'invoice_amount_settled' => $invoiceSettled,
                'payment_currency' => $paymentCurrency,
                'payment_amount_received' => $paymentAmount,
                'cross_currency_rate' => $crossRate,
                'cross_currency_rate_basis' => $paymentCurrency . '_per_' . $invoiceCurrency,
                'payment_rate_date' => $settlementRateDate,
                'payment_currency_rate_ngn' => $paymentRateNgn,
                'settlement_rate' => $settlementRate,
                'settlement_value_ngn' => $settlementValueNgn,
                'carrying_rate' => $carryingRate,
                'carrying_value_settled_ngn' => $carryingValueSettledNgn,
                'realized_fx_gain_ngn' => $realizedFxGainNgn,
                'realized_fx_loss_ngn' => $realizedFxLossNgn,
                'realized_fx_ledger_number' => $realizedFxLedgerNumber,
            ],
            'journal_linking' => [
                'origin' => $journalPosting ? 'Automatic' : 'Unposted',
                'validation_status' => $journalPosting ? 'Validated' : 'Pending',
                'requires_manual_link' => !$journalPosting,
                'journal_id' => $journalPosting ? (int) $journalPosting['journal_id'] : null,
            ],
            'journal' => $journalPosting ? [
                'journal_id' => (int) $journalPosting['journal_id'],
                'narration' => (string) $journalPosting['narration'],
                'invoice_currency' => (string) $journalPosting['invoice_currency'],
                'invoice_amount_settled' => (float) $journalPosting['invoice_amount_settled'],
                'payment_currency' => (string) $journalPosting['payment_currency'],
                'payment_amount_received' => (float) $journalPosting['payment_amount_received'],
                'cross_currency_rate' => (float) $journalPosting['cross_currency_rate'],
                'cross_currency_rate_basis' => (string) $journalPosting['cross_currency_rate_basis'],
                'rate_date' => (string) $journalPosting['rate_date'],
                'payment_currency_rate_ngn' => (float) $journalPosting['payment_currency_rate_ngn'],
                'settlement_rate' => (float) $journalPosting['settlement_rate'],
                'settlement_value_ngn' => (float) $journalPosting['settlement_value_ngn'],
                'carrying_rate' => (float) $journalPosting['carrying_rate'],
                'carrying_value_settled_ngn' => (float) $journalPosting['carrying_value_settled_ngn'],
                'realized_fx_gain_ngn' => (float) $journalPosting['realized_fx_gain_ngn'],
                'realized_fx_loss_ngn' => (float) $journalPosting['realized_fx_loss_ngn'],
                'realized_fx_ledger_number' => $journalPosting['realized_fx_ledger_number'],
                'preview_token' => (string) $journalPosting['preview_token'],
            ] : null,
        ],
    ], 201);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
