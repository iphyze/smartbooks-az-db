<?php
declare(strict_types=1);

/**
 * Helpers used only by invoice-payment journal posting.
 * Existing manual journal create/edit flows remain unchanged.
 */

function invoicePaymentLedgerByNumber(mysqli $conn, int $ledgerNumber): ?array
{
    if ($ledgerNumber <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
         FROM ledger_table
         WHERE ledger_number = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the selected ledger.', 500);
    }

    $stmt->bind_param('i', $ledgerNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
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

function invoicePaymentCurrencyRate(mysqli $conn, string $currency, string $postingDate): array
{
    $currency = strtoupper(trim($currency));
    if (!in_array($currency, ['NGN', 'USD', 'EUR', 'GBP'], true)) {
        throw new RuntimeException("Journal posting is not configured for {$currency} payments.", 422);
    }

    $stmt = $conn->prepare(
        'SELECT id, created_at, ngn_rate, usd_rate, eur_rate, gbp_rate
         FROM currency_table
         WHERE created_at <= ?
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to resolve the exchange rate for this payment.', 500);
    }
    $stmt->bind_param('s', $postingDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException("No exchange rate exists on or before {$postingDate}.", 422);
    }

    $rateField = strtolower($currency) . '_rate';
    $rate = (float) ($row[$rateField] ?? 0);
    if ($rate <= 0) {
        throw new RuntimeException("The {$currency} rate on {$row['created_at']} is invalid.", 422);
    }

    return [
        'id' => (int) $row['id'],
        'rate_date' => (string) $row['created_at'],
        'rate' => $rate,
        'ngn_rate' => (float) $row['ngn_rate'],
        'usd_rate' => (float) $row['usd_rate'],
        'eur_rate' => (float) $row['eur_rate'],
        'gbp_rate' => (float) $row['gbp_rate'],
    ];
}

function assertInvoicePaymentPostingDateOpen(mysqli $conn, string $postingDate): void
{
    $stmt = $conn->prepare(
        "SELECT id
         FROM accounting_periods
         WHERE start_date <= ?
           AND end_date >= ?
           AND is_locked = 'Locked'
         ORDER BY id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the accounting period.', 500);
    }
    $stmt->bind_param('ss', $postingDate, $postingDate);
    $stmt->execute();
    $locked = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($locked) {
        throw new RuntimeException('The payment date falls within a locked accounting period.', 409);
    }
}

function nextInvoicePaymentJournalId(mysqli $conn): int
{
    $stmt = $conn->prepare('SELECT MAX(journal_id) AS last_journal_id FROM journal_table');
    if (!$stmt) {
        throw new RuntimeException('Unable to generate the journal reference.', 500);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $row['last_journal_id'] === null ? 101 : ((int) $row['last_journal_id'] + 1);
}

function invoicePaymentReference(string $invoiceNumber): string
{
    $invoiceNumber = trim($invoiceNumber);
    return stripos($invoiceNumber, 'AZ-') === 0 ? $invoiceNumber : 'AZ-' . $invoiceNumber;
}

function insertInvoicePaymentMainJournalLine(
    mysqli $conn,
    int $journalId,
    string $journalType,
    string $transactionType,
    string $journalDate,
    string $currency,
    string $description,
    float $debit,
    float $credit,
    array $rateData,
    string $costCenter,
    array $ledger,
    string $userEmail
): void {
    $debitNgn = round($debit * (float) $rateData['rate'], 2);
    $creditNgn = round($credit * (float) $rateData['rate'], 2);

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

    $ledgerNumber = (int) $ledger['ledger_number'];
    $ledgerClassCode = (int) $ledger['ledger_class_code'];
    $rate = (float) $rateData['rate'];
    $ngnRate = (float) $rateData['ngn_rate'];
    $usdRate = (float) $rateData['usd_rate'];
    $eurRate = (float) $rateData['eur_rate'];
    $gbpRate = (float) $rateData['gbp_rate'];
    $rateDate = (string) $rateData['rate_date'];
    $ledgerName = (string) $ledger['ledger_name'];
    $ledgerClass = (string) $ledger['ledger_class'];
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
        $rate,
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
    $stmt->close();
}

/**
 * Post the two-line receipt journal generated by an invoice payment.
 */
function postInvoicePaymentJournal(
    mysqli $conn,
    array $invoice,
    string $paymentDate,
    float $amount,
    int $bankLedgerNumber,
    int $creditLedgerNumber,
    bool $isComplete,
    array $user
): array {
    assertInvoicePaymentPostingDateOpen($conn, $paymentDate);

    $currency = strtoupper(trim((string) ($invoice['currency'] ?? '')));
    $clientName = trim((string) ($invoice['clients_name'] ?? ''));
    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
    $bankLedger = invoicePaymentLedgerByNumber($conn, $bankLedgerNumber);
    $creditLedger = $creditLedgerNumber > 0
        ? invoicePaymentLedgerByNumber($conn, $creditLedgerNumber)
        : invoicePaymentCustomerLedger($conn, $clientName);

    if (!$bankLedger || strcasecmp((string) ($bankLedger['ledger_type'] ?? ''), 'Bank Accounts') !== 0) {
        throw new RuntimeException('Select a valid Bank Accounts ledger for the receipt.', 422);
    }
    if (!$creditLedger) {
        throw new RuntimeException('Select a valid ledger to credit for this receipt.', 422);
    }
    if ((int) $bankLedger['ledger_number'] === (int) $creditLedger['ledger_number']) {
        throw new RuntimeException('The debit and credit sides must use different ledgers.', 422);
    }

    $rateData = invoicePaymentCurrencyRate($conn, $currency, $paymentDate);
    $journalId = nextInvoicePaymentJournalId($conn);
    $journalType = 'Receipt';
    $transactionType = 'Bank';
    $receiptType = $isComplete ? 'complete' : 'part';
    $narration = sprintf(
        'Being %s receipt on Inv. No. %s IFO %s',
        $receiptType,
        invoicePaymentReference($invoiceNumber),
        $clientName
    );
    $costCenter = $clientName;
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $converted = round($amount * (float) $rateData['rate'], 2);
    $otherAmount = $currency === 'NGN' ? 0.0 : $amount;

    $headerStmt = $conn->prepare(
        'INSERT INTO journal_table
            (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description,
             debit, credit, rate_date, rate, debit_ngn, credit_ngn, debit_others, credit_others,
             cost_center, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$headerStmt) {
        throw new RuntimeException('Unable to prepare the invoice payment journal.', 500);
    }

    $rateDate = (string) $rateData['rate_date'];
    $rate = (float) $rateData['rate'];
    $headerStmt->bind_param(
        'isssssddsdddddsss',
        $journalId,
        $journalType,
        $transactionType,
        $paymentDate,
        $currency,
        $narration,
        $amount,
        $amount,
        $rateDate,
        $rate,
        $converted,
        $converted,
        $otherAmount,
        $otherAmount,
        $costCenter,
        $userEmail,
        $userEmail
    );
    $headerStmt->execute();
    $headerStmt->close();

    insertInvoicePaymentMainJournalLine(
        $conn,
        $journalId,
        $journalType,
        $transactionType,
        $paymentDate,
        $currency,
        $narration,
        $amount,
        0.0,
        $rateData,
        $costCenter,
        $bankLedger,
        $userEmail
    );
    insertInvoicePaymentMainJournalLine(
        $conn,
        $journalId,
        $journalType,
        $transactionType,
        $paymentDate,
        $currency,
        $narration,
        0.0,
        $amount,
        $rateData,
        $costCenter,
        $creditLedger,
        $userEmail
    );

    return [
        'journal_id' => $journalId,
        'narration' => $narration,
        'rate_date' => $rateDate,
        'rate' => $rate,
        'bank_ledger' => $bankLedger,
        'credit_ledger' => $creditLedger,
    ];
}

/**
 * Create the accounting reversal for a previously posted receipt journal.
 */
function reverseInvoicePaymentJournal(mysqli $conn, array $payment, array $invoice, array $user): int
{
    $bankLedgerNumber = (int) ($payment['bank_ledger_number'] ?? 0);
    $creditLedgerNumber = (int) ($payment['customer_ledger_number'] ?? 0);
    $bankLedger = invoicePaymentLedgerByNumber($conn, $bankLedgerNumber);
    $creditLedger = invoicePaymentLedgerByNumber($conn, $creditLedgerNumber);
    if (!$bankLedger || !$creditLedger) {
        throw new RuntimeException('The ledgers used by the original payment journal are no longer available.', 409);
    }

    $postingDate = date('Y-m-d');
    assertInvoicePaymentPostingDateOpen($conn, $postingDate);
    $currency = strtoupper((string) $payment['currency']);
    $amount = round((float) $payment['amount'], 2);
    $rateData = invoicePaymentCurrencyRate($conn, $currency, $postingDate);
    $journalId = nextInvoicePaymentJournalId($conn);
    $journalType = 'Receipt';
    $transactionType = 'Bank';
    $originalNarration = trim((string) ($payment['journal_narration'] ?? ''));
    $narration = 'Being reversal of ' . ($originalNarration !== ''
        ? lcfirst($originalNarration)
        : 'receipt on Inv. No. ' . invoicePaymentReference((string) $payment['invoice_number']));
    $clientName = trim((string) ($invoice['clients_name'] ?? ''));
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $converted = round($amount * (float) $rateData['rate'], 2);
    $otherAmount = $currency === 'NGN' ? 0.0 : $amount;

    $headerStmt = $conn->prepare(
        'INSERT INTO journal_table
            (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description,
             debit, credit, rate_date, rate, debit_ngn, credit_ngn, debit_others, credit_others,
             cost_center, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$headerStmt) {
        throw new RuntimeException('Unable to prepare the payment reversal journal.', 500);
    }
    $rateDate = (string) $rateData['rate_date'];
    $rate = (float) $rateData['rate'];
    $headerStmt->bind_param(
        'isssssddsdddddsss',
        $journalId,
        $journalType,
        $transactionType,
        $postingDate,
        $currency,
        $narration,
        $amount,
        $amount,
        $rateDate,
        $rate,
        $converted,
        $converted,
        $otherAmount,
        $otherAmount,
        $clientName,
        $userEmail,
        $userEmail
    );
    $headerStmt->execute();
    $headerStmt->close();

    insertInvoicePaymentMainJournalLine(
        $conn,
        $journalId,
        $journalType,
        $transactionType,
        $postingDate,
        $currency,
        $narration,
        0.0,
        $amount,
        $rateData,
        $clientName,
        $bankLedger,
        $userEmail
    );
    insertInvoicePaymentMainJournalLine(
        $conn,
        $journalId,
        $journalType,
        $transactionType,
        $postingDate,
        $currency,
        $narration,
        $amount,
        0.0,
        $rateData,
        $clientName,
        $creditLedger,
        $userEmail
    );

    return $journalId;
}
