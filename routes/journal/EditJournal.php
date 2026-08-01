<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/notification_helpers.php';
require_once 'utils/accounting_period_helpers.php';
require_once 'utils/invoice_payment_registration_helpers.php';

header('Content-Type: application/json');

/**
 * Helper: fetch ledger by name
 */
function getLedgerDetails($conn, $identifier, $column = 'ledger_name') {
    $sql = "SELECT * FROM ledger_table WHERE $column = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $data   = $result->fetch_assoc();
    $stmt->close();
    return $data;
}


/**
 * Normalise any accepted journal date value to YYYY-MM-DD before storage.
 */
function normalizeJournalDateValue($value, $fieldName = 'Journal date') {
    $raw = trim((string) $value);

    if ($raw === '') {
        throw new Exception("{$fieldName} is required.", 400);
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
        $dt = DateTime::createFromFormat('!Y-m-d', $raw);
        $errors = DateTime::getLastErrors();
        if ($dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dt->format('Y-m-d');
        }
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        throw new Exception("{$fieldName} must be a valid date.", 400);
    }

    return date('Y-m-d', $timestamp);
}

function journalProtectedFloatEquals($left, $right, float $tolerance = 0.000001): bool {
    return abs((float) $left - (float) $right) <= $tolerance;
}

function journalProtectedDateKey($value): string {
    $raw = trim((string) $value);
    return $raw === '' ? '' : substr($raw, 0, 10);
}

/**
 * A validated invoice-payment journal may be edited only for descriptive
 * metadata. Its accounting signature must stay exactly the same so the
 * payment validation, realised FX result, and reversal remain trustworthy.
 */
function assertLinkedPaymentJournalMetadataOnlyUpdate(
    mysqli $conn,
    int $journalId,
    array $data,
    string $journalDate,
    string $journalType,
    string $journalCurrency,
    string $transactionType,
    array $journalLineDateList
): array {
    $paymentStmt = $conn->prepare(
        "SELECT id, payment_code, invoice_number, status, journal_id, reversal_journal_id,
                journal_validation_status
         FROM invoice_payments
         WHERE journal_id = ? OR reversal_journal_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$paymentStmt) {
        throw new Exception('Unable to verify the invoice-payment journal link.', 500);
    }
    $paymentStmt->bind_param('ii', $journalId, $journalId);
    $paymentStmt->execute();
    $linkedPayment = $paymentStmt->get_result()->fetch_assoc() ?: null;
    $paymentStmt->close();

    if (!$linkedPayment) {
        return [];
    }
    if ((int) ($linkedPayment['reversal_journal_id'] ?? 0) === $journalId) {
        throw new Exception(
            "Journal #{$journalId} is a payment reversal journal and cannot be manually edited.",
            409
        );
    }
    if (strcasecmp((string) ($linkedPayment['status'] ?? ''), 'Active') !== 0) {
        throw new Exception(
            "Journal #{$journalId} belongs to a non-active invoice payment and cannot be manually edited.",
            409
        );
    }

    $headerStmt = $conn->prepare(
        'SELECT journal_date, journal_type, journal_currency, transaction_type
         FROM journal_table
         WHERE journal_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$headerStmt) {
        throw new Exception('Unable to load the protected journal header.', 500);
    }
    $headerStmt->bind_param('i', $journalId);
    $headerStmt->execute();
    $storedHeader = $headerStmt->get_result()->fetch_assoc() ?: null;
    $headerStmt->close();
    if (!$storedHeader) {
        throw new Exception("Journal ID {$journalId} not found.", 404);
    }

    $protectedChanges = [];
    if (journalProtectedDateKey($storedHeader['journal_date']) !== $journalDate) {
        $protectedChanges[] = 'journal date';
    }
    if (trim((string) $storedHeader['journal_type']) !== $journalType) {
        $protectedChanges[] = 'journal type';
    }
    if (strtoupper(trim((string) $storedHeader['journal_currency'])) !== strtoupper($journalCurrency)) {
        $protectedChanges[] = 'journal currency';
    }
    if (trim((string) $storedHeader['transaction_type']) !== $transactionType) {
        $protectedChanges[] = 'transaction type';
    }

    $lineStmt = $conn->prepare(
        'SELECT id, journal_date, journal_currency, transaction_type,
                debit, credit, rate, rate_date, debit_ngn, credit_ngn,
                ngn_rate, usd_rate, eur_rate, gbp_rate, ledger_number
         FROM main_journal_table
         WHERE journal_id = ?
         ORDER BY id ASC
         FOR UPDATE'
    );
    if (!$lineStmt) {
        throw new Exception('Unable to load the protected journal lines.', 500);
    }
    $lineStmt->bind_param('i', $journalId);
    $lineStmt->execute();
    $storedLines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lineStmt->close();

    $incomingIds = isset($data['db_id']) && is_array($data['db_id']) ? $data['db_id'] : [];
    if (count($storedLines) !== count($incomingIds)) {
        $protectedChanges[] = 'journal line count';
    } else {
        $storedById = [];
        foreach ($storedLines as $storedLine) {
            $storedById[(int) $storedLine['id']] = $storedLine;
        }
        $seenIds = [];
        foreach ($incomingIds as $index => $rawId) {
            $lineId = (int) $rawId;
            if ($lineId <= 0 || isset($seenIds[$lineId]) || !isset($storedById[$lineId])) {
                $protectedChanges[] = 'journal line identity';
                break;
            }
            $seenIds[$lineId] = true;
            $storedLine = $storedById[$lineId];

            $incomingSide = trim((string) ($data['sides'][$index] ?? ''));
            $storedSide = (float) $storedLine['debit'] > 0.0000005 ? 'Debit' : 'Credit';
            $storedAmount = $storedSide === 'Debit'
                ? (float) $storedLine['debit']
                : (float) $storedLine['credit'];
            $incomingAmount = (float) ($data['amount'][$index] ?? 0);
            $incomingRate = (float) ($data['currency_rate'][$index] ?? 0);
            $lineDate = isset($journalLineDateList[$index]) && trim((string) $journalLineDateList[$index]) !== ''
                ? normalizeJournalDateValue($journalLineDateList[$index], 'Journal date on line ' . ($index + 1))
                : $journalDate;

            $lineChanged =
                $storedSide !== $incomingSide
                || !journalProtectedFloatEquals($storedAmount, $incomingAmount)
                || (int) $storedLine['ledger_number'] !== (int) ($data['ledger_number'][$index] ?? 0)
                || strtoupper(trim((string) $storedLine['journal_currency'])) !== strtoupper(trim((string) ($data['jcurrency'][$index] ?? '')))
                || trim((string) $storedLine['transaction_type']) !== $transactionType
                || journalProtectedDateKey($storedLine['journal_date']) !== $lineDate
                || !journalProtectedFloatEquals($storedLine['rate'], $incomingRate, 0.00000001)
                || journalProtectedDateKey($storedLine['rate_date']) !== journalProtectedDateKey($data['rate_date'][$index] ?? '')
                || !journalProtectedFloatEquals($storedLine['ngn_rate'], $data['ngn_rate'][$index] ?? 0, 0.00000001)
                || !journalProtectedFloatEquals($storedLine['usd_rate'], $data['usd_rate'][$index] ?? 0, 0.00000001)
                || !journalProtectedFloatEquals($storedLine['eur_rate'], $data['eur_rate'][$index] ?? 0, 0.00000001)
                || !journalProtectedFloatEquals($storedLine['gbp_rate'], $data['gbp_rate'][$index] ?? 0, 0.00000001);

            if ($lineChanged) {
                $protectedChanges[] = 'accounting values on line ' . ($index + 1);
                break;
            }
        }
    }

    if ($protectedChanges) {
        throw new Exception(
            'This journal is linked to invoice payment ' . $linkedPayment['payment_code'] .
            '. You may update descriptions and cost centre only. Protected field changed: ' .
            implode(', ', array_values(array_unique($protectedChanges))) . '.',
            409
        );
    }

    return $linkedPayment;
}

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // ── Authenticate ──────────────────────────────────────────────────────────
    $userData       = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail      = $userData['email'];
    $userIntegrity  = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can update Journal Vouchers", 401);
    }

    // ── Decode JSON body ──────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ── Validate scalar / header fields ──────────────────────────────────────
    $requiredScalarFields = [
        'journal_id', 'journal_date', 'journal_type', 'journal_currency',
        'transaction_type', 'main_journal_description', 'cost_center',
    ];

    foreach ($requiredScalarFields as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // ── Validate array / line-item fields ────────────────────────────────────
    $arrayFields = [
        'ledger_name', 'amount', 'sides', 'jrate',
        'jcurrency', 'currency_rate', 'journal_description',
    ];

    foreach ($arrayFields as $field) {
        if (!isset($data[$field]) || !is_array($data[$field]) || empty($data[$field])) {
            throw new Exception(
                "Please ensure that you have at least added a line item with valid {$field}!", 400
            );
        }
    }

    $count = count($data['ledger_name']);
    foreach ($arrayFields as $field) {
        if (count($data[$field]) !== $count) {
            throw new Exception("Mismatch in line item data count for {$field}.", 400);
        }
    }

    $journalLineDateList = (isset($data['journal_line_date']) && is_array($data['journal_line_date']))
        ? $data['journal_line_date']
        : [];

    if (!empty($journalLineDateList) && count($journalLineDateList) !== $count) {
        throw new Exception("Mismatch in line item data count for journal_line_date.", 400);
    }

    // ── Clean header inputs ───────────────────────────────────────────────────
    $journal_id               = (int) $data['journal_id'];
    $journal_date             = normalizeJournalDateValue($data['journal_date'], 'Journal date');
    $journal_type             = trim($data['journal_type']);
    $journal_currency         = trim($data['journal_currency']);
    $transaction_type         = trim($data['transaction_type']);
    smartbooksAssertManualJournalTypeAllowed($transaction_type);
    $main_journal_description = trim($data['main_journal_description']);
    $cost_center              = trim($data['cost_center']);

    $invoicePaymentRegistration = isset($data['invoice_payment_registration']) && is_array($data['invoice_payment_registration'])
        ? $data['invoice_payment_registration']
        : [];
    $registerInvoicePayment = !empty($invoicePaymentRegistration['enabled']);
    $invoicePaymentNumber = trim((string) ($invoicePaymentRegistration['invoice_number'] ?? ''));
    $invoicePaymentPreviewToken = trim((string) ($invoicePaymentRegistration['preview_token'] ?? ''));
    if ($registerInvoicePayment) {
        if ($invoicePaymentNumber === '') {
            throw new Exception('Select the invoice to settle with this journal.', 422);
        }
        if ($invoicePaymentPreviewToken === '') {
            throw new Exception('Preview the invoice-payment registration before updating the journal.', 422);
        }
    }

    // ── Grand-total balance check (mirrors create-journal logic) ─────────────
    // Frontend sends preliminary NGN totals and grand_total (debit - credit).
    // The backend recalculates the authoritative totals from the submitted lines.
    $grand_total      = isset($data['grand_total'])      ? (float) $data['grand_total']      : null;
    $total_debit_ngn  = isset($data['total_debit_ngn'])  ? (float) $data['total_debit_ngn']  : 0;
    $total_credit_ngn = isset($data['total_credit_ngn']) ? (float) $data['total_credit_ngn'] : 0;
    $total_debit_usd  = isset($data['total_debit_usd'])  ? (float) $data['total_debit_usd']  : 0;
    $total_credit_usd = isset($data['total_credit_usd']) ? (float) $data['total_credit_usd'] : 0;

    if ($grand_total === null) {
        throw new Exception("grand_total is required.", 400);
    }

    // Floating-point conversion can produce values such as -0.0000001 (displayed as -0.00).
    // Treat only sub-cent rounding noise as zero; genuinely unbalanced journals are still rejected.
    if (abs($grand_total) < 0.005) {
        $grand_total = 0.0;
    }

    if ($grand_total != 0 || $grand_total < 0) {
        throw new Exception(
            "Grand total must be equal to zero. Please ensure that your total debit equals your total credit!", 400
        );
    }

    // Each journal line may have its own posting date and therefore its own effective rate.
    // Validate that every line still carries a resolved rate without forcing the IDs to match.
    foreach ($data['jrate'] as $index => $rate) {
        if (trim((string) $rate) === '') {
            throw new Exception("A valid exchange rate is required for journal line " . ($index + 1) . ".", 400);
        }
    }

    // ── Begin DB transaction ──────────────────────────────────────────────────
    $conn->begin_transaction();

    try {

        // 1. Accounting period lock checks: protect both the original and new dates.
        // Active payment journals may pass this gate only for the metadata-only
        // comparison below. Reversal and system journals remain fully blocked.
        smartbooksAssertJournalOpenForMutation($conn, $journal_id, 'edited', true);
        smartbooksAssertPostingDateOpen($conn, $journal_date, 'Journal header date');

        $linkedPayment = assertLinkedPaymentJournalMetadataOnlyUpdate(
            $conn,
            $journal_id,
            $data,
            $journal_date,
            $journal_type,
            $journal_currency,
            $transaction_type,
            $journalLineDateList
        );
        if ($linkedPayment && $registerInvoicePayment) {
            throw new Exception(
                "Journal #{$journal_id} is already linked to payment {$linkedPayment['payment_code']}. Use Manage Payment to update the payment link.",
                409
            );
        }

        // 2. Verify the journal exists
        $checkStmt = $conn->prepare("SELECT journal_id FROM journal_table WHERE journal_id = ?");
        $checkStmt->bind_param("i", $journal_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows === 0) {
            throw new Exception("Journal ID {$journal_id} not found.", 404);
        }
        $checkStmt->close();

        if ($linkedPayment) {
            // Preserve the validated accounting snapshot byte-for-byte. Only
            // descriptive metadata is updated for a linked payment journal.
            $headerMetadataStmt = $conn->prepare(
                'UPDATE journal_table
                 SET journal_description = ?, cost_center = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE journal_id = ?'
            );
            if (!$headerMetadataStmt) {
                throw new Exception('Unable to prepare the linked journal metadata update.', 500);
            }
            $headerMetadataStmt->bind_param(
                'sssi',
                $main_journal_description,
                $cost_center,
                $userEmail,
                $journal_id
            );
            $headerMetadataStmt->execute();
            $headerMetadataStmt->close();

            $lineMetadataStmt = $conn->prepare(
                'UPDATE main_journal_table
                 SET journal_description = ?, cost_center = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND journal_id = ?'
            );
            if (!$lineMetadataStmt) {
                throw new Exception('Unable to prepare the linked journal line metadata update.', 500);
            }
            foreach ($data['db_id'] as $index => $rawLineId) {
                $lineId = (int) $rawLineId;
                $lineDescription = trim((string) ($data['journal_description'][$index] ?? ''));
                $lineMetadataStmt->bind_param(
                    'sssii',
                    $lineDescription,
                    $cost_center,
                    $userEmail,
                    $lineId,
                    $journal_id
                );
                $lineMetadataStmt->execute();
            }
            $lineMetadataStmt->close();

            $totalsStmt = $conn->prepare(
                'SELECT debit_ngn, credit_ngn FROM journal_table WHERE journal_id = ? LIMIT 1'
            );
            if (!$totalsStmt) {
                throw new Exception('Unable to reload the linked journal totals.', 500);
            }
            $totalsStmt->bind_param('i', $journal_id);
            $totalsStmt->execute();
            $storedTotals = $totalsStmt->get_result()->fetch_assoc() ?: [];
            $totalsStmt->close();
            $total_debit_ngn = (float) ($storedTotals['debit_ngn'] ?? 0);
            $total_credit_ngn = (float) ($storedTotals['credit_ngn'] ?? 0);

            $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
            $logAction = "{$userEmail} updated descriptive details for payment-linked Journal Voucher #{$journal_id}";
            $logStmt->bind_param('iss', $loggedInUserId, $logAction, $userEmail);
            $logStmt->execute();
            $logStmt->close();

            notifyAccountingUsers(
                $conn,
                'journal_updated',
                'journal',
                "Journal #{$journal_id} descriptive details were updated",
                "{$userEmail} updated descriptions or cost centre without changing the linked payment journal values.",
                'info',
                'journal',
                $journal_id,
                "/journal/view/{$journal_id}",
                [
                    'journal_type' => $journal_type,
                    'journal_date' => $journal_date,
                    'payment_code' => (string) ($linkedPayment['payment_code'] ?? ''),
                    'metadata_only' => true,
                ],
                (int) $loggedInUserId
            );

            $conn->commit();

            http_response_code(200);
            echo json_encode([
                'status' => 'Success',
                'message' => 'Journal descriptive details updated successfully. Accounting values were preserved.',
                'data' => [
                    'journal_id' => $journal_id,
                    'total_debit' => $total_debit_ngn,
                    'total_credit' => $total_credit_ngn,
                    'invoice_payment' => $linkedPayment,
                    'metadata_only' => true,
                ],
            ]);
            return;
        }

        // 3. Collect the IDs of line items sent from the frontend.
        //    Items with a numeric id > 0 are existing rows; id = 0 / null = new rows.
        $incomingIds = [];
        if (isset($data['db_id']) && is_array($data['db_id'])) {
            foreach ($data['db_id'] as $dbId) {
                $parsed = (int) $dbId;
                if ($parsed > 0) {
                    $incomingIds[] = $parsed;
                }
            }
        }

        // 4. Delete line items that belong to this journal but were NOT sent back
        //    (user removed them on the frontend – already confirmed via modal).
        if (!empty($incomingIds)) {
            $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
            $idTypes      = str_repeat('i', count($incomingIds));

            $deleteStmt = $conn->prepare(
                "DELETE FROM main_journal_table
                 WHERE journal_id = ? AND id NOT IN ($placeholders)"
            );
            // Bind: first param is journal_id (i), then each incomingId (i each)
            $deleteStmt->bind_param('i' . $idTypes, $journal_id, ...$incomingIds);
            $deleteStmt->execute();
            $deleteStmt->close();
        } else {
            // No existing rows kept — remove all line items for this journal
            $deleteAllStmt = $conn->prepare(
                "DELETE FROM main_journal_table WHERE journal_id = ?"
            );
            $deleteAllStmt->bind_param("i", $journal_id);
            $deleteAllStmt->execute();
            $deleteAllStmt->close();
        }

        // 5. Upsert line items
        $computedDebitNgn = 0.0;
        $computedCreditNgn = 0.0;
        $stmtMainJrnl = $conn->prepare("
            INSERT INTO main_journal_table
                (id, journal_id, journal_type, journal_date, journal_currency, transaction_type,
                 journal_description, debit, credit, rate, rate_date, debit_ngn, credit_ngn,
                 ngn_rate, usd_rate, eur_rate, gbp_rate,
                 cost_center, ledger_name, ledger_number, ledger_class, ledger_class_code,
                 ledger_sub_class, ledger_type, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                journal_id          = VALUES(journal_id),
                journal_type        = VALUES(journal_type),
                journal_currency    = VALUES(journal_currency),
                transaction_type    = VALUES(transaction_type),
                journal_date        = VALUES(journal_date),
                journal_description = VALUES(journal_description),
                debit               = VALUES(debit),
                credit              = VALUES(credit),
                rate                = VALUES(rate),
                rate_date           = VALUES(rate_date),
                debit_ngn           = VALUES(debit_ngn),
                credit_ngn          = VALUES(credit_ngn),
                ngn_rate            = VALUES(ngn_rate),
                usd_rate            = VALUES(usd_rate),
                eur_rate            = VALUES(eur_rate),
                gbp_rate            = VALUES(gbp_rate),
                cost_center         = VALUES(cost_center),
                ledger_name         = VALUES(ledger_name),
                ledger_number       = VALUES(ledger_number),
                ledger_class        = VALUES(ledger_class),
                ledger_class_code   = VALUES(ledger_class_code),
                ledger_sub_class    = VALUES(ledger_sub_class),
                ledger_type         = VALUES(ledger_type),
                updated_by          = VALUES(updated_by),
                updated_at          = CURRENT_TIMESTAMP
        ");

        $rate_date = isset($data['rate_date'][0]) ? $data['rate_date'][0] : null;
        $jv_rate   = (float) $data['currency_rate'][0];

        for ($i = 0; $i < $count; $i++) {

            // db_id: 0 or null = new row (MySQL will auto-increment), existing = upsert
            $db_id = (isset($data['db_id'][$i]) && (int)$data['db_id'][$i] > 0)
                     ? (int) $data['db_id'][$i]
                     : null;

            $ledger_name              = trim($data['ledger_name'][$i]);
            $ledger_number            = isset($data['ledger_number'][$i])     ? trim($data['ledger_number'][$i])     : '';
            $ledger_class             = isset($data['ledger_class'][$i])      ? trim($data['ledger_class'][$i])      : '';
            $ledger_class_code        = isset($data['ledger_class_code'][$i]) ? trim($data['ledger_class_code'][$i]) : '';
            $ledger_sub_class         = isset($data['ledger_sub_class'][$i])  ? trim($data['ledger_sub_class'][$i])  : '';
            $ledger_type              = isset($data['ledger_type'][$i])       ? trim($data['ledger_type'][$i])       : '';
            $journal_description_line = trim($data['journal_description'][$i]);
            $line_journal_date = (isset($journalLineDateList[$i]) && trim((string) $journalLineDateList[$i]) !== '')
                ? normalizeJournalDateValue($journalLineDateList[$i], 'Journal date on line ' . ($i + 1))
                : $journal_date;

            smartbooksAssertPostingDateOpen($conn, $line_journal_date, 'Journal date on line ' . ($i + 1));

            $amount        = (float) $data['amount'][$i];
            $sides         = trim($data['sides'][$i]);
            $jcurrency     = trim($data['jcurrency'][$i]);
            $currency_rate = (float) $data['currency_rate'][$i];
            $jv_rate = (float) $data['currency_rate'][0];

            $rate_date = isset($data['rate_date'][0]) ? $data['rate_date'][0] : 0;
            $main_rate_date = isset($data['rate_date'][$i]) ? $data['rate_date'][$i] : 0;
            $ngn_rate = isset($data['ngn_rate'][$i]) ? (float) $data['ngn_rate'][$i] : 0;
            $usd_rate = isset($data['usd_rate'][$i]) ? (float) $data['usd_rate'][$i] : 0;
            $eur_rate = isset($data['eur_rate'][$i]) ? (float) $data['eur_rate'][$i] : 0;
            $gbp_rate = isset($data['gbp_rate'][$i]) ? (float) $data['gbp_rate'][$i] : 0;

            // ── Per-row validations (mirror create-journal) ───────────────────
            if (empty($sides) || !in_array($sides, ['Debit', 'Credit'])) {
                throw new Exception("Invalid side value on line " . ($i + 1) . ".", 400);
            }

            if (empty($jcurrency)) {
                throw new Exception(
                    "Please ensure that all currency fields are selected on line " . ($i + 1) . ".", 400
                );
            }

            if (empty($currency_rate) || $currency_rate == 0) {
                throw new Exception(
                    "Please ensure that all currency rate fields are selected on line " . ($i + 1) . ".", 400
                );
            }

            if (empty($amount) || $amount == 0) {
                throw new Exception(
                    "Please ensure that all amount fields are filled and non-zero on line " . ($i + 1) . ".", 400
                );
            }

            if (empty($journal_description_line)) {
                throw new Exception(
                    "Please ensure that all journal descriptions are filled on line " . ($i + 1) . ".", 400
                );
            }

            // Verify ledger exists. The stable ledger number is authoritative;
            // the name is presentation data and may contain encoded characters.
            $ledgerData = $ledger_number !== ''
                ? getLedgerDetails($conn, $ledger_number, 'ledger_number')
                : getLedgerDetails($conn, $ledger_name);
            if (!$ledgerData) {
                throw new Exception("The ledger selected on line " . ($i + 1) . " does not exist in the database!", 404);
            }
            $ledger_name = (string) $ledgerData['ledger_name'];
            $ledger_number = (string) $ledgerData['ledger_number'];
            $ledger_class = (string) $ledgerData['ledger_class'];
            $ledger_class_code = (string) $ledgerData['ledger_class_code'];
            $ledger_sub_class = (string) $ledgerData['ledger_sub_class'];
            $ledger_type = (string) $ledgerData['ledger_type'];

            // Split debit / credit
            $debit  = ($sides === 'Debit')  ? $amount : 0;
            $credit = ($sides === 'Credit') ? $amount : 0;

            // NGN equivalents per row
            $debit_rate  = $debit  * $currency_rate;
            $credit_rate = $credit * $currency_rate;
            $computedDebitNgn += $debit_rate;
            $computedCreditNgn += $credit_rate;

            $stmtMainJrnl->bind_param(
                "iisssssddddsdddddsssssssss",
                $db_id,
                $journal_id,
                $journal_type,
                $line_journal_date,
                $jcurrency,
                $transaction_type,
                $journal_description_line,
                $debit,
                $credit,
                $currency_rate,
                $main_rate_date,
                $debit_rate,
                $credit_rate,
                $ngn_rate,
                $usd_rate,
                $eur_rate,
                $gbp_rate,
                $cost_center,
                $ledger_name,
                $ledger_number,
                $ledger_class,
                $ledger_class_code,
                $ledger_sub_class,
                $ledger_type,
                $userEmail,
                $userEmail
            );

            if (!$stmtMainJrnl->execute()) {
                throw new Exception("Error upserting journal line item: " . $stmtMainJrnl->error, 500);
            }
        }

        $stmtMainJrnl->close();

        $computedDebitNgn = round($computedDebitNgn, 2);
        $computedCreditNgn = round($computedCreditNgn, 2);
        if (abs($computedDebitNgn - $computedCreditNgn) > 0.01) {
            throw new Exception(
                'The journal lines are not balanced in NGN. Please review the line amounts and exchange rates.',
                400
            );
        }
        $total_debit_ngn = $computedDebitNgn;
        $total_credit_ngn = $computedCreditNgn;

        // 6. Update journal header (mirrors create-journal payload structure)
        $stmtJrnl = $conn->prepare("
            UPDATE journal_table SET
                journal_date        = ?,
                journal_type        = ?,
                journal_currency    = ?,
                transaction_type    = ?,
                journal_description = ?,
                debit               = ?,
                credit              = ?,
                rate_date           = ?,
                rate                = ?,
                debit_ngn           = ?,
                credit_ngn          = ?,
                debit_others        = ?,
                credit_others       = ?,
                cost_center         = ?,
                updated_by          = ?,
                updated_at          = CURRENT_TIMESTAMP
            WHERE journal_id = ?
        ");

        $stmtJrnl->bind_param(
            "sssssddsdddddssi",
            $journal_date,
            $journal_type,
            $journal_currency,
            $transaction_type,
            $main_journal_description,
            $total_debit_ngn,
            $total_credit_ngn,
            $rate_date,
            $jv_rate,
            $total_debit_ngn,
            $total_credit_ngn,
            $total_debit_usd,
            $total_credit_usd,
            $cost_center,
            $userEmail,
            $journal_id
        );

        if (!$stmtJrnl->execute()) {
            throw new Exception("Error updating journal header: " . $stmtJrnl->error, 500);
        }
        $stmtJrnl->close();

        $invoicePayment = null;
        if ($registerInvoicePayment) {
            $invoiceLockStmt = $conn->prepare(
                'SELECT invoice_number FROM invoice_table WHERE invoice_number = ? LIMIT 1 FOR UPDATE'
            );
            if (!$invoiceLockStmt) {
                throw new Exception('Unable to lock the invoice for payment registration.', 500);
            }
            $invoiceLockStmt->bind_param('s', $invoicePaymentNumber);
            $invoiceLockStmt->execute();
            $lockedInvoice = $invoiceLockStmt->get_result()->fetch_assoc();
            $invoiceLockStmt->close();
            if (!$lockedInvoice) {
                throw new Exception("Invoice #{$invoicePaymentNumber} was not found.", 404);
            }

            $invoice = fetchInvoiceBundle($conn, $invoicePaymentNumber);
            $storedJournal = invoicePaymentManualLinkLoadJournal($conn, $journal_id, true);
            $normalisedJournal = invoicePaymentRegistrationNormalisePersistedJournal($storedJournal);
            $invoicePaymentAnalysis = invoicePaymentRegistrationAnalyse(
                $conn,
                $invoice,
                $normalisedJournal,
                $journal_id
            );
            $invoicePayment = invoicePaymentRegistrationPersist(
                $conn,
                $invoice,
                $invoicePaymentAnalysis,
                $journal_id,
                $userData,
                [
                    'payment_method' => trim((string) ($invoicePaymentRegistration['payment_method'] ?? '')),
                    'transaction_reference' => trim((string) ($invoicePaymentRegistration['transaction_reference'] ?? '')),
                    'notes' => trim((string) ($invoicePaymentRegistration['notes'] ?? '')),
                ],
                $invoicePaymentPreviewToken
            );
        }

        // 7. Log the action
        $logStmt   = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "{$userEmail} updated Journal Voucher #{$journal_id}";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        notifyAccountingUsers(
            $conn,
            'journal_updated',
            'journal',
            "Journal #{$journal_id} was updated",
            "{$userEmail} updated the journal dated {$journal_date}.",
            'info',
            'journal',
            $journal_id,
            "/journal/view/{$journal_id}",
            ['journal_type' => $journal_type, 'journal_date' => $journal_date],
            (int) $loggedInUserId
        );

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Journal Voucher updated successfully!",
            "data"    => [
                "journal_id"   => $journal_id,
                "total_debit"  => $total_debit_ngn,
                "total_credit" => $total_credit_ngn,
                "invoice_payment" => $invoicePayment,
            ],
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => publicErrorMessage($e),
    ]);
}