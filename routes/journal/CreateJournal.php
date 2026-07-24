<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/notification_helpers.php';
require_once 'utils/accounting_period_helpers.php';
require_once 'utils/invoice_payment_registration_helpers.php';

header('Content-Type: application/json');

/**
 * Helper function to fetch ledger details
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

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // ── Authenticate ──────────────────────────────────────────────────────────
    $userData        = authenticateUser();
    $loggedInUserId  = $userData['id'];
    $userEmail       = $userData['email'];
    $userIntegrity   = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can create Journal Vouchers", 401);
    }

    // ── Decode JSON body ──────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ── Validate scalar / header fields ──────────────────────────────────────
    $requiredScalarFields = [
        'journal_date', 'journal_type', 'journal_currency',
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
            throw new Exception('Enter the invoice number to settle with this journal.', 422);
        }
        if ($invoicePaymentPreviewToken === '') {
            throw new Exception('Preview the invoice-payment registration before submitting the journal.', 422);
        }
    }

    // ── Grand-total balance check (matches old process_journal.php) ───────────
    // The old code checks: grand_total != 0  where grand_total = totalDebit - totalCredit
    // totalDebit / totalCredit are the NGN-converted sums sent from the frontend.
    // The frontend check is retained for fast feedback, but the backend recalculates
    // the authoritative NGN totals from the journal lines before saving the header.
    $grand_total     = isset($data['grand_total'])     ? (float) $data['grand_total']     : null;
    $total_debit_ngn = isset($data['total_debit_ngn']) ? (float) $data['total_debit_ngn'] : 0;
    $total_credit_ngn= isset($data['total_credit_ngn'])? (float) $data['total_credit_ngn']: 0;
    $total_debit_usd = isset($data['total_debit_usd']) ? (float) $data['total_debit_usd'] : 0;
    $total_credit_usd= isset($data['total_credit_usd'])? (float) $data['total_credit_usd']: 0;

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

        // 1. Accounting period lock check
        smartbooksAssertPostingDateOpen($conn, $journal_date, 'Journal header date');

        // 2. Generate the journal business ID atomically.
        $journal_id = smartbooksNextJournalId($conn);

        // 3. Insert line items into main_journal_table
        $computedDebitNgn = 0.0;
        $computedCreditNgn = 0.0;
        $stmtMainJrnl = $conn->prepare("
            INSERT INTO main_journal_table
                (journal_id, journal_type, journal_date, journal_currency, transaction_type,
                 journal_description, debit, credit, rate, rate_date, debit_ngn, credit_ngn,
                 ngn_rate, usd_rate, eur_rate, gbp_rate,
                 cost_center, ledger_name, ledger_number, ledger_class, ledger_class_code,
                 ledger_sub_class, ledger_type, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        for ($i = 0; $i < $count; $i++) {

            $ledger_name           = trim($data['ledger_name'][$i]);
            $ledger_number         = isset($data['ledger_number'][$i])     ? trim($data['ledger_number'][$i])     : '';
            $ledger_class          = isset($data['ledger_class'][$i])      ? trim($data['ledger_class'][$i])      : '';
            $ledger_class_code     = isset($data['ledger_class_code'][$i]) ? trim($data['ledger_class_code'][$i]) : '';
            $ledger_sub_class      = isset($data['ledger_sub_class'][$i])  ? trim($data['ledger_sub_class'][$i])  : '';
            $ledger_type           = isset($data['ledger_type'][$i])       ? trim($data['ledger_type'][$i])       : '';
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

            // Validate line-item fields (mirrors old per-row checks)
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

            // Verify ledger exists
            $ledgerData = getLedgerDetails($conn, $ledger_name);
            if (!$ledgerData) {
                throw new Exception("{$ledger_name} does not exist in the database!", 404);
            }

            // Split debit / credit
            $debit  = ($sides === 'Debit')  ? $amount : 0;
            $credit = ($sides === 'Credit') ? $amount : 0;

            // NGN equivalents stored per row (matches old $debit_rate / $credit_rate)
            $debit_rate  = $debit  * $currency_rate;
            $credit_rate = $credit * $currency_rate;
            $computedDebitNgn += $debit_rate;
            $computedCreditNgn += $credit_rate;

            $stmtMainJrnl->bind_param(
                "isssssdddsdddddssssssssss",
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
                throw new Exception("Error inserting journal line item: " . $stmtMainJrnl->error, 500);
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

        // 4. Insert journal header into journal_table
        $stmtJrnl = $conn->prepare("
            INSERT INTO journal_table
                (journal_id, journal_date, journal_type, journal_currency, transaction_type,
                 journal_description, debit, credit, rate_date, rate, debit_ngn, credit_ngn,
                 debit_others, credit_others, cost_center, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmtJrnl->bind_param(
            "isssssddsdddddsss",
            $journal_id,
            $journal_date,
            $journal_type,
            $journal_currency,
            $transaction_type,
            $main_journal_description,
            $total_debit_ngn,   // debit
            $total_credit_ngn,  // credit
            $rate_date,  // rate_date
            $jv_rate,  // currency_rate
            $total_debit_ngn,   // debit_ngn
            $total_credit_ngn,  // credit_ngn
            $total_debit_usd,   // debit_others
            $total_credit_usd,  // credit_others
            $cost_center,
            $userEmail,
            $userEmail
        );

        if (!$stmtJrnl->execute()) {
            throw new Exception("Error inserting journal header: " . $stmtJrnl->error, 500);
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

        // 5. Log the action
        $logStmt   = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "{$userEmail} created Journal Voucher #{$journal_id}";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        notifyAccountingUsers(
            $conn,
            'journal_created',
            'journal',
            "Journal #{$journal_id} was created",
            "{$userEmail} created a {$journal_type} journal dated {$journal_date}.",
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
            "message" => "Journal Voucher created successfully!",
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