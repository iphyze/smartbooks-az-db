<?php
declare(strict_types=1);

require_once __DIR__ . '/fx_helpers.php';

/**
 * Helpers used by invoice-payment journal preview, posting, and reversal.
 * They preserve the existing journal_table + main_journal_table pattern while
 * allowing the receipt currency to differ from the invoice currency.
 */

function invoicePaymentSupportedCurrencies(): array
{
    return ['NGN', 'USD', 'EUR', 'GBP'];
}

function invoicePaymentNormaliseCurrency(string $currency, string $label): string
{
    $currency = strtoupper(trim($currency));
    if (!in_array($currency, invoicePaymentSupportedCurrencies(), true)) {
        throw new RuntimeException("{$label} is not configured for journal posting.", 422);
    }
    return $currency;
}

function invoicePaymentLedgerByNumber(mysqli $conn, int $ledgerNumber): ?array
{
    return $ledgerNumber > 0 ? smartbooksFxLedgerByNumber($conn, $ledgerNumber) : null;
}

function invoicePaymentCustomerLedger(mysqli $conn, string $clientName): ?array
{
    $clientName = trim($clientName);
    if ($clientName !== '') {
        $stmt = $conn->prepare(
            'SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
             FROM ledger_table
             WHERE ledger_name = ?
             LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to resolve the customer ledger.', 500);
        }
        $stmt->bind_param('s', $clientName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    $fallbackName = 'Account Receivables';
    $stmt = $conn->prepare(
        'SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
         FROM ledger_table
         WHERE ledger_name = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to resolve the customer ledger.', 500);
    }
    $stmt->bind_param('s', $fallbackName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

/**
 * Resolve the NGN value of one unit of the selected payment currency.
 * A supplied override represents the actual transaction rate agreed/used for
 * the receipt; otherwise the latest configured rate on or before the selected
 * effective date is used.
 */
function invoicePaymentCurrencyRate(
    mysqli $conn,
    string $currency,
    string $rateEffectiveDate,
    ?float $overrideRateNgn = null
): array {
    $currency = invoicePaymentNormaliseCurrency($currency, 'Payment currency');
    $rateEffectiveDate = smartbooksFxValidateDate($rateEffectiveDate, 'payment rate date');

    $stmt = $conn->prepare(
        'SELECT id, effective_date, ngn_rate, usd_rate, eur_rate, gbp_rate
         FROM currency_table
         WHERE effective_date <= ?
         ORDER BY effective_date DESC, id DESC
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to resolve the exchange rate for this payment.', 500);
    }
    $stmt->bind_param('s', $rateEffectiveDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException("No exchange rate exists on or before {$rateEffectiveDate}.", 422);
    }

    $rateField = strtolower($currency) . '_rate';
    $configuredRate = (float) ($row[$rateField] ?? 0);
    $rate = $overrideRateNgn !== null ? $overrideRateNgn : $configuredRate;
    if ($currency === 'NGN') {
        $rate = 1.0;
    }
    if ($rate <= 0) {
        throw new RuntimeException("The {$currency} rate effective on {$rateEffectiveDate} is invalid.", 422);
    }

    $ngnRate = (float) $row['ngn_rate'];
    $usdRate = (float) $row['usd_rate'];
    $eurRate = (float) $row['eur_rate'];
    $gbpRate = (float) $row['gbp_rate'];
    if ($currency === 'NGN') {
        $ngnRate = 1.0;
    } elseif ($currency === 'USD') {
        $usdRate = $rate;
    } elseif ($currency === 'EUR') {
        $eurRate = $rate;
    } elseif ($currency === 'GBP') {
        $gbpRate = $rate;
    }

    $appliedRateDate = ($currency === 'NGN' || $overrideRateNgn !== null)
        ? $rateEffectiveDate
        : (string) $row['effective_date'];

    return [
        'id' => (int) $row['id'],
        'requested_rate_date' => $rateEffectiveDate,
        'configured_rate_date' => (string) $row['effective_date'],
        'rate_date' => $appliedRateDate,
        'rate' => $rate,
        'configured_rate' => $configuredRate,
        'is_override' => $overrideRateNgn !== null,
        'ngn_rate' => $ngnRate,
        'usd_rate' => $usdRate,
        'eur_rate' => $eurRate,
        'gbp_rate' => $gbpRate,
    ];
}

/**
 * Normalise the two amounts used by a mixed-currency settlement.
 * cross_currency_rate means payment-currency units per one invoice-currency
 * unit. For same-currency receipts it is always 1.
 */
function normaliseInvoicePaymentAmounts(
    string $invoiceCurrency,
    string $paymentCurrency,
    float $invoiceAmountSettled,
    ?float $paymentAmountReceived,
    ?float $crossCurrencyRate
): array {
    $invoiceCurrency = invoicePaymentNormaliseCurrency($invoiceCurrency, 'Invoice currency');
    $paymentCurrency = invoicePaymentNormaliseCurrency($paymentCurrency, 'Payment currency');
    $invoiceAmountSettled = round($invoiceAmountSettled, 2);

    if ($invoiceAmountSettled <= 0) {
        throw new RuntimeException('Invoice amount settled must be greater than zero.', 422);
    }

    if ($invoiceCurrency === $paymentCurrency) {
        if ($paymentAmountReceived !== null && abs($paymentAmountReceived - $invoiceAmountSettled) > 0.009) {
            throw new RuntimeException(
                'When the payment currency matches the invoice currency, the amount received must equal the invoice amount settled.',
                422
            );
        }
        if ($crossCurrencyRate !== null && abs($crossCurrencyRate - 1.0) > 0.0000001) {
            throw new RuntimeException('The cross-currency rate must be 1 when both currencies are the same.', 422);
        }

        return [
            'invoice_currency' => $invoiceCurrency,
            'payment_currency' => $paymentCurrency,
            'invoice_amount_settled' => $invoiceAmountSettled,
            'payment_amount_received' => $invoiceAmountSettled,
            'cross_currency_rate' => 1.0,
        ];
    }

    $received = $paymentAmountReceived !== null ? round($paymentAmountReceived, 2) : null;
    $crossRate = $crossCurrencyRate !== null ? round($crossCurrencyRate, 8) : null;
    if ($received !== null && $received <= 0) {
        throw new RuntimeException('Payment amount received must be greater than zero.', 422);
    }
    if ($crossRate !== null && $crossRate <= 0) {
        throw new RuntimeException('Cross-currency settlement rate must be greater than zero.', 422);
    }
    if ($received === null && $crossRate === null) {
        throw new RuntimeException(
            'Enter either the payment amount received or the cross-currency settlement rate.',
            422
        );
    }

    if ($received === null) {
        $received = round($invoiceAmountSettled * (float) $crossRate, 2);
    } elseif ($crossRate === null) {
        $crossRate = round($received / $invoiceAmountSettled, 8);
    } else {
        $expectedReceived = round($invoiceAmountSettled * $crossRate, 2);
        $tolerance = max(0.02, round(abs($expectedReceived) * 0.0001, 2));
        if (abs($received - $expectedReceived) > $tolerance) {
            throw new RuntimeException(
                'The payment amount received does not agree with the invoice amount settled and the cross-currency rate.',
                422
            );
        }
    }

    return [
        'invoice_currency' => $invoiceCurrency,
        'payment_currency' => $paymentCurrency,
        'invoice_amount_settled' => $invoiceAmountSettled,
        'payment_amount_received' => $received,
        'cross_currency_rate' => $crossRate,
    ];
}

function assertInvoicePaymentPostingDateOpen(mysqli $conn, string $postingDate): void
{
    smartbooksFxAssertPostingDateOpen($conn, $postingDate);
}

function nextInvoicePaymentJournalId(mysqli $conn): int
{
    return smartbooksFxNextJournalId($conn);
}

function invoicePaymentReference(string $invoiceNumber): string
{
    $invoiceNumber = trim($invoiceNumber);
    return stripos($invoiceNumber, 'AZ-') === 0 ? $invoiceNumber : 'AZ-' . $invoiceNumber;
}

function insertInvoicePaymentJournalHeader(
    mysqli $conn,
    int $journalId,
    string $journalType,
    string $transactionType,
    string $journalDate,
    string $currency,
    string $description,
    float $debit,
    float $credit,
    string $rateDate,
    float $rate,
    float $debitNgn,
    float $creditNgn,
    float $debitOthers,
    float $creditOthers,
    string $costCenter,
    string $userEmail
): void {
    $stmt = $conn->prepare(
        'INSERT INTO journal_table
            (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description,
             debit, credit, rate_date, rate, debit_ngn, credit_ngn, debit_others, credit_others,
             cost_center, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the invoice payment journal.', 500);
    }

    $stmt->bind_param(
        'isssssddsdddddsss',
        $journalId,
        $journalType,
        $transactionType,
        $journalDate,
        $currency,
        $description,
        $debit,
        $credit,
        $rateDate,
        $rate,
        $debitNgn,
        $creditNgn,
        $debitOthers,
        $creditOthers,
        $costCenter,
        $userEmail,
        $userEmail
    );
    $stmt->execute();
    $stmt->close();
}

function insertInvoicePaymentMainJournalLine(
    mysqli $conn,
    int $journalId,
    string $journalType,
    string $transactionType,
    string $journalDate,
    string $description,
    array $line,
    array $rateData,
    string $costCenter,
    array $ledger,
    string $userEmail
): int {
    $stmt = $conn->prepare(
        'INSERT INTO main_journal_table
            (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description,
             debit, credit, rate, debit_ngn, credit_ngn, ngn_rate, usd_rate, eur_rate, gbp_rate,
             cost_center, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class,
             ledger_type, created_by, updated_by, rate_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the invoice payment journal line.', 500);
    }

    $currency = strtoupper(trim((string) ($line['currency'] ?? 'NGN')));
    $debit = round((float) ($line['debit'] ?? 0), 6);
    $credit = round((float) ($line['credit'] ?? 0), 6);
    $lineRate = (float) ($line['rate'] ?? ($currency === 'NGN' ? 1 : $rateData['rate']));
    $debitNgn = round((float) ($line['debit_ngn'] ?? 0), 2);
    $creditNgn = round((float) ($line['credit_ngn'] ?? 0), 2);
    $rateDate = (string) ($line['rate_date'] ?? $rateData['rate_date']);
    $ngnRate = (float) $rateData['ngn_rate'];
    $usdRate = (float) $rateData['usd_rate'];
    $eurRate = (float) $rateData['eur_rate'];
    $gbpRate = (float) $rateData['gbp_rate'];
    $ledgerName = (string) $ledger['ledger_name'];
    $ledgerNumber = (int) $ledger['ledger_number'];
    $ledgerClass = (string) $ledger['ledger_class'];
    $ledgerClassCode = (int) $ledger['ledger_class_code'];
    $ledgerSubClass = (string) $ledger['ledger_sub_class'];
    $ledgerType = (string) $ledger['ledger_type'];

    $stmt->bind_param(
        'isssssdddddddddssisisssss',
        $journalId,
        $journalType,
        $transactionType,
        $journalDate,
        $currency,
        $description,
        $debit,
        $credit,
        $lineRate,
        $debitNgn,
        $creditNgn,
        $ngnRate,
        $usdRate,
        $eurRate,
        $gbpRate,
        $costCenter,
        $ledgerName,
        $ledgerNumber,
        $ledgerClass,
        $ledgerClassCode,
        $ledgerSubClass,
        $ledgerType,
        $userEmail,
        $userEmail,
        $rateDate
    );
    $stmt->execute();
    $lineId = (int) $stmt->insert_id;
    $stmt->close();

    return $lineId;
}

/**
 * Calculate the settlement value, carrying amount cleared, and realised FX.
 * This function is also used when the payment is recorded without an automatic
 * journal so the expected accounting values are retained for later linking.
 */
function calculateInvoicePaymentSettlement(
    mysqli $conn,
    array $invoice,
    string $paymentDate,
    float $invoiceAmountSettled,
    string $paymentCurrency,
    ?float $paymentAmountReceived,
    ?float $crossCurrencyRate,
    int $creditLedgerNumber,
    string $rateEffectiveDate,
    ?float $paymentCurrencyRateNgn = null
): array {
    $paymentDate = smartbooksFxValidateDate($paymentDate, 'payment date');
    $rateEffectiveDate = smartbooksFxValidateDate($rateEffectiveDate, 'payment rate date');
    if ($rateEffectiveDate > $paymentDate) {
        throw new RuntimeException('Payment rate date cannot be later than the payment date.', 422);
    }

    $invoiceCurrency = invoicePaymentNormaliseCurrency(
        (string) ($invoice['currency'] ?? ''),
        'Invoice currency'
    );
    $amounts = normaliseInvoicePaymentAmounts(
        $invoiceCurrency,
        $paymentCurrency,
        $invoiceAmountSettled,
        $paymentAmountReceived,
        $crossCurrencyRate
    );

    $clientName = trim((string) ($invoice['clients_name'] ?? ''));
    $creditLedger = $creditLedgerNumber > 0
        ? invoicePaymentLedgerByNumber($conn, $creditLedgerNumber)
        : invoicePaymentCustomerLedger($conn, $clientName);
    if (!$creditLedger) {
        throw new RuntimeException('Select a valid ledger to credit for this receipt.', 422);
    }

    if ($paymentCurrencyRateNgn !== null && $paymentCurrencyRateNgn <= 0) {
        throw new RuntimeException('Payment-currency NGN rate must be greater than zero.', 422);
    }
    if ($amounts['payment_currency'] === 'NGN' &&
        $paymentCurrencyRateNgn !== null &&
        abs($paymentCurrencyRateNgn - 1.0) > 0.0000001) {
        throw new RuntimeException('The NGN payment-currency rate must be 1.', 422);
    }
    $rateData = invoicePaymentCurrencyRate(
        $conn,
        $amounts['payment_currency'],
        $rateEffectiveDate,
        $paymentCurrencyRateNgn
    );

    $settlementValueNgn = round(
        (float) $amounts['payment_amount_received'] * (float) $rateData['rate'],
        2
    );
    $carryingValueNgn = (float) $amounts['invoice_amount_settled'];
    $carryingRate = 1.0;
    $position = null;

    if ($invoiceCurrency !== 'NGN') {
        smartbooksFxRequireSchema($conn);
        $position = smartbooksFxLedgerCurrencyPosition(
            $conn,
            (int) $creditLedger['ledger_number'],
            $invoiceCurrency,
            $paymentDate
        );
        $fcyBalance = (float) $position['fcy_balance'];
        $currentCarrying = (float) $position['current_carrying_ngn'];

        if ($fcyBalance <= 0.00005 || $currentCarrying <= 0.005) {
            throw new RuntimeException(
                "The selected credit ledger does not have a positive {$invoiceCurrency} receivable balance to settle.",
                422
            );
        }
        if ((float) $amounts['invoice_amount_settled'] > $fcyBalance + 0.005) {
            throw new RuntimeException(
                'The invoice amount settled is greater than the selected ledger\'s open ' .
                $invoiceCurrency . ' balance of ' . number_format($fcyBalance, 2) . '.',
                422
            );
        }

        $carryingRate = $currentCarrying / $fcyBalance;
        $carryingValueNgn = abs((float) $amounts['invoice_amount_settled'] - $fcyBalance) <= 0.005
            ? round($currentCarrying, 2)
            : round((float) $amounts['invoice_amount_settled'] * $carryingRate, 2);
    }

    $realizedDifference = round($settlementValueNgn - $carryingValueNgn, 2);
    $realizedGain = 0.0;
    $realizedLoss = 0.0;
    $realizedLedger = null;
    if ($realizedDifference > 0.009) {
        $realizedGain = $realizedDifference;
        $realizedLedger = smartbooksFxRequiredLedger(
            $conn,
            SMARTBOOKS_FX_REALIZED_GAIN_LEDGER,
            'Realized Exchange Gain',
            'Revenue'
        );
    } elseif ($realizedDifference < -0.009) {
        $realizedLoss = abs($realizedDifference);
        $realizedLedger = smartbooksFxRequiredLedger(
            $conn,
            SMARTBOOKS_FX_REALIZED_LOSS_LEDGER,
            'Realized Exchange Loss',
            'Expense'
        );
    }

    $effectiveSettlementRate = (float) $amounts['invoice_amount_settled'] > 0
        ? round($settlementValueNgn / (float) $amounts['invoice_amount_settled'], 8)
        : 0.0;

    return array_merge($amounts, [
        'cross_currency_rate_basis' => $amounts['payment_currency'] . '_per_' . $amounts['invoice_currency'],
        'rate_date' => (string) $rateData['rate_date'],
        'requested_rate_date' => $rateEffectiveDate,
        'payment_currency_rate_ngn' => (float) $rateData['rate'],
        'settlement_rate' => $effectiveSettlementRate,
        'settlement_value_ngn' => $settlementValueNgn,
        'carrying_rate' => round($carryingRate, 8),
        'carrying_value_settled_ngn' => $carryingValueNgn,
        'realized_fx_gain_ngn' => $realizedGain,
        'realized_fx_loss_ngn' => $realizedLoss,
        'realized_fx_ledger_number' => $realizedLedger ? (int) $realizedLedger['ledger_number'] : null,
        'realized_fx_ledger' => $realizedLedger,
        'credit_ledger' => $creditLedger,
        'credit_ledger_position' => $position,
        'rate_data' => $rateData,
    ]);
}

/**
 * Build the exact balanced journal which will be posted.
 */
function buildInvoicePaymentJournalPreview(
    mysqli $conn,
    array $invoice,
    string $paymentDate,
    float $invoiceAmountSettled,
    string $paymentCurrency,
    ?float $paymentAmountReceived,
    ?float $crossCurrencyRate,
    int $debitLedgerNumber,
    int $creditLedgerNumber,
    bool $isComplete,
    string $rateEffectiveDate,
    ?float $paymentCurrencyRateNgn = null
): array {
    $debitLedger = invoicePaymentLedgerByNumber($conn, $debitLedgerNumber);
    if (!$debitLedger) {
        throw new RuntimeException('Select a valid ledger to debit for this receipt.', 422);
    }

    $settlement = calculateInvoicePaymentSettlement(
        $conn,
        $invoice,
        $paymentDate,
        $invoiceAmountSettled,
        $paymentCurrency,
        $paymentAmountReceived,
        $crossCurrencyRate,
        $creditLedgerNumber,
        $rateEffectiveDate,
        $paymentCurrencyRateNgn
    );
    $creditLedger = $settlement['credit_ledger'];
    if ((int) $debitLedger['ledger_number'] === (int) $creditLedger['ledger_number']) {
        throw new RuntimeException('The debit and credit sides must use different ledgers.', 422);
    }

    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
    $clientName = trim((string) ($invoice['clients_name'] ?? ''));
    $receiptType = $isComplete ? 'complete' : 'part';
    $narration = sprintf(
        'Being %s receipt on Inv. No. %s IFO %s',
        $receiptType,
        invoicePaymentReference($invoiceNumber),
        $clientName
    );

    $lines = [
        [
            'side' => 'debit',
            'purpose' => 'receipt',
            'ledger' => $debitLedger,
            'currency' => $settlement['payment_currency'],
            'debit' => $settlement['payment_amount_received'],
            'credit' => 0.0,
            'rate' => $settlement['payment_currency_rate_ngn'],
            'rate_date' => $settlement['rate_date'],
            'debit_ngn' => $settlement['settlement_value_ngn'],
            'credit_ngn' => 0.0,
        ],
        [
            'side' => 'credit',
            'purpose' => 'receivable_settlement',
            'ledger' => $creditLedger,
            'currency' => $settlement['invoice_currency'],
            'debit' => 0.0,
            'credit' => $settlement['invoice_amount_settled'],
            'rate' => $settlement['carrying_rate'],
            'rate_date' => $paymentDate,
            'debit_ngn' => 0.0,
            'credit_ngn' => $settlement['carrying_value_settled_ngn'],
        ],
    ];

    if ($settlement['realized_fx_gain_ngn'] > 0 && $settlement['realized_fx_ledger']) {
        $lines[] = [
            'side' => 'credit',
            'purpose' => 'realized_fx_gain',
            'ledger' => $settlement['realized_fx_ledger'],
            'currency' => 'NGN',
            'debit' => 0.0,
            'credit' => $settlement['realized_fx_gain_ngn'],
            'rate' => 1.0,
            'rate_date' => $paymentDate,
            'debit_ngn' => 0.0,
            'credit_ngn' => $settlement['realized_fx_gain_ngn'],
        ];
    } elseif ($settlement['realized_fx_loss_ngn'] > 0 && $settlement['realized_fx_ledger']) {
        $lines[] = [
            'side' => 'debit',
            'purpose' => 'realized_fx_loss',
            'ledger' => $settlement['realized_fx_ledger'],
            'currency' => 'NGN',
            'debit' => $settlement['realized_fx_loss_ngn'],
            'credit' => 0.0,
            'rate' => 1.0,
            'rate_date' => $paymentDate,
            'debit_ngn' => $settlement['realized_fx_loss_ngn'],
            'credit_ngn' => 0.0,
        ];
    }

    $totalDebitNgn = round(array_sum(array_column($lines, 'debit_ngn')), 2);
    $totalCreditNgn = round(array_sum(array_column($lines, 'credit_ngn')), 2);
    if (abs($totalDebitNgn - $totalCreditNgn) > 0.009) {
        throw new RuntimeException('The generated invoice payment journal is not balanced.', 500);
    }

    $tokenLines = array_map(static function (array $line): array {
        return [
            'ledger_number' => (int) $line['ledger']['ledger_number'],
            'currency' => $line['currency'],
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'debit_ngn' => $line['debit_ngn'],
            'credit_ngn' => $line['credit_ngn'],
            'rate' => $line['rate'],
        ];
    }, $lines);
    $previewBasis = [
        'invoice_number' => $invoiceNumber,
        'payment_date' => $paymentDate,
        'invoice_currency' => $settlement['invoice_currency'],
        'invoice_amount_settled' => $settlement['invoice_amount_settled'],
        'payment_currency' => $settlement['payment_currency'],
        'payment_amount_received' => $settlement['payment_amount_received'],
        'cross_currency_rate' => $settlement['cross_currency_rate'],
        'rate_date' => $settlement['rate_date'],
        'payment_currency_rate_ngn' => $settlement['payment_currency_rate_ngn'],
        'lines' => $tokenLines,
    ];

    return array_merge($settlement, [
        'narration' => $narration,
        // Legacy response aliases retained for the current frontend.
        'currency' => $settlement['payment_currency'],
        'amount' => $settlement['payment_amount_received'],
        'debit_ledger' => $debitLedger,
        'bank_ledger' => $debitLedger,
        'lines' => $lines,
        'total_debit_ngn' => $totalDebitNgn,
        'total_credit_ngn' => $totalCreditNgn,
        'preview_token' => hash('sha256', json_encode($previewBasis, JSON_PRESERVE_ZERO_FRACTION)),
    ]);
}

function postInvoicePaymentJournal(
    mysqli $conn,
    array $invoice,
    string $paymentDate,
    float $invoiceAmountSettled,
    string $paymentCurrency,
    ?float $paymentAmountReceived,
    ?float $crossCurrencyRate,
    int $bankLedgerNumber,
    int $creditLedgerNumber,
    bool $isComplete,
    array $user,
    string $submittedPreviewToken,
    string $rateEffectiveDate,
    ?float $paymentCurrencyRateNgn = null
): array {
    assertInvoicePaymentPostingDateOpen($conn, $paymentDate);
    if ($submittedPreviewToken === '') {
        throw new RuntimeException('Preview the payment journal before posting it.', 422);
    }

    $preview = buildInvoicePaymentJournalPreview(
        $conn,
        $invoice,
        $paymentDate,
        $invoiceAmountSettled,
        $paymentCurrency,
        $paymentAmountReceived,
        $crossCurrencyRate,
        $bankLedgerNumber,
        $creditLedgerNumber,
        $isComplete,
        $rateEffectiveDate,
        $paymentCurrencyRateNgn
    );
    if (!hash_equals($preview['preview_token'], $submittedPreviewToken)) {
        throw new RuntimeException(
            'The exchange rate, amounts, or ledger carrying value changed after the payment journal preview was generated. Refresh the preview before posting.',
            409
        );
    }

    $journalId = nextInvoicePaymentJournalId($conn);
    $journalType = 'Receipt';
    $transactionType = 'Bank';
    $clientName = trim((string) ($invoice['clients_name'] ?? ''));
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $headerCurrency = (string) $preview['payment_currency'];
    $headerAmount = (float) $preview['payment_amount_received'];
    $otherAmount = $headerCurrency === 'NGN' ? 0.0 : $headerAmount;

    insertInvoicePaymentJournalHeader(
        $conn,
        $journalId,
        $journalType,
        $transactionType,
        $paymentDate,
        $headerCurrency,
        (string) $preview['narration'],
        $headerAmount,
        $headerAmount,
        (string) $preview['rate_date'],
        (float) $preview['payment_currency_rate_ngn'],
        (float) $preview['total_debit_ngn'],
        (float) $preview['total_credit_ngn'],
        $otherAmount,
        $otherAmount,
        $clientName,
        $userEmail
    );

    $lineIds = [];
    foreach ($preview['lines'] as $line) {
        $lineIds[] = insertInvoicePaymentMainJournalLine(
            $conn,
            $journalId,
            $journalType,
            $transactionType,
            $paymentDate,
            (string) $preview['narration'],
            $line,
            $preview['rate_data'],
            $clientName,
            $line['ledger'],
            $userEmail
        );
    }

    $preview['journal_id'] = $journalId;
    $preview['line_ids'] = $lineIds;
    return $preview;
}

/**
 * Reverse the stored journal values line by line. No current exchange rate is
 * used, so the original receipt, receivable carrying amount, and realised FX
 * are cancelled exactly, including mixed-currency journals.
 */
function reverseInvoicePaymentJournal(mysqli $conn, array $payment, array $invoice, array $user): int
{
    $originalJournalId = (int) ($payment['journal_id'] ?? 0);
    if ($originalJournalId <= 0) {
        throw new RuntimeException('The original payment journal is not available.', 409);
    }

    $postingDate = date('Y-m-d');
    assertInvoicePaymentPostingDateOpen($conn, $postingDate);

    $headerStmt = $conn->prepare(
        'SELECT journal_type, transaction_type, journal_currency, journal_description,
                debit, credit, rate_date, rate, debit_ngn, credit_ngn,
                debit_others, credit_others, cost_center
         FROM journal_table
         WHERE journal_id = ?
         LIMIT 1'
    );
    if (!$headerStmt) {
        throw new RuntimeException('Unable to load the original payment journal.', 500);
    }
    $headerStmt->bind_param('i', $originalJournalId);
    $headerStmt->execute();
    $header = $headerStmt->get_result()->fetch_assoc();
    $headerStmt->close();
    if (!$header) {
        throw new RuntimeException('The original payment journal no longer exists.', 409);
    }

    $linesStmt = $conn->prepare(
        'SELECT journal_type, transaction_type, journal_currency, debit, credit,
                rate_date, rate, debit_ngn, credit_ngn,
                ngn_rate, usd_rate, eur_rate, gbp_rate, cost_center,
                ledger_name, ledger_number, ledger_class, ledger_class_code,
                ledger_sub_class, ledger_type
         FROM main_journal_table
         WHERE journal_id = ?
         ORDER BY id ASC'
    );
    if (!$linesStmt) {
        throw new RuntimeException('Unable to load the original payment journal lines.', 500);
    }
    $linesStmt->bind_param('i', $originalJournalId);
    $linesStmt->execute();
    $lines = $linesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $linesStmt->close();
    if (!$lines) {
        throw new RuntimeException('The original payment journal has no ledger lines.', 409);
    }

    $journalId = nextInvoicePaymentJournalId($conn);
    $originalNarration = trim((string) ($payment['journal_narration'] ?? $header['journal_description'] ?? ''));
    $narration = 'Being reversal of ' . ($originalNarration !== ''
        ? lcfirst($originalNarration)
        : 'receipt on Inv. No. ' . invoicePaymentReference((string) $payment['invoice_number']));
    $userEmail = trim((string) ($user['email'] ?? 'system'));

    insertInvoicePaymentJournalHeader(
        $conn,
        $journalId,
        (string) $header['journal_type'],
        (string) $header['transaction_type'],
        $postingDate,
        (string) $header['journal_currency'],
        $narration,
        (float) $header['credit'],
        (float) $header['debit'],
        (string) $header['rate_date'],
        (float) $header['rate'],
        (float) $header['credit_ngn'],
        (float) $header['debit_ngn'],
        (float) $header['credit_others'],
        (float) $header['debit_others'],
        (string) $header['cost_center'],
        $userEmail
    );

    foreach ($lines as $originalLine) {
        $ledger = [
            'ledger_name' => (string) $originalLine['ledger_name'],
            'ledger_number' => (int) $originalLine['ledger_number'],
            'ledger_class' => (string) $originalLine['ledger_class'],
            'ledger_class_code' => (int) $originalLine['ledger_class_code'],
            'ledger_sub_class' => (string) $originalLine['ledger_sub_class'],
            'ledger_type' => (string) $originalLine['ledger_type'],
        ];
        $line = [
            'currency' => (string) $originalLine['journal_currency'],
            'debit' => (float) $originalLine['credit'],
            'credit' => (float) $originalLine['debit'],
            'rate' => (float) $originalLine['rate'],
            'rate_date' => (string) $originalLine['rate_date'],
            'debit_ngn' => (float) $originalLine['credit_ngn'],
            'credit_ngn' => (float) $originalLine['debit_ngn'],
        ];
        $lineRateData = [
            'rate' => (float) $originalLine['rate'],
            'rate_date' => (string) $originalLine['rate_date'],
            'ngn_rate' => (float) $originalLine['ngn_rate'],
            'usd_rate' => (float) $originalLine['usd_rate'],
            'eur_rate' => (float) $originalLine['eur_rate'],
            'gbp_rate' => (float) $originalLine['gbp_rate'],
        ];

        insertInvoicePaymentMainJournalLine(
            $conn,
            $journalId,
            (string) $originalLine['journal_type'],
            (string) $originalLine['transaction_type'],
            $postingDate,
            $narration,
            $line,
            $lineRateData,
            (string) $originalLine['cost_center'],
            $ledger,
            $userEmail
        );
    }

    return $journalId;
}
