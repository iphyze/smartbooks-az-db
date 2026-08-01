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

/**
 * Load the active payment connected to this journal. Reversal journals stay
 * immutable, while an original payment journal may be corrected only through
 * the preview/revalidation workflow handled below.
 */
function loadLinkedPaymentForJournalCorrection(mysqli $conn, int $journalId): array {
    $stmt = $conn->prepare(
        "SELECT id, journal_id, reversal_journal_id, payment_code, status
         FROM invoice_payments
         WHERE journal_id = ? OR reversal_journal_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new Exception('Unable to verify the invoice-payment journal link.', 500);
    }
    $stmt->bind_param('ii', $journalId, $journalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        return [];
    }
    if ((int) ($row['reversal_journal_id'] ?? 0) === $journalId) {
        throw new Exception(
            "Journal #{$journalId} is a payment reversal journal and cannot be edited. Reverse the correction through the controlled payment workflow.",
            409
        );
    }
    if (strcasecmp((string) ($row['status'] ?? ''), 'Active') !== 0) {
        throw new Exception(
            "Journal #{$journalId} belongs to a non-active invoice payment and cannot be edited.",
            409
        );
    }

    return invoicePaymentManualLinkLoadPayment($conn, (int) $row['id'], '', true);
}

function lockInvoiceNumbersForJournalCorrection(mysqli $conn, array $invoiceNumbers): void {
    $numbers = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string) $value),
        $invoiceNumbers
    ))));
    sort($numbers, SORT_STRING);

    $stmt = $conn->prepare(
        'SELECT invoice_number FROM invoice_table WHERE invoice_number = ? LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new Exception('Unable to lock the invoice for journal correction.', 500);
    }
    foreach ($numbers as $invoiceNumber) {
        $stmt->bind_param('s', $invoiceNumber);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            throw new Exception("Invoice #{$invoiceNumber} was not found.", 404);
        }
    }
    $stmt->close();
}

function journalCorrectionAuditSnapshot(array $journal): array {
    $header = $journal['header'] ?? [];
    $lines = [];
    foreach (($journal['lines'] ?? []) as $line) {
        $lines[] = [
            'id' => (int) ($line['id'] ?? 0),
            'ledger_number' => (int) ($line['ledger_number'] ?? 0),
            'journal_date' => (string) ($line['journal_date'] ?? ''),
            'journal_currency' => (string) ($line['journal_currency'] ?? ''),
            'transaction_type' => (string) ($line['transaction_type'] ?? ''),
            'debit' => (float) ($line['debit'] ?? 0),
            'credit' => (float) ($line['credit'] ?? 0),
            'rate' => (float) ($line['rate'] ?? 0),
            'rate_date' => (string) ($line['rate_date'] ?? ''),
            'debit_ngn' => (float) ($line['debit_ngn'] ?? 0),
            'credit_ngn' => (float) ($line['credit_ngn'] ?? 0),
        ];
    }
    return [
        'journal_id' => (int) ($header['journal_id'] ?? 0),
        'journal_date' => (string) ($header['journal_date'] ?? ''),
        'journal_type' => (string) ($header['journal_type'] ?? ''),
        'journal_currency' => (string) ($header['journal_currency'] ?? ''),
        'transaction_type' => (string) ($header['transaction_type'] ?? ''),
        'description' => (string) ($header['journal_description'] ?? ''),
        'cost_center' => (string) ($header['cost_center'] ?? ''),
        'lines' => $lines,
    ];
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

    $linkedPaymentCorrection = isset($data['linked_payment_correction']) && is_array($data['linked_payment_correction'])
        ? $data['linked_payment_correction']
        : [];
    $correctLinkedPayment = !empty($linkedPaymentCorrection['enabled']);
    $linkedCorrectionPaymentId = (int) ($linkedPaymentCorrection['payment_id'] ?? 0);
    $linkedCorrectionInvoiceNumber = trim((string) ($linkedPaymentCorrection['invoice_number'] ?? ''));
    $linkedCorrectionPreviewToken = trim((string) ($linkedPaymentCorrection['preview_token'] ?? ''));

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

        // 1. Accounting period lock checks: protect both the original and revised dates.
        // A linked original payment journal may be corrected only when the
        // payment is previewed and revalidated in this same transaction.
        smartbooksAssertJournalOpenForMutation($conn, $journal_id, 'edited', true);
        smartbooksAssertPostingDateOpen($conn, $journal_date, 'Journal header date');

        $linkedPayment = loadLinkedPaymentForJournalCorrection($conn, $journal_id);
        $originalLinkedJournalSnapshot = null;
        if ($linkedPayment) {
            if ($registerInvoicePayment) {
                throw new Exception(
                    "Journal #{$journal_id} is already linked to payment {$linkedPayment['payment_code']}. Correct the existing link instead of registering another payment.",
                    409
                );
            }
            if (!$correctLinkedPayment) {
                throw new Exception(
                    "Journal #{$journal_id} is linked to payment {$linkedPayment['payment_code']}. Preview and revalidate the linked payment before saving accounting changes.",
                    409
                );
            }
            if ($linkedCorrectionPaymentId !== (int) $linkedPayment['id']) {
                throw new Exception('The payment correction does not match this journal link.', 409);
            }
            if ($linkedCorrectionInvoiceNumber === '') {
                throw new Exception('Select the invoice for the corrected payment.', 422);
            }
            if ($linkedCorrectionPreviewToken === '') {
                throw new Exception('Preview and validate the corrected journal payment before saving.', 422);
            }
            $originalLinkedJournalSnapshot = journalCorrectionAuditSnapshot(
                invoicePaymentManualLinkLoadJournal($conn, $journal_id, true)
            );
        } elseif ($correctLinkedPayment) {
            throw new Exception('This journal is not linked to the selected invoice payment.', 409);
        }

        // 2. Verify the journal exists.
        $checkStmt = $conn->prepare("SELECT journal_id FROM journal_table WHERE journal_id = ?");
        $checkStmt->bind_param("i", $journal_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows === 0) {
            throw new Exception("Journal ID {$journal_id} not found.", 404);
        }
        $checkStmt->close();

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
        if ($linkedPayment) {
            $oldInvoiceNumber = trim((string) ($linkedPayment['invoice_number'] ?? ''));
            lockInvoiceNumbersForJournalCorrection(
                $conn,
                [$oldInvoiceNumber, $linkedCorrectionInvoiceNumber]
            );

            $invoice = fetchInvoiceBundle($conn, $linkedCorrectionInvoiceNumber);
            $storedJournal = invoicePaymentManualLinkLoadJournal($conn, $journal_id, true);
            $normalisedJournal = invoicePaymentRegistrationNormalisePersistedJournal($storedJournal);
            $invoicePaymentAnalysis = invoicePaymentRegistrationAnalyse(
                $conn,
                $invoice,
                $normalisedJournal,
                $journal_id,
                (int) $linkedPayment['id']
            );

            $invoicePayment = invoicePaymentRegistrationUpdateLinkedPayment(
                $conn,
                $linkedPayment,
                $invoicePaymentAnalysis,
                $userData,
                [
                    'payment_method' => trim((string) ($linkedPaymentCorrection['payment_method'] ?? '')),
                    'transaction_reference' => trim((string) ($linkedPaymentCorrection['transaction_reference'] ?? '')),
                    'notes' => trim((string) ($linkedPaymentCorrection['notes'] ?? '')),
                ],
                $linkedCorrectionPreviewToken,
                [
                    'journal_corrected' => true,
                    'corrected_at' => date(DATE_ATOM),
                    'corrected_by_user_id' => (int) $loggedInUserId,
                    'corrected_by_email' => $userEmail,
                    'previous_journal' => $originalLinkedJournalSnapshot,
                    'revised_journal' => journalCorrectionAuditSnapshot($storedJournal),
                    'previous_payment' => [
                        'invoice_number' => $oldInvoiceNumber,
                        'payment_date' => (string) ($linkedPayment['payment_date'] ?? ''),
                        'invoice_amount_settled' => (float) ($linkedPayment['invoice_amount_settled'] ?? 0),
                        'payment_currency' => (string) ($linkedPayment['payment_currency'] ?? ''),
                        'payment_amount_received' => (float) ($linkedPayment['payment_amount_received'] ?? 0),
                        'journal_validation_hash' => (string) ($linkedPayment['journal_validation_hash'] ?? ''),
                    ],
                ]
            );
        } elseif ($registerInvoicePayment) {
            lockInvoiceNumbersForJournalCorrection($conn, [$invoicePaymentNumber]);

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
            "message" => $linkedPayment ? "Journal and linked invoice payment corrected successfully!" : "Journal Voucher updated successfully!",
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