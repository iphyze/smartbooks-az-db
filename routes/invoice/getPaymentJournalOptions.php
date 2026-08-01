<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/invoice_payment_journal_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can prepare invoice payment journals.'
);

$nullableFloat = static function (array $source, array $keys): ?float {
    foreach ($keys as $key) {
        if (array_key_exists($key, $source) && $source[$key] !== '' && $source[$key] !== null) {
            return (float) $source[$key];
        }
    }
    return null;
};

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
$bankId = (int) ($_GET['bank_id'] ?? 0);
$paymentDate = trim((string) ($_GET['payment_date'] ?? date('Y-m-d')));
$invoiceAmountSettled = round((float) ($_GET['invoice_amount_settled'] ?? $_GET['amount'] ?? 0), 2);
$requestedPaymentCurrency = strtoupper(trim((string) ($_GET['payment_currency'] ?? $_GET['currency'] ?? '')));
$paymentAmountReceived = $nullableFloat($_GET, ['payment_amount_received', 'received_amount']);
$journalReceivableAmount = $nullableFloat($_GET, ['journal_receivable_amount', 'receivable_amount']);
$crossCurrencyRate = $nullableFloat($_GET, ['cross_currency_rate', 'payment_exchange_rate']);
$paymentCurrencyRateNgn = $nullableFloat($_GET, ['payment_currency_rate_ngn']);
$rateEffectiveDate = trim((string) ($_GET['payment_rate_date'] ?? $_GET['settlement_rate_date'] ?? $paymentDate));
$debitLedgerNumber = (int) ($_GET['debit_ledger_number'] ?? $_GET['bank_ledger_number'] ?? 0);
$requestedCreditLedgerNumber = (int) ($_GET['credit_ledger_number'] ?? 0);

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
if ($invoiceAmountSettled < 0) {
    throw new RuntimeException('Invoice amount settled cannot be negative.', 422);
}

$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
$invoiceCurrency = invoicePaymentNormaliseCurrency(
    (string) ($invoice['currency'] ?? ''),
    'Invoice currency'
);
$paymentCurrency = $requestedPaymentCurrency !== ''
    ? invoicePaymentNormaliseCurrency($requestedPaymentCurrency, 'Payment currency')
    : $invoiceCurrency;
if ($paymentAmountReceived === null && $invoiceCurrency === $paymentCurrency && $invoiceAmountSettled > 0) {
    $paymentAmountReceived = $journalReceivableAmount !== null
        ? $journalReceivableAmount
        : $invoiceAmountSettled;
}

$customerLedger = invoicePaymentCustomerLedger($conn, (string) ($invoice['clients_name'] ?? ''));
$rateData = invoicePaymentCurrencyRate(
    $conn,
    $paymentCurrency,
    $rateEffectiveDate,
    $paymentCurrencyRateNgn
);

$bankAccount = null;
$accountNumber = '';
if ($bankId > 0) {
    $bankStmt = $conn->prepare(
        'SELECT id, bank_name, account_name, account_number, account_currency
         FROM bank_table
         WHERE id = ?
         LIMIT 1'
    );
    if (!$bankStmt) {
        throw new RuntimeException('Unable to load the selected bank account.', 500);
    }
    $bankStmt->bind_param('i', $bankId);
    $bankStmt->execute();
    $bankAccount = $bankStmt->get_result()->fetch_assoc() ?: null;
    $bankStmt->close();
    if (!$bankAccount) {
        throw new RuntimeException('The selected bank account is no longer available.', 422);
    }
    if (strtoupper((string) $bankAccount['account_currency']) !== $paymentCurrency) {
        throw new RuntimeException("Select a {$paymentCurrency} bank account for this payment.", 422);
    }
    $accountNumber = trim((string) $bankAccount['account_number']);
}

$sql = "SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
        FROM ledger_table
        ORDER BY ledger_number ASC";
$result = $conn->query($sql);
$allLedgers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$bankLedgers = $allLedgers;

$suggestedBankLedger = null;
if ($accountNumber !== '') {
    foreach ($bankLedgers as $ledger) {
        if (stripos((string) $ledger['ledger_name'], $accountNumber) !== false) {
            $suggestedBankLedger = $ledger;
            break;
        }
    }
}

$selectedCreditLedger = $requestedCreditLedgerNumber > 0
    ? invoicePaymentLedgerByNumber($conn, $requestedCreditLedgerNumber)
    : $customerLedger;
$fxSchemaReady = smartbooksFxSchemaReady($conn);
$creditLedgerPosition = null;
if ($invoiceCurrency !== 'NGN' && $selectedCreditLedger && $fxSchemaReady) {
    $creditLedgerPosition = smartbooksFxLedgerCurrencyPosition(
        $conn,
        (int) $selectedCreditLedger['ledger_number'],
        $invoiceCurrency,
        $paymentDate
    );
}

$summary = invoicePaymentSummary($conn, $invoiceNumber, (float) ($invoice['invoice_amount'] ?? 0));
$balanceDue = (float) $summary['balance_due'];
$normalisedAmounts = null;
$withholdingCoverage = [
    'withholding_tax_total' => invoicePaymentWithholdingTaxTotal($invoice),
    'withholding_tax_already_settled' => (float) ($summary['withholding_tax_settled'] ?? 0),
    'withholding_tax_remaining' => max(
        0,
        invoicePaymentWithholdingTaxTotal($invoice) - (float) ($summary['withholding_tax_settled'] ?? 0)
    ),
    'withholding_tax_settled' => 0.0,
];
$journalPreview = null;
$journalPreviewError = null;
$journalWarnings = [];

if ($invoiceAmountSettled > 0) {
    try {
        if ($invoiceAmountSettled > $balanceDue + 0.009) {
            throw new RuntimeException(
                'Invoice amount settled cannot be greater than the outstanding balance of ' .
                number_format($balanceDue, 2) . ' ' . $invoiceCurrency . '.',
                422
            );
        }

        $canNormaliseAmounts = $debitLedgerNumber > 0
            || $invoiceCurrency === $paymentCurrency
            || ($paymentAmountReceived !== null && $paymentAmountReceived > 0)
            || ($crossCurrencyRate !== null && $crossCurrencyRate > 0);

        if ($canNormaliseAmounts) {
            $normalisedAmounts = normaliseInvoicePaymentAmounts(
                $invoiceCurrency,
                $paymentCurrency,
                $invoiceAmountSettled,
                $paymentAmountReceived,
                $crossCurrencyRate,
                $journalReceivableAmount
            );
            $normalisedAmounts = applyInvoicePaymentWithholdingCoverage(
                $invoice,
                $summary,
                $normalisedAmounts
            );
            $withholdingCoverage = validateInvoicePaymentWithholdingCoverage(
                $invoice,
                $summary,
                $normalisedAmounts
            );

            $effectiveInvoiceAmountSettled = (float) $normalisedAmounts['invoice_amount_settled'];
            if ($effectiveInvoiceAmountSettled > $balanceDue + 0.009) {
                throw new RuntimeException(
                    'Invoice amount settled cannot be greater than the outstanding balance of ' .
                    number_format($balanceDue, 2) . ' ' . $invoiceCurrency . '.',
                    422
                );
            }

            if ($debitLedgerNumber > 0) {
                $isComplete = round(max(0, $balanceDue - $effectiveInvoiceAmountSettled), 2) <= 0.009;
                $journalPreview = buildInvoicePaymentJournalPreview(
                    $conn,
                    $invoice,
                    $paymentDate,
                    $effectiveInvoiceAmountSettled,
                    $paymentCurrency,
                    (float) $normalisedAmounts['payment_amount_received'],
                    (float) $normalisedAmounts['cross_currency_rate'],
                    $debitLedgerNumber,
                    $requestedCreditLedgerNumber,
                    $isComplete,
                    $rateEffectiveDate,
                    $paymentCurrencyRateNgn,
                    (float) $normalisedAmounts['journal_receivable_amount']
                );
                $journalWarnings = is_array($journalPreview['validation_warnings'] ?? null)
                    ? $journalPreview['validation_warnings']
                    : [];
            }
        }
    } catch (RuntimeException $exception) {
        if ((int) $exception->getCode() !== 422) {
            throw $exception;
        }

        // A preview validation problem must not discard the ledger choices.
        // Return the context and let the user correct the proposed journal.
        $journalPreview = null;
        $journalPreviewError = $exception->getMessage();
    }
}

jsonResponse([
    'status' => 'Success',
    'data' => [
        'invoice_currency' => $invoiceCurrency,
        'payment_currency' => $paymentCurrency,
        'supported_payment_currencies' => invoicePaymentSupportedCurrencies(),
        'invoice_amount_settled' => $normalisedAmounts['invoice_amount_settled'] ?? $invoiceAmountSettled,
        'journal_receivable_amount' => $normalisedAmounts['journal_receivable_amount'] ?? $journalReceivableAmount,
        'withholding_tax' => $withholdingCoverage,
        'payment_amount_received' => $normalisedAmounts['payment_amount_received'] ?? $paymentAmountReceived,
        'cross_currency_rate' => $normalisedAmounts['cross_currency_rate'] ?? $crossCurrencyRate,
        'cross_currency_rate_basis' => $paymentCurrency . '_per_' . $invoiceCurrency,
        'payment_rate_date' => $rateEffectiveDate,
        'payment_currency_rate_ngn' => (float) $rateData['rate'],
        'payment_summary' => $summary,
        'customer_ledger' => $customerLedger,
        'suggested_credit_ledger' => $customerLedger,
        'bank_account' => $bankAccount,
        'bank_ledgers' => $bankLedgers,
        'credit_ledgers' => $allLedgers,
        'suggested_bank_ledger' => $suggestedBankLedger,
        'selected_credit_ledger' => $selectedCreditLedger,
        'credit_ledger_position' => $creditLedgerPosition,
        'journal_preview' => $journalPreview,
        'journal_preview_error' => $journalPreviewError,
        'journal_warnings' => $journalWarnings,
        'preview_token' => $journalPreview['preview_token'] ?? null,
        'fx_schema_ready' => $fxSchemaReady,
        // Legacy key retained for the current frontend.
        'rate' => $rateData,
    ],
]);
