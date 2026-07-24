<?php
declare(strict_types=1);

require_once __DIR__ . '/invoice_helpers.php';
require_once __DIR__ . '/invoice_payment_journal_helpers.php';
require_once __DIR__ . '/invoice_payment_manual_journal_helpers.php';
require_once __DIR__ . '/notification_helpers.php';

/**
 * Harmonises invoice payments entered through the journal workspace.
 * The journal remains the accounting source of truth; this helper only creates
 * or updates the payment register and allocation around a validated journal.
 */

function invoicePaymentRegistrationNormaliseDate(string $value, string $label): string
{
    return smartbooksFxValidateDate(trim($value), $label);
}

function invoicePaymentRegistrationLineSide(array $line): ?string
{
    $debit = round((float) ($line['debit'] ?? 0), 6);
    $credit = round((float) ($line['credit'] ?? 0), 6);
    if ($debit > 0.0000005 && $credit > 0.0000005) {
        return null;
    }
    if ($debit > 0.0000005) {
        return 'Debit';
    }
    if ($credit > 0.0000005) {
        return 'Credit';
    }
    return 'Zero';
}

function invoicePaymentRegistrationNormalisePersistedJournal(array $journal): array
{
    $header = $journal['header'] ?? [];
    $lines = [];
    foreach (($journal['lines'] ?? []) as $index => $line) {
        $lines[] = [
            'source_index' => $index,
            'id' => isset($line['id']) ? (int) $line['id'] : null,
            'ledger_number' => (int) ($line['ledger_number'] ?? 0),
            'ledger_name' => trim((string) ($line['ledger_name'] ?? '')),
            'ledger_class' => trim((string) ($line['ledger_class'] ?? '')),
            'ledger_class_code' => (int) ($line['ledger_class_code'] ?? 0),
            'ledger_sub_class' => trim((string) ($line['ledger_sub_class'] ?? '')),
            'ledger_type' => trim((string) ($line['ledger_type'] ?? '')),
            'journal_date' => trim((string) ($line['journal_date'] ?? $header['journal_date'] ?? '')),
            'currency' => strtoupper(trim((string) ($line['journal_currency'] ?? ''))),
            'debit' => round((float) ($line['debit'] ?? 0), 6),
            'credit' => round((float) ($line['credit'] ?? 0), 6),
            'rate' => round((float) ($line['rate'] ?? 0), 8),
            'rate_date' => trim((string) ($line['rate_date'] ?? '')),
            'debit_ngn' => round((float) ($line['debit_ngn'] ?? 0), 2),
            'credit_ngn' => round((float) ($line['credit_ngn'] ?? 0), 2),
            'description' => trim((string) ($line['journal_description'] ?? '')),
        ];
    }

    return [
        'journal_id' => isset($header['journal_id']) ? (int) $header['journal_id'] : 0,
        'journal_date' => trim((string) ($header['journal_date'] ?? '')),
        'journal_type' => trim((string) ($header['journal_type'] ?? '')),
        'transaction_type' => trim((string) ($header['transaction_type'] ?? '')),
        'journal_currency' => strtoupper(trim((string) ($header['journal_currency'] ?? ''))),
        'description' => trim((string) ($header['journal_description'] ?? '')),
        'cost_center' => trim((string) ($header['cost_center'] ?? '')),
        'lines' => $lines,
    ];
}

function invoicePaymentRegistrationDraftJournal(mysqli $conn, array $payload): array
{
    $journalDate = invoicePaymentRegistrationNormaliseDate(
        (string) ($payload['journal_date'] ?? ''),
        'journal date'
    );
    $journalType = trim((string) ($payload['journal_type'] ?? ''));
    $transactionType = trim((string) ($payload['transaction_type'] ?? ''));
    $journalCurrency = strtoupper(trim((string) ($payload['journal_currency'] ?? 'NGN')));
    $description = trim((string) ($payload['main_journal_description'] ?? $payload['journal_description'] ?? ''));
    $costCenter = trim((string) ($payload['cost_center'] ?? ''));

    $requiredArrays = ['ledger_name', 'amount', 'sides', 'jcurrency', 'currency_rate'];
    foreach ($requiredArrays as $field) {
        if (!isset($payload[$field]) || !is_array($payload[$field]) || !$payload[$field]) {
            throw new RuntimeException("Journal {$field} lines are required for payment validation.", 422);
        }
    }
    $count = count($payload['ledger_name']);
    foreach ($requiredArrays as $field) {
        if (count($payload[$field]) !== $count) {
            throw new RuntimeException('The journal line arrays are inconsistent.', 422);
        }
    }

    $lineDates = isset($payload['journal_line_date']) && is_array($payload['journal_line_date'])
        ? $payload['journal_line_date']
        : [];
    $lineDescriptions = isset($payload['journal_description']) && is_array($payload['journal_description'])
        ? $payload['journal_description']
        : [];
    $rateDates = isset($payload['rate_date']) && is_array($payload['rate_date'])
        ? $payload['rate_date']
        : [];
    $ledgerNumbers = isset($payload['ledger_number']) && is_array($payload['ledger_number'])
        ? $payload['ledger_number']
        : [];

    $lines = [];
    for ($index = 0; $index < $count; $index++) {
        $ledgerName = trim((string) ($payload['ledger_name'][$index] ?? ''));
        $ledgerNumber = (int) ($ledgerNumbers[$index] ?? 0);
        $ledger = $ledgerNumber > 0
            ? invoicePaymentLedgerByNumber($conn, $ledgerNumber)
            : null;
        if (!$ledger && $ledgerName !== '') {
            $stmt = $conn->prepare(
                'SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
                 FROM ledger_table
                 WHERE ledger_name = ?
                 LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to validate the journal ledger.', 500);
            }
            $stmt->bind_param('s', $ledgerName);
            $stmt->execute();
            $ledger = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
        if (!$ledger) {
            throw new RuntimeException('Every invoice-payment journal line must use a valid ledger.', 422);
        }

        $side = trim((string) ($payload['sides'][$index] ?? ''));
        if (!in_array($side, ['Debit', 'Credit'], true)) {
            throw new RuntimeException('Every invoice-payment journal line must have a valid debit or credit side.', 422);
        }
        $amount = round((float) ($payload['amount'][$index] ?? 0), 6);
        $rate = round((float) ($payload['currency_rate'][$index] ?? 0), 8);
        if ($amount <= 0 || $rate <= 0) {
            throw new RuntimeException('Every invoice-payment journal line must have a positive amount and exchange rate.', 422);
        }
        $currency = invoicePaymentNormaliseCurrency(
            (string) ($payload['jcurrency'][$index] ?? ''),
            'Journal line currency'
        );
        $lineDate = invoicePaymentRegistrationNormaliseDate(
            (string) ($lineDates[$index] ?? $journalDate),
            'journal line date'
        );
        $amountNgn = round($amount * $rate, 2);

        $lines[] = [
            'source_index' => $index,
            'id' => null,
            'ledger_number' => (int) $ledger['ledger_number'],
            'ledger_name' => (string) $ledger['ledger_name'],
            'ledger_class' => (string) $ledger['ledger_class'],
            'ledger_class_code' => (int) $ledger['ledger_class_code'],
            'ledger_sub_class' => (string) $ledger['ledger_sub_class'],
            'ledger_type' => (string) $ledger['ledger_type'],
            'journal_date' => $lineDate,
            'currency' => $currency,
            'debit' => $side === 'Debit' ? $amount : 0.0,
            'credit' => $side === 'Credit' ? $amount : 0.0,
            'rate' => $rate,
            'rate_date' => trim((string) ($rateDates[$index] ?? $lineDate)),
            'debit_ngn' => $side === 'Debit' ? $amountNgn : 0.0,
            'credit_ngn' => $side === 'Credit' ? $amountNgn : 0.0,
            'description' => trim((string) ($lineDescriptions[$index] ?? $description)),
        ];
    }

    return [
        'journal_id' => 0,
        'journal_date' => $journalDate,
        'journal_type' => $journalType,
        'transaction_type' => $transactionType,
        'journal_currency' => $journalCurrency,
        'description' => $description,
        'cost_center' => $costCenter,
        'lines' => $lines,
    ];
}

function invoicePaymentRegistrationPostedAmount(
    mysqli $conn,
    string $invoiceNumber,
    int $excludeJournalId = 0
): float {
    $sql =
        "SELECT COALESCE(SUM(a.allocated_amount), 0) AS registered_amount
         FROM invoice_payment_allocations a
         INNER JOIN invoice_payments p ON p.id = a.payment_id
         WHERE a.invoice_number = ?
           AND p.status = 'Active'
           AND p.journal_id IS NOT NULL
           AND p.journal_validation_status = 'Validated'";
    if ($excludeJournalId > 0) {
        $sql .= ' AND p.journal_id <> ?';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate the registered invoice payments.', 500);
    }
    if ($excludeJournalId > 0) {
        $stmt->bind_param('si', $invoiceNumber, $excludeJournalId);
    } else {
        $stmt->bind_param('s', $invoiceNumber);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return round((float) ($row['registered_amount'] ?? 0), 2);
}

function invoicePaymentRegistrationExistingJournalLink(mysqli $conn, int $journalId): ?array
{
    if ($journalId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, payment_code, invoice_number, journal_id, reversal_journal_id,
                journal_origin, journal_validation_status, status, updated_at
         FROM invoice_payments
         WHERE journal_id = ? OR reversal_journal_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect the journal payment relationship.', 500);
    }
    $stmt->bind_param('ii', $journalId, $journalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function invoicePaymentRegistrationStableLine(array $line): array
{
    return [
        'ledger_number' => (int) $line['ledger_number'],
        'currency' => (string) $line['currency'],
        'debit' => round((float) $line['debit'], 6),
        'credit' => round((float) $line['credit'], 6),
        'rate' => round((float) $line['rate'], 8),
        'debit_ngn' => round((float) $line['debit_ngn'], 2),
        'credit_ngn' => round((float) $line['credit_ngn'], 2),
        'journal_date' => (string) $line['journal_date'],
        'rate_date' => (string) $line['rate_date'],
    ];
}

function invoicePaymentRegistrationAnalyse(
    mysqli $conn,
    array $invoice,
    array $journal,
    int $existingJournalId = 0,
    int $allowedPaymentId = 0
): array {
    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
    $invoiceCurrency = invoicePaymentNormaliseCurrency(
        (string) ($invoice['currency'] ?? ''),
        'Invoice currency'
    );
    $journalDate = invoicePaymentRegistrationNormaliseDate(
        (string) ($journal['journal_date'] ?? ''),
        'journal date'
    );
    $customerLedger = invoicePaymentCustomerLedger($conn, (string) ($invoice['clients_name'] ?? ''));
    if (!$customerLedger) {
        throw new RuntimeException('The invoice customer ledger could not be resolved.', 422);
    }

    $protectedTypes = ['year end closing', 'year end closing reversal', 'fx revaluation', 'fx revaluation reversal'];
    if (in_array(strtolower(trim((string) ($journal['transaction_type'] ?? ''))), $protectedTypes, true)) {
        throw new RuntimeException('A controlled system journal cannot be registered as an invoice payment.', 409);
    }

    $lines = [];
    $totalDebitNgn = 0.0;
    $totalCreditNgn = 0.0;
    foreach (($journal['lines'] ?? []) as $line) {
        $side = invoicePaymentRegistrationLineSide($line);
        if ($side === null) {
            throw new RuntimeException('A journal line cannot contain both a debit and a credit.', 422);
        }
        if ($side === 'Zero') {
            continue;
        }
        if ((string) ($line['journal_date'] ?? '') !== $journalDate) {
            throw new RuntimeException('All invoice-payment journal lines must use the journal payment date.', 422);
        }
        $line['side'] = $side;
        $line['currency'] = invoicePaymentNormaliseCurrency(
            (string) ($line['currency'] ?? ''),
            'Journal line currency'
        );
        $line['debit'] = round((float) ($line['debit'] ?? 0), 6);
        $line['credit'] = round((float) ($line['credit'] ?? 0), 6);
        $line['debit_ngn'] = round((float) ($line['debit_ngn'] ?? 0), 2);
        $line['credit_ngn'] = round((float) ($line['credit_ngn'] ?? 0), 2);
        $line['rate'] = round((float) ($line['rate'] ?? 0), 8);
        if ($line['rate'] <= 0) {
            throw new RuntimeException('Every invoice-payment journal line must have a positive exchange rate.', 422);
        }
        $totalDebitNgn += $line['debit_ngn'];
        $totalCreditNgn += $line['credit_ngn'];
        $lines[] = $line;
    }

    $totalDebitNgn = round($totalDebitNgn, 2);
    $totalCreditNgn = round($totalCreditNgn, 2);
    if (abs($totalDebitNgn - $totalCreditNgn) > 0.009) {
        throw new RuntimeException('The journal is not balanced in NGN.', 422);
    }
    if (count($lines) < 2 || count($lines) > 3) {
        throw new RuntimeException(
            'An invoice-payment journal must contain only the receipt, receivable settlement, and any required realized FX line.',
            422
        );
    }

    $customerLines = array_values(array_filter($lines, static function (array $line) use ($customerLedger): bool {
        return (int) $line['ledger_number'] === (int) $customerLedger['ledger_number'];
    }));
    if (count($customerLines) !== 1) {
        throw new RuntimeException('The journal must contain exactly one posting to the invoice customer ledger.', 422);
    }
    $receivableLine = $customerLines[0];
    if ($receivableLine['side'] !== 'Credit') {
        throw new RuntimeException('A customer invoice payment must credit the receivable ledger.', 422);
    }
    if ($receivableLine['currency'] !== $invoiceCurrency) {
        throw new RuntimeException("The receivable line must be posted in the invoice currency {$invoiceCurrency}.", 422);
    }

    $gainLedger = smartbooksFxRequiredLedger(
        $conn,
        SMARTBOOKS_FX_REALIZED_GAIN_LEDGER,
        'Realized Exchange Gain',
        'Revenue'
    );
    $lossLedger = smartbooksFxRequiredLedger(
        $conn,
        SMARTBOOKS_FX_REALIZED_LOSS_LEDGER,
        'Realized Exchange Loss',
        'Expense'
    );
    $gainLedgerNumber = (int) $gainLedger['ledger_number'];
    $lossLedgerNumber = (int) $lossLedger['ledger_number'];

    $receiptCandidates = array_values(array_filter($lines, static function (array $line) use (
        $customerLedger,
        $gainLedgerNumber,
        $lossLedgerNumber
    ): bool {
        $ledgerNumber = (int) $line['ledger_number'];
        return $line['side'] === 'Debit'
            && $ledgerNumber !== (int) $customerLedger['ledger_number']
            && $ledgerNumber !== $gainLedgerNumber
            && $ledgerNumber !== $lossLedgerNumber;
    }));
    if (count($receiptCandidates) !== 1) {
        throw new RuntimeException('The journal must contain exactly one debit to the receiving bank or cash ledger.', 422);
    }
    $receiptLine = $receiptCandidates[0];

    $invoiceAmountSettled = round((float) $receivableLine['credit'], 2);
    $paymentAmountReceived = round((float) $receiptLine['debit'], 2);
    $carryingValueNgn = round((float) $receivableLine['credit_ngn'], 2);
    $settlementValueNgn = round((float) $receiptLine['debit_ngn'], 2);
    if ($invoiceAmountSettled <= 0 || $paymentAmountReceived <= 0 || $carryingValueNgn <= 0 || $settlementValueNgn <= 0) {
        throw new RuntimeException('The receipt and receivable settlement values must be greater than zero.', 422);
    }

    $realizedDifference = round($settlementValueNgn - $carryingValueNgn, 2);
    $realizedGain = $realizedDifference > 0.009 ? $realizedDifference : 0.0;
    $realizedLoss = $realizedDifference < -0.009 ? abs($realizedDifference) : 0.0;
    $fxLines = array_values(array_filter($lines, static function (array $line) use (
        $gainLedgerNumber,
        $lossLedgerNumber
    ): bool {
        return in_array((int) $line['ledger_number'], [$gainLedgerNumber, $lossLedgerNumber], true);
    }));

    if ($realizedGain > 0) {
        if (count($fxLines) !== 1 ||
            (int) $fxLines[0]['ledger_number'] !== $gainLedgerNumber ||
            $fxLines[0]['side'] !== 'Credit' ||
            $fxLines[0]['currency'] !== 'NGN' ||
            abs((float) $fxLines[0]['credit_ngn'] - $realizedGain) > 0.009) {
            throw new RuntimeException(
                'The journal must credit the realized exchange gain ledger for the exact settlement difference.',
                422
            );
        }
    } elseif ($realizedLoss > 0) {
        if (count($fxLines) !== 1 ||
            (int) $fxLines[0]['ledger_number'] !== $lossLedgerNumber ||
            $fxLines[0]['side'] !== 'Debit' ||
            $fxLines[0]['currency'] !== 'NGN' ||
            abs((float) $fxLines[0]['debit_ngn'] - $realizedLoss) > 0.009) {
            throw new RuntimeException(
                'The journal must debit the realized exchange loss ledger for the exact settlement difference.',
                422
            );
        }
    } elseif ($fxLines) {
        throw new RuntimeException('A realized FX line is not required because the settlement has no exchange difference.', 422);
    }

    $nonSettlementLines = array_values(array_filter($lines, static function (array $line) use (
        $receiptLine,
        $receivableLine,
        $gainLedgerNumber,
        $lossLedgerNumber
    ): bool {
        $ledgerNumber = (int) $line['ledger_number'];
        if ($ledgerNumber === (int) $receiptLine['ledger_number'] && $line['side'] === 'Debit') {
            return false;
        }
        if ($ledgerNumber === (int) $receivableLine['ledger_number'] && $line['side'] === 'Credit') {
            return false;
        }
        return !in_array($ledgerNumber, [$gainLedgerNumber, $lossLedgerNumber], true);
    }));
    if ($nonSettlementLines) {
        throw new RuntimeException('The journal contains lines that do not belong to this invoice settlement.', 422);
    }

    $existingLink = invoicePaymentRegistrationExistingJournalLink($conn, $existingJournalId);
    if ($existingLink) {
        $existingPaymentId = (int) ($existingLink['id'] ?? 0);
        $isPrimaryJournal = (int) ($existingLink['journal_id'] ?? 0) === $existingJournalId;
        $isAllowedLink = $allowedPaymentId > 0
            && $existingPaymentId === $allowedPaymentId
            && $isPrimaryJournal
            && strcasecmp((string) ($existingLink['status'] ?? ''), 'Active') === 0
            && strcasecmp((string) ($existingLink['journal_validation_status'] ?? ''), 'Validated') === 0;

        if (!$isAllowedLink) {
            throw new RuntimeException(
                "Journal #{$existingJournalId} is already connected to payment {$existingLink['payment_code']}.",
                409
            );
        }
    }

    $invoiceTotal = round((float) ($invoice['invoice_amount'] ?? 0), 2);
    $registeredAmount = invoicePaymentRegistrationPostedAmount($conn, $invoiceNumber, $existingJournalId);
    $availableToRegister = round(max(0, $invoiceTotal - $registeredAmount), 2);
    if ($invoiceAmountSettled > $availableToRegister + 0.009) {
        throw new RuntimeException(
            'The journal settlement exceeds the invoice amount that remains available for journal registration (' .
            number_format($availableToRegister, 2) . ' ' . $invoiceCurrency . ').',
            409
        );
    }

    $paymentCurrency = (string) $receiptLine['currency'];
    $paymentRate = $paymentAmountReceived > 0
        ? round($settlementValueNgn / $paymentAmountReceived, 8)
        : 0.0;
    $carryingRate = $invoiceAmountSettled > 0
        ? round($carryingValueNgn / $invoiceAmountSettled, 8)
        : 0.0;
    $crossCurrencyRate = $invoiceAmountSettled > 0
        ? round($paymentAmountReceived / $invoiceAmountSettled, 8)
        : 0.0;
    $settlementRate = $invoiceAmountSettled > 0
        ? round($settlementValueNgn / $invoiceAmountSettled, 8)
        : 0.0;
    $paymentRateDate = trim((string) ($receiptLine['rate_date'] ?? '')) ?: $journalDate;
    try {
        $paymentRateDate = invoicePaymentRegistrationNormaliseDate($paymentRateDate, 'payment rate date');
    } catch (Throwable $error) {
        if ($existingJournalId <= 0) {
            throw $error;
        }
        // Older posted journals may contain the rate recording timestamp rather
        // than its effective date. Preserve that value in the validation snapshot
        // but use the journal date as the historical payment effective date.
        $paymentRateDate = $journalDate;
    }
    if ($paymentRateDate > $journalDate) {
        if ($existingJournalId <= 0) {
            throw new RuntimeException('The payment rate date cannot be later than the journal date.', 422);
        }
        $paymentRateDate = $journalDate;
    }

    $stableLines = array_map('invoicePaymentRegistrationStableLine', $lines);
    usort($stableLines, static function (array $left, array $right): int {
        return [
            $left['ledger_number'],
            $left['currency'],
            $left['debit'],
            $left['credit'],
            $left['rate'],
        ] <=> [
            $right['ledger_number'],
            $right['currency'],
            $right['debit'],
            $right['credit'],
            $right['rate'],
        ];
    });
    $tokenBasis = [
        'invoice_number' => $invoiceNumber,
        'invoice_updated_at' => (string) ($invoice['updated_at'] ?? ''),
        'registered_amount' => $registeredAmount,
        'available_to_register' => $availableToRegister,
        'journal_date' => $journalDate,
        'journal_type' => (string) ($journal['journal_type'] ?? ''),
        'transaction_type' => (string) ($journal['transaction_type'] ?? ''),
        'journal_currency' => (string) ($journal['journal_currency'] ?? ''),
        'description' => (string) ($journal['description'] ?? ''),
        'allowed_payment_id' => $allowedPaymentId,
        'existing_payment_updated_at' => $existingLink && $allowedPaymentId > 0
            ? (string) ($existingLink['updated_at'] ?? '')
            : '',
        'lines' => $stableLines,
    ];
    $previewToken = hash(
        'sha256',
        json_encode($tokenBasis, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
    );

    $realizedFxLedgerNumber = $realizedGain > 0
        ? $gainLedgerNumber
        : ($realizedLoss > 0 ? $lossLedgerNumber : null);

    return [
        'can_register' => true,
        'preview_token' => $previewToken,
        'invoice' => [
            'invoice_number' => $invoiceNumber,
            'clients_name' => (string) ($invoice['clients_name'] ?? ''),
            'currency' => $invoiceCurrency,
            'invoice_total' => $invoiceTotal,
            'registered_amount' => $registeredAmount,
            'available_to_register' => $availableToRegister,
        ],
        'journal' => [
            'journal_id' => $existingJournalId,
            'journal_date' => $journalDate,
            'journal_type' => (string) ($journal['journal_type'] ?? ''),
            'transaction_type' => (string) ($journal['transaction_type'] ?? ''),
            'description' => (string) ($journal['description'] ?? ''),
            'total_debit_ngn' => $totalDebitNgn,
            'total_credit_ngn' => $totalCreditNgn,
        ],
        'settlement' => [
            'invoice_currency' => $invoiceCurrency,
            'invoice_amount_settled' => $invoiceAmountSettled,
            'payment_currency' => $paymentCurrency,
            'payment_amount_received' => $paymentAmountReceived,
            'cross_currency_rate' => $crossCurrencyRate,
            'payment_rate_date' => $paymentRateDate,
            'payment_currency_rate_ngn' => $paymentRate,
            'settlement_rate' => $settlementRate,
            'settlement_value_ngn' => $settlementValueNgn,
            'carrying_rate' => $carryingRate,
            'carrying_value_settled_ngn' => $carryingValueNgn,
            'realized_fx_gain_ngn' => $realizedGain,
            'realized_fx_loss_ngn' => $realizedLoss,
            'realized_fx_ledger_number' => $realizedFxLedgerNumber,
            'bank_ledger_number' => (int) $receiptLine['ledger_number'],
            'bank_ledger_name' => (string) $receiptLine['ledger_name'],
            'customer_ledger_number' => (int) $receivableLine['ledger_number'],
            'customer_ledger_name' => (string) $receivableLine['ledger_name'],
        ],
        'validation_snapshot' => [
            'invoice_number' => $invoiceNumber,
            'journal_date' => $journalDate,
            'receipt_line' => invoicePaymentRegistrationStableLine($receiptLine),
            'receivable_line' => invoicePaymentRegistrationStableLine($receivableLine),
            'fx_lines' => array_map('invoicePaymentRegistrationStableLine', $fxLines),
            'settlement' => [
                'invoice_amount_settled' => $invoiceAmountSettled,
                'payment_amount_received' => $paymentAmountReceived,
                'settlement_value_ngn' => $settlementValueNgn,
                'carrying_value_settled_ngn' => $carryingValueNgn,
                'realized_fx_gain_ngn' => $realizedGain,
                'realized_fx_loss_ngn' => $realizedLoss,
            ],
        ],
    ];
}

function invoicePaymentRegistrationDefaultMethod(array $analysis): string
{
    $transactionType = strtolower(trim((string) ($analysis['journal']['transaction_type'] ?? '')));
    if ($transactionType === 'cash') {
        return 'Journal Cash';
    }
    if ($transactionType === 'bank') {
        return 'Journal Bank';
    }
    return 'Journal Entry';
}

function invoicePaymentRegistrationUnlinkedPayments(mysqli $conn, string $invoiceNumber): array
{
    $stmt = $conn->prepare(
        "SELECT p.id, p.payment_code, p.payment_method, p.invoice_currency,
                p.invoice_amount_settled, p.payment_currency, p.payment_amount_received,
                p.journal_id, p.journal_validation_status, p.status,
                a.id AS allocation_id, a.allocated_amount, a.allocation_currency
         FROM invoice_payments p
         INNER JOIN invoice_payment_allocations a
            ON a.payment_id = p.id
           AND a.invoice_number = ?
         WHERE p.invoice_number = ?
           AND p.status = 'Active'
           AND p.journal_id IS NULL
           AND p.journal_validation_status <> 'Validated'
         ORDER BY (p.payment_method = 'Legacy Balance') DESC, p.id ASC
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect unlinked invoice payments.', 500);
    }
    $stmt->bind_param('ss', $invoiceNumber, $invoiceNumber);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function invoicePaymentRegistrationInsertPayment(
    mysqli $conn,
    array $analysis,
    int $journalId,
    array $user,
    array $metadata,
    ?string $forcedPaymentCode = null
): array {
    $settlement = $analysis['settlement'];
    $invoice = $analysis['invoice'];
    $paymentCode = $forcedPaymentCode ?: generateInvoicePaymentCode($conn);
    $paymentMethod = trim((string) ($metadata['payment_method'] ?? ''));
    if ($paymentMethod === '') {
        $paymentMethod = invoicePaymentRegistrationDefaultMethod($analysis);
    }
    $transactionReference = trim((string) ($metadata['transaction_reference'] ?? ''));
    $notes = trim((string) ($metadata['notes'] ?? ''));
    $transactionReferenceValue = $transactionReference !== '' ? $transactionReference : null;
    $notesValue = $notes !== '' ? $notes : null;
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $validationHash = (string) $analysis['preview_token'];
    $snapshotJson = json_encode(
        $analysis['validation_snapshot'],
        JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
    );
    $journalNarration = (string) ($analysis['journal']['description'] ?? '');

    $stmt = $conn->prepare(
        "INSERT INTO invoice_payments
            (payment_code, invoice_number, payment_date, amount, currency,
             invoice_currency, invoice_amount_settled, payment_currency, payment_amount_received,
             cross_currency_rate, payment_rate_date, payment_currency_rate_ngn,
             payment_method, transaction_reference, notes, post_journal, journal_id,
             journal_origin, journal_validation_status, journal_validation_hash,
             journal_validation_snapshot, journal_linked_at, journal_linked_by_user_id,
             journal_linked_by_email, journal_narration, bank_ledger_number,
             customer_ledger_number, settlement_rate_date, settlement_rate,
             settlement_value_ngn, carrying_rate, carrying_value_settled_ngn,
             realized_fx_gain_ngn, realized_fx_loss_ngn, realized_fx_ledger_number,
             journal_posted_at, status, recorded_by_user_id, recorded_by_email)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?,
                 'Manual', 'Validated', ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 NOW(), 'Active', ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to create the invoice payment register entry.', 500);
    }

    $paymentDate = (string) $analysis['journal']['journal_date'];
    $paymentAmount = (float) $settlement['payment_amount_received'];
    $paymentCurrency = (string) $settlement['payment_currency'];
    $invoiceCurrency = (string) $settlement['invoice_currency'];
    $invoiceAmount = (float) $settlement['invoice_amount_settled'];
    $crossRate = (float) $settlement['cross_currency_rate'];
    $rateDate = (string) $settlement['payment_rate_date'];
    $paymentRate = (float) $settlement['payment_currency_rate_ngn'];
    $bankLedger = (int) $settlement['bank_ledger_number'];
    $customerLedger = (int) $settlement['customer_ledger_number'];
    $settlementRate = (float) $settlement['settlement_rate'];
    $settlementValue = (float) $settlement['settlement_value_ngn'];
    $carryingRate = (float) $settlement['carrying_rate'];
    $carryingValue = (float) $settlement['carrying_value_settled_ngn'];
    $gain = (float) $settlement['realized_fx_gain_ngn'];
    $loss = (float) $settlement['realized_fx_loss_ngn'];
    $fxLedger = $settlement['realized_fx_ledger_number'] !== null
        ? (int) $settlement['realized_fx_ledger_number']
        : null;
    $invoiceNumber = (string) $invoice['invoice_number'];

    $stmt->bind_param(
        'sssdssdsddsdsssississiisddddddiis',
        $paymentCode,
        $invoiceNumber,
        $paymentDate,
        $paymentAmount,
        $paymentCurrency,
        $invoiceCurrency,
        $invoiceAmount,
        $paymentCurrency,
        $paymentAmount,
        $crossRate,
        $rateDate,
        $paymentRate,
        $paymentMethod,
        $transactionReferenceValue,
        $notesValue,
        $journalId,
        $validationHash,
        $snapshotJson,
        $userId,
        $userEmail,
        $journalNarration,
        $bankLedger,
        $customerLedger,
        $rateDate,
        $settlementRate,
        $settlementValue,
        $carryingRate,
        $carryingValue,
        $gain,
        $loss,
        $fxLedger,
        $userId,
        $userEmail
    );
    $stmt->execute();
    $paymentId = (int) $stmt->insert_id;
    $stmt->close();

    $allocationStmt = $conn->prepare(
        'INSERT INTO invoice_payment_allocations
            (payment_id, invoice_number, allocated_amount, allocation_currency)
         VALUES (?, ?, ?, ?)'
    );
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to allocate the journal payment to the invoice.', 500);
    }
    $allocationStmt->bind_param('isds', $paymentId, $invoiceNumber, $invoiceAmount, $invoiceCurrency);
    $allocationStmt->execute();
    $allocationStmt->close();

    return [
        'id' => $paymentId,
        'payment_code' => $paymentCode,
        'invoice_number' => $invoiceNumber,
        'journal_id' => $journalId,
        'journal_origin' => 'Manual',
        'journal_validation_status' => 'Validated',
    ];
}

function invoicePaymentRegistrationUpdateExistingPayment(
    mysqli $conn,
    array $existing,
    array $analysis,
    int $journalId,
    array $user,
    array $metadata
): array {
    $settlement = $analysis['settlement'];
    $paymentMethod = trim((string) ($metadata['payment_method'] ?? ''));
    if ($paymentMethod === '') {
        $paymentMethod = invoicePaymentRegistrationDefaultMethod($analysis);
    }
    $transactionReference = trim((string) ($metadata['transaction_reference'] ?? ''));
    $notes = trim((string) ($metadata['notes'] ?? ''));
    $transactionReferenceValue = $transactionReference !== '' ? $transactionReference : null;
    $notesValue = $notes !== '' ? $notes : null;
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $validationHash = (string) $analysis['preview_token'];
    $snapshotJson = json_encode(
        $analysis['validation_snapshot'],
        JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
    );
    $journalNarration = (string) ($analysis['journal']['description'] ?? '');
    $paymentId = (int) $existing['id'];
    $allocationId = (int) $existing['allocation_id'];

    $stmt = $conn->prepare(
        "UPDATE invoice_payments
         SET payment_date = ?, amount = ?, currency = ?, invoice_currency = ?,
             invoice_amount_settled = ?, payment_currency = ?, payment_amount_received = ?,
             cross_currency_rate = ?, payment_rate_date = ?, payment_currency_rate_ngn = ?,
             payment_method = ?, transaction_reference = ?, notes = ?, post_journal = 0,
             journal_id = ?, journal_origin = 'Manual', journal_validation_status = 'Validated',
             journal_validation_hash = ?, journal_validation_snapshot = ?, journal_linked_at = NOW(),
             journal_linked_by_user_id = ?, journal_linked_by_email = ?, journal_narration = ?,
             bank_ledger_number = ?, customer_ledger_number = ?, settlement_rate_date = ?,
             settlement_rate = ?, settlement_value_ngn = ?, carrying_rate = ?,
             carrying_value_settled_ngn = ?, realized_fx_gain_ngn = ?, realized_fx_loss_ngn = ?,
             realized_fx_ledger_number = ?, journal_posted_at = NOW(), updated_at = NOW()
         WHERE id = ? AND status = 'Active' AND journal_id IS NULL"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to harmonize the existing invoice payment.', 500);
    }

    $paymentDate = (string) $analysis['journal']['journal_date'];
    $paymentAmount = (float) $settlement['payment_amount_received'];
    $paymentCurrency = (string) $settlement['payment_currency'];
    $invoiceCurrency = (string) $settlement['invoice_currency'];
    $invoiceAmount = (float) $settlement['invoice_amount_settled'];
    $crossRate = (float) $settlement['cross_currency_rate'];
    $rateDate = (string) $settlement['payment_rate_date'];
    $paymentRate = (float) $settlement['payment_currency_rate_ngn'];
    $bankLedger = (int) $settlement['bank_ledger_number'];
    $customerLedger = (int) $settlement['customer_ledger_number'];
    $settlementRate = (float) $settlement['settlement_rate'];
    $settlementValue = (float) $settlement['settlement_value_ngn'];
    $carryingRate = (float) $settlement['carrying_rate'];
    $carryingValue = (float) $settlement['carrying_value_settled_ngn'];
    $gain = (float) $settlement['realized_fx_gain_ngn'];
    $loss = (float) $settlement['realized_fx_loss_ngn'];
    $fxLedger = $settlement['realized_fx_ledger_number'] !== null
        ? (int) $settlement['realized_fx_ledger_number']
        : null;

    $stmt->bind_param(
        'sdssdsddsdsssississiisddddddii',
        $paymentDate,
        $paymentAmount,
        $paymentCurrency,
        $invoiceCurrency,
        $invoiceAmount,
        $paymentCurrency,
        $paymentAmount,
        $crossRate,
        $rateDate,
        $paymentRate,
        $paymentMethod,
        $transactionReferenceValue,
        $notesValue,
        $journalId,
        $validationHash,
        $snapshotJson,
        $userId,
        $userEmail,
        $journalNarration,
        $bankLedger,
        $customerLedger,
        $rateDate,
        $settlementRate,
        $settlementValue,
        $carryingRate,
        $carryingValue,
        $gain,
        $loss,
        $fxLedger,
        $paymentId
    );
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The existing payment changed before it could be harmonized.', 409);
    }
    $stmt->close();

    $allocationStmt = $conn->prepare(
        'UPDATE invoice_payment_allocations
         SET allocated_amount = ?, allocation_currency = ?
         WHERE id = ? AND payment_id = ?'
    );
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to update the existing payment allocation.', 500);
    }
    $allocationStmt->bind_param('dsii', $invoiceAmount, $invoiceCurrency, $allocationId, $paymentId);
    $allocationStmt->execute();
    $allocationStmt->close();

    return [
        'id' => $paymentId,
        'payment_code' => (string) $existing['payment_code'],
        'invoice_number' => (string) $analysis['invoice']['invoice_number'],
        'journal_id' => $journalId,
        'journal_origin' => 'Manual',
        'journal_validation_status' => 'Validated',
        'reused_existing_payment' => true,
    ];
}

function invoicePaymentRegistrationSplitLegacyPayment(
    mysqli $conn,
    array $legacy,
    array $analysis,
    int $journalId,
    array $user,
    array $metadata
): array {
    $targetAmount = round((float) $analysis['settlement']['invoice_amount_settled'], 2);
    $legacyAmount = round((float) $legacy['allocated_amount'], 2);
    $remaining = round($legacyAmount - $targetAmount, 2);
    if ($remaining <= 0.009) {
        return invoicePaymentRegistrationUpdateExistingPayment(
            $conn,
            $legacy,
            $analysis,
            $journalId,
            $user,
            $metadata
        );
    }

    $paymentId = (int) $legacy['id'];
    $allocationId = (int) $legacy['allocation_id'];
    $stmt = $conn->prepare(
        'UPDATE invoice_payments
         SET amount = ?, invoice_amount_settled = ?, payment_amount_received = ?, updated_at = NOW()
         WHERE id = ? AND status = \'Active\' AND journal_id IS NULL'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to split the legacy payment balance.', 500);
    }
    $stmt->bind_param('dddi', $remaining, $remaining, $remaining, $paymentId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The legacy payment changed before it could be split.', 409);
    }
    $stmt->close();

    $allocationStmt = $conn->prepare(
        'UPDATE invoice_payment_allocations SET allocated_amount = ? WHERE id = ? AND payment_id = ?'
    );
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to split the legacy payment allocation.', 500);
    }
    $allocationStmt->bind_param('dii', $remaining, $allocationId, $paymentId);
    $allocationStmt->execute();
    if ($allocationStmt->affected_rows !== 1) {
        $allocationStmt->close();
        throw new RuntimeException('The legacy payment allocation changed before it could be split.', 409);
    }
    $allocationStmt->close();

    $payment = invoicePaymentRegistrationInsertPayment($conn, $analysis, $journalId, $user, $metadata);
    $payment['split_legacy_payment_id'] = $paymentId;
    $payment['legacy_balance_remaining'] = $remaining;
    return $payment;
}


function invoicePaymentRegistrationUpdateLinkedPayment(
    mysqli $conn,
    array $payment,
    array $analysis,
    array $user,
    array $metadata,
    string $submittedPreviewToken
): array {
    if ($submittedPreviewToken === '') {
        throw new RuntimeException('Preview the invoice-payment link before updating it.', 422);
    }
    if (!hash_equals((string) $analysis['preview_token'], $submittedPreviewToken)) {
        throw new RuntimeException(
            'The invoice, journal, or payment register changed after preview. Generate a new preview before saving.',
            409
        );
    }

    $paymentId = (int) ($payment['id'] ?? 0);
    $journalId = (int) ($payment['journal_id'] ?? 0);
    if ($paymentId <= 0 || $journalId <= 0) {
        throw new RuntimeException('The linked payment record is incomplete.', 409);
    }
    if (strcasecmp((string) ($payment['status'] ?? ''), 'Active') !== 0) {
        throw new RuntimeException('Only an active payment link can be updated.', 409);
    }
    if (!empty($payment['reversal_journal_id'])) {
        throw new RuntimeException('A reversed payment link cannot be updated.', 409);
    }

    $allocationStmt = $conn->prepare(
        'SELECT id, invoice_number, allocated_amount, allocation_currency
         FROM invoice_payment_allocations
         WHERE payment_id = ?
         ORDER BY id ASC
         FOR UPDATE'
    );
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to load the payment allocation.', 500);
    }
    $allocationStmt->bind_param('i', $paymentId);
    $allocationStmt->execute();
    $allocations = $allocationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $allocationStmt->close();
    if (count($allocations) !== 1) {
        throw new RuntimeException(
            'This payment must have exactly one invoice allocation before its journal link can be managed.',
            409
        );
    }
    $allocation = $allocations[0];

    $settlement = $analysis['settlement'];
    $invoiceNumber = (string) $analysis['invoice']['invoice_number'];
    $paymentMethod = trim((string) ($metadata['payment_method'] ?? ''));
    if ($paymentMethod === '') {
        $paymentMethod = invoicePaymentRegistrationDefaultMethod($analysis);
    }
    $transactionReference = trim((string) ($metadata['transaction_reference'] ?? ''));
    $notes = trim((string) ($metadata['notes'] ?? ''));
    $transactionReferenceValue = $transactionReference !== '' ? $transactionReference : null;
    $notesValue = $notes !== '' ? $notes : null;
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $validationHash = (string) $analysis['preview_token'];
    $snapshotJson = json_encode(
        $analysis['validation_snapshot'],
        JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
    );
    $journalNarration = (string) ($analysis['journal']['description'] ?? '');

    $paymentDate = (string) $analysis['journal']['journal_date'];
    $paymentAmount = (float) $settlement['payment_amount_received'];
    $paymentCurrency = (string) $settlement['payment_currency'];
    $invoiceCurrency = (string) $settlement['invoice_currency'];
    $invoiceAmount = (float) $settlement['invoice_amount_settled'];
    $crossRate = (float) $settlement['cross_currency_rate'];
    $rateDate = (string) $settlement['payment_rate_date'];
    $paymentRate = (float) $settlement['payment_currency_rate_ngn'];
    $bankLedger = (int) $settlement['bank_ledger_number'];
    $customerLedger = (int) $settlement['customer_ledger_number'];
    $settlementRate = (float) $settlement['settlement_rate'];
    $settlementValue = (float) $settlement['settlement_value_ngn'];
    $carryingRate = (float) $settlement['carrying_rate'];
    $carryingValue = (float) $settlement['carrying_value_settled_ngn'];
    $gain = (float) $settlement['realized_fx_gain_ngn'];
    $loss = (float) $settlement['realized_fx_loss_ngn'];
    $fxLedger = $settlement['realized_fx_ledger_number'] !== null
        ? (int) $settlement['realized_fx_ledger_number']
        : null;

    $updateStmt = $conn->prepare(
        "UPDATE invoice_payments
         SET invoice_number = ?, payment_date = ?, amount = ?, currency = ?,
             invoice_currency = ?, invoice_amount_settled = ?, payment_currency = ?,
             payment_amount_received = ?, cross_currency_rate = ?, payment_rate_date = ?,
             payment_currency_rate_ngn = ?, payment_method = ?, transaction_reference = ?,
             notes = ?, post_journal = 0, journal_origin = 'Manual',
             journal_validation_status = 'Validated', journal_validation_hash = ?,
             journal_validation_snapshot = ?, journal_linked_at = NOW(),
             journal_linked_by_user_id = ?, journal_linked_by_email = ?,
             journal_narration = ?, bank_ledger_number = ?, customer_ledger_number = ?,
             settlement_rate_date = ?, settlement_rate = ?, settlement_value_ngn = ?,
             carrying_rate = ?, carrying_value_settled_ngn = ?, realized_fx_gain_ngn = ?,
             realized_fx_loss_ngn = ?, realized_fx_ledger_number = ?,
             journal_posted_at = COALESCE(journal_posted_at, NOW()), updated_at = NOW()
         WHERE id = ? AND journal_id = ? AND status = 'Active'"
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to update the linked invoice payment.', 500);
    }
    $updateStmt->bind_param(
        'ssdssdsddsdsssssissiisddddddiii',
        $invoiceNumber,
        $paymentDate,
        $paymentAmount,
        $paymentCurrency,
        $invoiceCurrency,
        $invoiceAmount,
        $paymentCurrency,
        $paymentAmount,
        $crossRate,
        $rateDate,
        $paymentRate,
        $paymentMethod,
        $transactionReferenceValue,
        $notesValue,
        $validationHash,
        $snapshotJson,
        $userId,
        $userEmail,
        $journalNarration,
        $bankLedger,
        $customerLedger,
        $rateDate,
        $settlementRate,
        $settlementValue,
        $carryingRate,
        $carryingValue,
        $gain,
        $loss,
        $fxLedger,
        $paymentId,
        $journalId
    );
    $updateStmt->execute();
    if ($updateStmt->affected_rows > 1) {
        $updateStmt->close();
        throw new RuntimeException('The payment link changed before it could be updated.', 409);
    }
    $updateStmt->close();

    $allocationId = (int) $allocation['id'];
    $allocationUpdateStmt = $conn->prepare(
        'UPDATE invoice_payment_allocations
         SET invoice_number = ?, allocated_amount = ?, allocation_currency = ?
         WHERE id = ? AND payment_id = ?'
    );
    if (!$allocationUpdateStmt) {
        throw new RuntimeException('Unable to update the invoice allocation.', 500);
    }
    $allocationUpdateStmt->bind_param(
        'sdsii',
        $invoiceNumber,
        $invoiceAmount,
        $invoiceCurrency,
        $allocationId,
        $paymentId
    );
    $allocationUpdateStmt->execute();
    if ($allocationUpdateStmt->affected_rows > 1) {
        $allocationUpdateStmt->close();
        throw new RuntimeException('The invoice allocation could not be updated safely.', 409);
    }
    $allocationUpdateStmt->close();

    $updatedPayment = invoicePaymentManualLinkLoadPayment($conn, $paymentId, '', true);
    $oldInvoiceNumber = trim((string) ($payment['invoice_number'] ?? ''));
    $reason = $oldInvoiceNumber !== $invoiceNumber
        ? "Payment link moved from Invoice #{$oldInvoiceNumber} to Invoice #{$invoiceNumber}."
        : 'Payment link details were revalidated and updated.';

    invoicePaymentManualLinkRecordEvent(
        $conn,
        $updatedPayment,
        $journalId,
        'LinkUpdated',
        'Validated',
        $user,
        $reason,
        $validationHash,
        $analysis['validation_snapshot']
    );

    $oldInvoiceSummary = null;
    if ($oldInvoiceNumber !== '' && $oldInvoiceNumber !== $invoiceNumber) {
        $oldInvoiceSummary = syncInvoicePaymentState(
            $conn,
            $oldInvoiceNumber,
            $user,
            "Payment {$updatedPayment['payment_code']} was reassigned to Invoice #{$invoiceNumber}."
        );
    }
    $newInvoiceSummary = syncInvoicePaymentState(
        $conn,
        $invoiceNumber,
        $user,
        "Payment {$updatedPayment['payment_code']} was revalidated from Journal #{$journalId}."
    );

    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if ($logStmt) {
        $action = $oldInvoiceNumber !== $invoiceNumber
            ? "{$userEmail} moved payment {$updatedPayment['payment_code']} from Invoice #{$oldInvoiceNumber} to Invoice #{$invoiceNumber} without changing Journal #{$journalId}"
            : "{$userEmail} revalidated payment {$updatedPayment['payment_code']} for Invoice #{$invoiceNumber} without changing Journal #{$journalId}";
        $logStmt->bind_param('iss', $userId, $action, $userEmail);
        $logStmt->execute();
        $logStmt->close();
    }

    notifyAccountingUsers(
        $conn,
        'invoice_payment_link_updated',
        'invoice',
        "Payment {$updatedPayment['payment_code']} link updated",
        $oldInvoiceNumber !== $invoiceNumber
            ? "{$userEmail} moved the validated Journal #{$journalId} payment from Invoice #{$oldInvoiceNumber} to Invoice #{$invoiceNumber}."
            : "{$userEmail} revalidated the Journal #{$journalId} payment details for Invoice #{$invoiceNumber}.",
        'info',
        'invoice',
        $invoiceNumber,
        "/invoice/view/{$invoiceNumber}",
        [
            'payment_id' => $paymentId,
            'payment_code' => (string) $updatedPayment['payment_code'],
            'journal_id' => $journalId,
            'old_invoice_number' => $oldInvoiceNumber,
            'invoice_number' => $invoiceNumber,
        ],
        $userId
    );

    return [
        'id' => $paymentId,
        'payment_code' => (string) $updatedPayment['payment_code'],
        'invoice_number' => $invoiceNumber,
        'journal_id' => $journalId,
        'journal_origin' => 'Manual',
        'journal_validation_status' => 'Validated',
        'payment_method' => $paymentMethod,
        'transaction_reference' => $transactionReferenceValue,
        'notes' => $notesValue,
        'invoice_currency' => $invoiceCurrency,
        'invoice_amount_settled' => $invoiceAmount,
        'payment_currency' => $paymentCurrency,
        'payment_amount_received' => $paymentAmount,
        'payment_summary' => $newInvoiceSummary,
        'previous_invoice_summary' => $oldInvoiceSummary,
    ];
}

function invoicePaymentRegistrationPersist(
    mysqli $conn,
    array $invoice,
    array $analysis,
    int $journalId,
    array $user,
    array $metadata,
    string $submittedPreviewToken
): array {
    if ($submittedPreviewToken === '') {
        throw new RuntimeException('Preview the invoice-payment registration before posting.', 422);
    }
    if (!hash_equals((string) $analysis['preview_token'], $submittedPreviewToken)) {
        throw new RuntimeException(
            'The invoice, journal lines, or existing payment register changed after preview. Generate a new preview before posting.',
            409
        );
    }

    $invoiceNumber = (string) $analysis['invoice']['invoice_number'];
    $targetAmount = round((float) $analysis['settlement']['invoice_amount_settled'], 2);
    $invoiceCurrency = (string) $analysis['settlement']['invoice_currency'];
    $unlinkedPayments = invoicePaymentRegistrationUnlinkedPayments($conn, $invoiceNumber);

    $exactLegacy = null;
    $exactPending = null;
    $legacyLarger = null;
    $legacySmaller = null;
    $nonLegacyPending = [];
    foreach ($unlinkedPayments as $row) {
        $allocationCurrency = strtoupper(trim((string) $row['allocation_currency']));
        $amount = round((float) $row['allocated_amount'], 2);
        $isLegacy = strcasecmp((string) $row['payment_method'], 'Legacy Balance') === 0;

        if (!$isLegacy) {
            $nonLegacyPending[] = $row;
            if ($allocationCurrency === $invoiceCurrency && abs($amount - $targetAmount) <= 0.009) {
                $exactPending = $row;
            }
            continue;
        }
        if ($allocationCurrency !== $invoiceCurrency) {
            continue;
        }
        if (abs($amount - $targetAmount) <= 0.009 && $exactLegacy === null) {
            $exactLegacy = $row;
        } elseif ($amount > $targetAmount + 0.009 && $legacyLarger === null) {
            $legacyLarger = $row;
        } elseif ($amount < $targetAmount - 0.009 && $legacySmaller === null) {
            $legacySmaller = $row;
        }
    }

    if ($nonLegacyPending) {
        if (count($nonLegacyPending) !== 1 || $exactPending === null) {
            throw new RuntimeException(
                'This invoice already has an unlinked payment with different or ambiguous amounts. Link or correct that pending payment before registering another journal.',
                409
            );
        }
        $payment = invoicePaymentRegistrationUpdateExistingPayment(
            $conn,
            $exactPending,
            $analysis,
            $journalId,
            $user,
            $metadata
        );
    } elseif ($exactLegacy) {
        $payment = invoicePaymentRegistrationUpdateExistingPayment(
            $conn,
            $exactLegacy,
            $analysis,
            $journalId,
            $user,
            $metadata
        );
    } elseif ($legacyLarger) {
        $payment = invoicePaymentRegistrationSplitLegacyPayment(
            $conn,
            $legacyLarger,
            $analysis,
            $journalId,
            $user,
            $metadata
        );
    } elseif ($legacySmaller) {
        $payment = invoicePaymentRegistrationUpdateExistingPayment(
            $conn,
            $legacySmaller,
            $analysis,
            $journalId,
            $user,
            $metadata
        );
        $payment['legacy_amount_corrected'] = true;
    } else {
        $payment = invoicePaymentRegistrationInsertPayment(
            $conn,
            $analysis,
            $journalId,
            $user,
            $metadata
        );
    }

    $loadedPayment = invoicePaymentManualLinkLoadPayment($conn, (int) $payment['id'], '', true);
    invoicePaymentManualLinkRecordEvent(
        $conn,
        $loadedPayment,
        $journalId,
        'RegisteredFromJournal',
        'Validated',
        $user,
        null,
        (string) $analysis['preview_token'],
        $analysis['validation_snapshot']
    );

    $summary = syncInvoicePaymentState(
        $conn,
        $invoiceNumber,
        $user,
        "Invoice payment harmonized with Journal #{$journalId}."
    );

    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if ($logStmt) {
        $action = "{$userEmail} registered Journal #{$journalId} as invoice payment {$payment['payment_code']} for Invoice #{$invoiceNumber}";
        $logStmt->bind_param('iss', $userId, $action, $userEmail);
        $logStmt->execute();
        $logStmt->close();
    }

    notifyAccountingUsers(
        $conn,
        'invoice_payment_registered_from_journal',
        'invoice',
        "Journal #{$journalId} registered as invoice payment",
        "{$userEmail} linked Journal #{$journalId} to Invoice #{$invoiceNumber}.",
        'info',
        'invoice',
        $invoiceNumber,
        "/invoice/view/{$invoiceNumber}",
        [
            'payment_id' => (int) $payment['id'],
            'payment_code' => (string) $payment['payment_code'],
            'journal_id' => $journalId,
            'invoice_number' => $invoiceNumber,
        ],
        $userId
    );

    $payment['payment_summary'] = $summary;
    $payment['settlement'] = $analysis['settlement'];
    return $payment;
}
