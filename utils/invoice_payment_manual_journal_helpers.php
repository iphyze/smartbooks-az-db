<?php
declare(strict_types=1);

/**
 * Helpers for validating and linking an existing manual journal to an invoice
 * payment. The existing journal header/line model remains authoritative.
 */

function invoicePaymentManualLinkColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect the payment-journal linking schema.', 500);
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    $cache[$key] = $exists;
    return $exists;
}

function invoicePaymentManualLinkTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect the payment-journal linking schema.', 500);
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    $cache[$key] = $exists;
    return $exists;
}

function invoicePaymentManualLinkRequireSchema(mysqli $conn): void
{
    $requiredColumns = [
        'journal_origin',
        'journal_validation_status',
        'journal_validation_hash',
        'journal_validation_snapshot',
        'journal_linked_at',
        'journal_linked_by_user_id',
        'journal_linked_by_email',
        'journal_unlinked_at',
        'journal_unlinked_by_user_id',
        'journal_unlinked_by_email',
        'journal_unlink_reason',
    ];
    foreach ($requiredColumns as $column) {
        if (!invoicePaymentManualLinkColumnExists($conn, 'invoice_payments', $column)) {
            throw new RuntimeException(
                'The manual payment-journal linking migration has not been applied. Apply 20260723_manual_payment_journal_linking.sql first.',
                503
            );
        }
    }
    if (!invoicePaymentManualLinkTableExists($conn, 'invoice_payment_journal_link_events')) {
        throw new RuntimeException(
            'The manual payment-journal linking migration has not been applied. Apply 20260723_manual_payment_journal_linking.sql first.',
            503
        );
    }
}

function invoicePaymentManualLinkResolvePaymentIdentifier(array $payload): array
{
    $paymentId = (int) ($payload['payment_id'] ?? 0);
    $paymentCode = trim((string) ($payload['payment_code'] ?? ''));
    if ($paymentId <= 0 && $paymentCode === '') {
        throw new RuntimeException('Select a payment to link.', 422);
    }
    return [$paymentId, $paymentCode];
}

function invoicePaymentManualLinkLoadPayment(
    mysqli $conn,
    int $paymentId,
    string $paymentCode = '',
    bool $forUpdate = false
): array {
    invoicePaymentManualLinkRequireSchema($conn);

    $where = $paymentId > 0 ? 'id = ?' : 'payment_code = ?';
    $sql = "SELECT id, payment_code, invoice_number, payment_date,
                   invoice_currency, invoice_amount_settled,
                   payment_currency, payment_amount_received,
                   cross_currency_rate, payment_rate_date, payment_currency_rate_ngn,
                   payment_method, bank_id, bank_name, account_name, account_number,
                   transaction_reference, notes, post_journal, journal_id,
                   journal_origin, journal_validation_status, journal_validation_hash,
                   journal_validation_snapshot, journal_narration, bank_ledger_number,
                   customer_ledger_number, settlement_rate_date, settlement_rate,
                   settlement_value_ngn, carrying_rate, carrying_value_settled_ngn,
                   realized_fx_gain_ngn, realized_fx_loss_ngn, realized_fx_ledger_number,
                   journal_posted_at, journal_linked_at, journal_linked_by_user_id,
                   journal_linked_by_email, reversal_journal_id, status,
                   recorded_by_user_id, recorded_by_email, created_at, updated_at
            FROM invoice_payments
            WHERE {$where}
            LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load the invoice payment.', 500);
    }
    if ($paymentId > 0) {
        $stmt->bind_param('i', $paymentId);
    } else {
        $stmt->bind_param('s', $paymentCode);
    }
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) {
        throw new RuntimeException('Payment record not found.', 404);
    }

    $intFields = [
        'id', 'bank_id', 'journal_id', 'bank_ledger_number', 'customer_ledger_number',
        'realized_fx_ledger_number', 'journal_linked_by_user_id', 'reversal_journal_id',
        'recorded_by_user_id',
    ];
    foreach ($intFields as $field) {
        $payment[$field] = $payment[$field] !== null ? (int) $payment[$field] : null;
    }
    $floatFields = [
        'invoice_amount_settled', 'payment_amount_received', 'cross_currency_rate',
        'payment_currency_rate_ngn', 'settlement_rate', 'settlement_value_ngn',
        'carrying_rate', 'carrying_value_settled_ngn', 'realized_fx_gain_ngn',
        'realized_fx_loss_ngn',
    ];
    foreach ($floatFields as $field) {
        $payment[$field] = $payment[$field] !== null ? (float) $payment[$field] : null;
    }
    $payment['post_journal'] = (bool) $payment['post_journal'];
    $payment['invoice_currency'] = strtoupper(trim((string) $payment['invoice_currency']));
    $payment['payment_currency'] = strtoupper(trim((string) $payment['payment_currency']));
    return $payment;
}

function invoicePaymentManualLinkLoadJournal(mysqli $conn, int $journalId, bool $forUpdate = false): array
{
    if ($journalId <= 0) {
        throw new RuntimeException('Select a valid journal to link.', 422);
    }

    $headerSql =
        'SELECT id, journal_id, journal_type, transaction_type, journal_date,
                journal_currency, journal_description, debit, credit, rate_date, rate,
                debit_ngn, credit_ngn, debit_others, credit_others, cost_center,
                created_by, updated_by, created_at, updated_at
         FROM journal_table
         WHERE journal_id = ?
         ORDER BY id ASC';
    if ($forUpdate) {
        $headerSql .= ' FOR UPDATE';
    }
    $headerStmt = $conn->prepare($headerSql);
    if (!$headerStmt) {
        throw new RuntimeException('Unable to load the journal header.', 500);
    }
    $headerStmt->bind_param('i', $journalId);
    $headerStmt->execute();
    $headers = $headerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $headerStmt->close();
    if (!$headers) {
        throw new RuntimeException('Journal not found.', 404);
    }

    $lineSql =
        'SELECT id, journal_id, journal_type, transaction_type, journal_date,
                journal_currency, journal_description, debit, credit, rate, rate_date,
                debit_ngn, credit_ngn, ngn_rate, usd_rate, eur_rate, gbp_rate,
                cost_center, ledger_name, ledger_number, ledger_class,
                ledger_class_code, ledger_sub_class, ledger_type, created_by,
                updated_by, created_at, updated_at
         FROM main_journal_table
         WHERE journal_id = ?
         ORDER BY id ASC';
    if ($forUpdate) {
        $lineSql .= ' FOR UPDATE';
    }
    $lineStmt = $conn->prepare($lineSql);
    if (!$lineStmt) {
        throw new RuntimeException('Unable to load the journal lines.', 500);
    }
    $lineStmt->bind_param('i', $journalId);
    $lineStmt->execute();
    $lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lineStmt->close();
    if (!$lines) {
        throw new RuntimeException('The selected journal has no ledger lines.', 409);
    }

    foreach ($lines as &$line) {
        $line['id'] = (int) $line['id'];
        $line['journal_id'] = (int) $line['journal_id'];
        $line['ledger_number'] = (int) $line['ledger_number'];
        $line['ledger_class_code'] = (int) $line['ledger_class_code'];
        foreach (['debit', 'credit', 'rate', 'debit_ngn', 'credit_ngn'] as $field) {
            $line[$field] = (float) $line[$field];
        }
        $line['journal_currency'] = strtoupper(trim((string) $line['journal_currency']));
    }
    unset($line);

    return [
        'header_count' => count($headers),
        'header' => $headers[0],
        'headers' => $headers,
        'lines' => $lines,
    ];
}

function invoicePaymentManualLinkExpectedLines(array $payment): array
{
    $settlementValue = round((float) ($payment['settlement_value_ngn'] ?? 0), 2);
    $carryingValue = round((float) ($payment['carrying_value_settled_ngn'] ?? 0), 2);
    $invoiceAmount = round((float) ($payment['invoice_amount_settled'] ?? 0), 2);
    $paymentAmount = round((float) ($payment['payment_amount_received'] ?? 0), 2);
    $carryingRate = (float) ($payment['carrying_rate'] ?? 0);
    $journalReceivableAmount = $carryingRate > 0
        ? round($carryingValue / $carryingRate, 2)
        : $invoiceAmount;
    $customerLedger = (int) ($payment['customer_ledger_number'] ?? 0);

    if (
        $settlementValue <= 0
        || $carryingValue <= 0
        || $invoiceAmount <= 0
        || $journalReceivableAmount <= 0
        || $paymentAmount <= 0
    ) {
        throw new RuntimeException(
            'This payment does not contain the stored settlement and carrying values required for manual journal validation.',
            409
        );
    }
    if ($customerLedger <= 0) {
        throw new RuntimeException('This payment does not contain a customer ledger to validate.', 409);
    }

    $expected = [
        [
            'purpose' => 'receipt',
            'side' => 'Debit',
            'ledger_number' => ($payment['bank_ledger_number'] ?? null) !== null
                ? (int) $payment['bank_ledger_number']
                : null,
            'ledger_is_selectable' => ($payment['bank_ledger_number'] ?? null) === null,
            'currency' => strtoupper((string) $payment['payment_currency']),
            'amount' => $paymentAmount,
            'amount_ngn' => $settlementValue,
            'rate' => (float) ($payment['payment_currency_rate_ngn'] ?? 1),
        ],
        [
            'purpose' => 'receivable_settlement',
            'side' => 'Credit',
            'ledger_number' => $customerLedger,
            'ledger_is_selectable' => false,
            'currency' => strtoupper((string) $payment['invoice_currency']),
            'amount' => $journalReceivableAmount,
            'amount_ngn' => $carryingValue,
            'rate' => $carryingRate > 0 ? $carryingRate : 1.0,
        ],
    ];

    $gain = round((float) ($payment['realized_fx_gain_ngn'] ?? 0), 2);
    $loss = round((float) ($payment['realized_fx_loss_ngn'] ?? 0), 2);
    if ($gain > 0.009) {
        $expected[] = [
            'purpose' => 'realized_fx_gain',
            'side' => 'Credit',
            'ledger_number' => (int) ($payment['realized_fx_ledger_number'] ?? 0),
            'ledger_is_selectable' => false,
            'currency' => 'NGN',
            'amount' => $gain,
            'amount_ngn' => $gain,
            'rate' => 1.0,
        ];
    } elseif ($loss > 0.009) {
        $expected[] = [
            'purpose' => 'realized_fx_loss',
            'side' => 'Debit',
            'ledger_number' => (int) ($payment['realized_fx_ledger_number'] ?? 0),
            'ledger_is_selectable' => false,
            'currency' => 'NGN',
            'amount' => $loss,
            'amount_ngn' => $loss,
            'rate' => 1.0,
        ];
    }

    foreach ($expected as $line) {
        if (!$line['ledger_is_selectable'] && (int) $line['ledger_number'] <= 0) {
            throw new RuntimeException('The payment is missing a required ledger for manual journal validation.', 409);
        }
    }
    return $expected;
}

function invoicePaymentManualLinkLineSide(array $line): ?string
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

function invoicePaymentManualLinkLineMatches(array $expected, array $actual): bool
{
    $side = invoicePaymentManualLinkLineSide($actual);
    if ($side !== $expected['side']) {
        return false;
    }
    if (strtoupper(trim((string) $actual['journal_currency'])) !== $expected['currency']) {
        return false;
    }
    if (!$expected['ledger_is_selectable'] && (int) $actual['ledger_number'] !== (int) $expected['ledger_number']) {
        return false;
    }

    $amount = $side === 'Debit' ? (float) $actual['debit'] : (float) $actual['credit'];
    $amountNgn = $side === 'Debit' ? (float) $actual['debit_ngn'] : (float) $actual['credit_ngn'];
    return abs($amount - (float) $expected['amount']) <= 0.009
        && abs($amountNgn - (float) $expected['amount_ngn']) <= 0.009;
}

function invoicePaymentManualLinkNormalisedActualLine(array $line): array
{
    return [
        'id' => (int) $line['id'],
        'ledger_number' => (int) $line['ledger_number'],
        'ledger_name' => (string) $line['ledger_name'],
        'side' => invoicePaymentManualLinkLineSide($line),
        'currency' => strtoupper((string) $line['journal_currency']),
        'debit' => round((float) $line['debit'], 6),
        'credit' => round((float) $line['credit'], 6),
        'rate' => round((float) $line['rate'], 8),
        'debit_ngn' => round((float) $line['debit_ngn'], 2),
        'credit_ngn' => round((float) $line['credit_ngn'], 2),
        'journal_date' => (string) $line['journal_date'],
    ];
}

function invoicePaymentManualLinkValidate(
    mysqli $conn,
    array $payment,
    int $journalId,
    bool $forUpdate = false
): array {
    $journal = invoicePaymentManualLinkLoadJournal($conn, $journalId, $forUpdate);
    $header = $journal['header'];
    $actualLines = $journal['lines'];
    $expectedLines = invoicePaymentManualLinkExpectedLines($payment);
    $blockers = [];
    $warnings = [];

    if (strcasecmp((string) $payment['status'], 'Active') !== 0) {
        $blockers[] = ['code' => 'PAYMENT_NOT_ACTIVE', 'message' => 'Only an active payment can be linked to a journal.'];
    }
    if ((bool) $payment['post_journal']) {
        $blockers[] = ['code' => 'AUTOMATIC_JOURNAL_PAYMENT', 'message' => 'This payment was configured to post its journal automatically.'];
    }
    $existingJournalId = (int) ($payment['journal_id'] ?? 0);
    if ($existingJournalId > 0 && $existingJournalId !== $journalId) {
        $blockers[] = [
            'code' => 'PAYMENT_ALREADY_LINKED',
            'message' => "This payment is already linked to Journal #{$existingJournalId}.",
        ];
    }
    if (!empty($payment['reversal_journal_id'])) {
        $blockers[] = ['code' => 'PAYMENT_JOURNAL_REVERSED', 'message' => 'The payment already has a journal reversal.'];
    }
    if ((int) $journal['header_count'] !== 1) {
        $blockers[] = [
            'code' => 'DUPLICATE_JOURNAL_HEADER',
            'message' => "Journal #{$journalId} has more than one header and cannot be linked safely.",
        ];
    }

    $protectedTypes = ['year end closing', 'year end closing reversal', 'fx revaluation', 'fx revaluation reversal'];
    if (in_array(strtolower(trim((string) $header['transaction_type'])), $protectedTypes, true)) {
        $blockers[] = ['code' => 'SYSTEM_JOURNAL', 'message' => 'A controlled system journal cannot be linked to a payment.'];
    }

    $otherLinkStmt = $conn->prepare(
        "SELECT id, payment_code, status, journal_validation_status
         FROM invoice_payments
         WHERE journal_id = ? AND id <> ?
         ORDER BY id ASC
         LIMIT 1"
    );
    if (!$otherLinkStmt) {
        throw new RuntimeException('Unable to check whether the journal is already linked.', 500);
    }
    $paymentId = (int) $payment['id'];
    $otherLinkStmt->bind_param('ii', $journalId, $paymentId);
    $otherLinkStmt->execute();
    $otherLink = $otherLinkStmt->get_result()->fetch_assoc();
    $otherLinkStmt->close();
    if ($otherLink) {
        $blockers[] = [
            'code' => 'JOURNAL_ALREADY_LINKED',
            'message' => "Journal #{$journalId} is already linked to payment {$otherLink['payment_code']}.",
        ];
    }

    $reversalUseStmt = $conn->prepare(
        'SELECT id, payment_code FROM invoice_payments WHERE reversal_journal_id = ? LIMIT 1'
    );
    if (!$reversalUseStmt) {
        throw new RuntimeException('Unable to validate the journal usage.', 500);
    }
    $reversalUseStmt->bind_param('i', $journalId);
    $reversalUseStmt->execute();
    $reversalUse = $reversalUseStmt->get_result()->fetch_assoc();
    $reversalUseStmt->close();
    if ($reversalUse) {
        $blockers[] = [
            'code' => 'REVERSAL_JOURNAL',
            'message' => "Journal #{$journalId} is already a reversal for payment {$reversalUse['payment_code']}.",
        ];
    }

    $paymentDate = (string) $payment['payment_date'];
    if ((string) $header['journal_date'] !== $paymentDate) {
        $blockers[] = [
            'code' => 'HEADER_DATE_MISMATCH',
            'message' => "The journal date must be the payment date {$paymentDate}.",
        ];
    }

    $nonZeroLines = [];
    $totalDebitNgn = 0.0;
    $totalCreditNgn = 0.0;
    foreach ($actualLines as $line) {
        $side = invoicePaymentManualLinkLineSide($line);
        if ($side === null) {
            $blockers[] = [
                'code' => 'LINE_HAS_BOTH_SIDES',
                'message' => "Journal line #{$line['id']} contains both a debit and a credit.",
            ];
        }
        if ((string) $line['journal_date'] !== $paymentDate) {
            $blockers[] = [
                'code' => 'LINE_DATE_MISMATCH',
                'message' => "Journal line #{$line['id']} must use the payment date {$paymentDate}.",
            ];
        }
        $totalDebitNgn += (float) $line['debit_ngn'];
        $totalCreditNgn += (float) $line['credit_ngn'];
        if ($side !== 'Zero') {
            $nonZeroLines[] = $line;
        }
    }
    $totalDebitNgn = round($totalDebitNgn, 2);
    $totalCreditNgn = round($totalCreditNgn, 2);
    if (abs($totalDebitNgn - $totalCreditNgn) > 0.009) {
        $blockers[] = ['code' => 'JOURNAL_NOT_BALANCED', 'message' => 'The journal is not balanced in NGN.'];
    }
    if (abs((float) $header['debit_ngn'] - $totalDebitNgn) > 0.009 ||
        abs((float) $header['credit_ngn'] - $totalCreditNgn) > 0.009) {
        $blockers[] = [
            'code' => 'HEADER_TOTAL_MISMATCH',
            'message' => 'The journal header totals do not agree with its ledger lines.',
        ];
    }
    if (count($nonZeroLines) !== count($expectedLines)) {
        $blockers[] = [
            'code' => 'UNEXPECTED_LINE_COUNT',
            'message' => 'The journal must contain only the receipt, receivable settlement, and required realized FX lines for this payment.',
        ];
    }

    $unusedActualIndexes = array_keys($nonZeroLines);
    $matches = [];
    $candidateDebitLedgerNumber = null;
    foreach ($expectedLines as $expectedIndex => $expected) {
        $matchedIndex = null;
        foreach ($unusedActualIndexes as $position => $actualIndex) {
            if (invoicePaymentManualLinkLineMatches($expected, $nonZeroLines[$actualIndex])) {
                $matchedIndex = $actualIndex;
                unset($unusedActualIndexes[$position]);
                break;
            }
        }
        if ($matchedIndex === null) {
            $ledgerText = $expected['ledger_is_selectable']
                ? 'the receiving ledger'
                : 'ledger ' . $expected['ledger_number'];
            $blockers[] = [
                'code' => strtoupper($expected['purpose']) . '_MISMATCH',
                'message' => sprintf(
                    'The %s line must be a %s of %s %.2f with an NGN value of %.2f on %s.',
                    str_replace('_', ' ', $expected['purpose']),
                    strtolower($expected['side']),
                    $expected['currency'],
                    $expected['amount'],
                    $expected['amount_ngn'],
                    $ledgerText
                ),
            ];
            continue;
        }
        $matchedLine = $nonZeroLines[$matchedIndex];
        if ($expected['purpose'] === 'receipt') {
            $candidateDebitLedgerNumber = (int) $matchedLine['ledger_number'];
        }
        $matches[] = [
            'purpose' => $expected['purpose'],
            'expected' => $expected,
            'actual' => invoicePaymentManualLinkNormalisedActualLine($matchedLine),
        ];
    }
    if (!empty($unusedActualIndexes)) {
        $blockers[] = [
            'code' => 'UNEXPECTED_JOURNAL_LINES',
            'message' => 'The journal contains one or more ledger lines that do not belong to this payment settlement.',
        ];
    }

    $expectedDebitNgn = round(array_sum(array_map(static function (array $line): float {
        return $line['side'] === 'Debit' ? (float) $line['amount_ngn'] : 0.0;
    }, $expectedLines)), 2);
    $expectedCreditNgn = round(array_sum(array_map(static function (array $line): float {
        return $line['side'] === 'Credit' ? (float) $line['amount_ngn'] : 0.0;
    }, $expectedLines)), 2);

    if ($candidateDebitLedgerNumber !== null && ($payment['bank_ledger_number'] ?? null) === null) {
        $warnings[] = [
            'code' => 'RECEIVING_LEDGER_FROM_JOURNAL',
            'message' => "Ledger {$candidateDebitLedgerNumber} will be recorded as the receiving ledger for this payment.",
        ];
    }

    $actualNormalised = array_map('invoicePaymentManualLinkNormalisedActualLine', $actualLines);
    $basis = [
        'payment_id' => (int) $payment['id'],
        'payment_code' => (string) $payment['payment_code'],
        'payment_updated_at' => (string) $payment['updated_at'],
        'journal_id' => $journalId,
        'journal_updated_at' => (string) ($header['updated_at'] ?? ''),
        'expected_lines' => $expectedLines,
        'actual_lines' => $actualNormalised,
        'candidate_debit_ledger_number' => $candidateDebitLedgerNumber,
    ];
    $previewToken = hash(
        'sha256',
        json_encode($basis, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
    );

    return [
        'payment' => [
            'id' => (int) $payment['id'],
            'payment_code' => (string) $payment['payment_code'],
            'invoice_number' => (string) $payment['invoice_number'],
            'payment_date' => $paymentDate,
            'invoice_currency' => (string) $payment['invoice_currency'],
            'invoice_amount_settled' => (float) $payment['invoice_amount_settled'],
            'payment_currency' => (string) $payment['payment_currency'],
            'payment_amount_received' => (float) $payment['payment_amount_received'],
            'settlement_value_ngn' => (float) $payment['settlement_value_ngn'],
            'carrying_value_settled_ngn' => (float) $payment['carrying_value_settled_ngn'],
            'realized_fx_gain_ngn' => (float) $payment['realized_fx_gain_ngn'],
            'realized_fx_loss_ngn' => (float) $payment['realized_fx_loss_ngn'],
        ],
        'journal' => [
            'journal_id' => $journalId,
            'journal_date' => (string) $header['journal_date'],
            'journal_type' => (string) $header['journal_type'],
            'transaction_type' => (string) $header['transaction_type'],
            'description' => (string) $header['journal_description'],
            'total_debit_ngn' => $totalDebitNgn,
            'total_credit_ngn' => $totalCreditNgn,
        ],
        'expected_lines' => $expectedLines,
        'actual_lines' => $actualNormalised,
        'matches' => $matches,
        'candidate_debit_ledger_number' => $candidateDebitLedgerNumber,
        'summary' => [
            'expected_line_count' => count($expectedLines),
            'actual_line_count' => count($nonZeroLines),
            'expected_debit_ngn' => $expectedDebitNgn,
            'expected_credit_ngn' => $expectedCreditNgn,
            'actual_debit_ngn' => $totalDebitNgn,
            'actual_credit_ngn' => $totalCreditNgn,
        ],
        'blockers' => $blockers,
        'warnings' => $warnings,
        'can_link' => empty($blockers),
        'preview_token' => $previewToken,
        'validation_snapshot' => $basis,
    ];
}

function invoicePaymentManualLinkRecordEvent(
    mysqli $conn,
    array $payment,
    ?int $journalId,
    string $eventType,
    string $validationStatus,
    array $user,
    ?string $reason = null,
    ?string $validationHash = null,
    ?array $validationSnapshot = null
): void {
    invoicePaymentManualLinkRequireSchema($conn);
    $snapshotJson = $validationSnapshot !== null
        ? json_encode($validationSnapshot, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
        : null;
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $paymentId = (int) $payment['id'];
    $paymentCode = (string) $payment['payment_code'];

    $stmt = $conn->prepare(
        'INSERT INTO invoice_payment_journal_link_events
            (payment_id, payment_code, journal_id, event_type, validation_status,
             reason, validation_hash, validation_snapshot,
             performed_by_user_id, performed_by_email)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to record the payment-journal link audit event.', 500);
    }
    $stmt->bind_param(
        'isisssssis',
        $paymentId,
        $paymentCode,
        $journalId,
        $eventType,
        $validationStatus,
        $reason,
        $validationHash,
        $snapshotJson,
        $userId,
        $userEmail
    );
    $stmt->execute();
    $stmt->close();
}
