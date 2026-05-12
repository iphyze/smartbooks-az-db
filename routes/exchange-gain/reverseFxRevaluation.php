<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

/**
 * POST /exchange/reverse-revaluation
 *
 * Reverses a previously posted FX Revaluation journal by:
 *   1. Locating the original journal by journal_id in journal_table,
 *      verifying it is an FX Revaluation journal.
 *   2. Checking the accounting period is not locked.
 *   3. Generating a new journal_id for the reversal batch.
 *   4. Inserting mirror-image lines into main_journal_table:
 *      every debit_ngn becomes credit_ngn and vice versa.
 *      The ledger_name, ledger_number, and all classification
 *      columns are identical to the original — this is what
 *      makes the reversal net the original to zero.
 *   5. Inserting one header row into journal_table for the reversal.
 *   6. After a successful reversal the duplicate guard in
 *      postFxRevaluation.php will no longer find an existing
 *      revaluation for the period, so a fresh revaluation can
 *      be posted immediately.
 *
 * Request body (JSON):
 *   {
 *     "journal_id":          1310,           // the journal to reverse
 *     "reversal_date":       "2025-12-31",   // date for the reversal journal
 *     "reversal_description":"Reversal of FX Revaluation USD - Dec 2025"
 *   }
 */

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────
    $userData              = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUser          = $userData['username'] ?? $userData['email'] ?? 'system';

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can reverse FX journals", 401);
    }

    // ── Parse body ────────────────────────────────────────────────────────────
    $body = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON body.", 400);
    }

    $requiredFields = ['journal_id', 'reversal_date', 'reversal_description'];
    foreach ($requiredFields as $field) {
        if (!isset($body[$field]) || (is_string($body[$field]) && empty(trim($body[$field])))) {
            throw new Exception("Missing required field: '$field'.", 400);
        }
    }

    $originalJournalId  = (int) $body['journal_id'];
    $reversalDate       = trim($body['reversal_date']);
    $reversalDesc       = trim($body['reversal_description']);

    if ($originalJournalId <= 0) {
        throw new Exception("Invalid journal_id.", 400);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 1 — Verify the original journal exists and is an FX Revaluation
    // ════════════════════════════════════════════════════════════════════════

    $headerStmt = $conn->prepare("
        SELECT journal_id, journal_type, journal_date, journal_currency,
               rate_date, rate, debit, credit, debit_ngn, credit_ngn,
               debit_others, credit_others, cost_center
        FROM journal_table
        WHERE journal_id = ?
        LIMIT 1
    ");
    if (!$headerStmt) throw new Exception("DB Error (header lookup): " . $conn->error, 500);
    $headerStmt->bind_param("i", $originalJournalId);
    $headerStmt->execute();
    $originalHeader = $headerStmt->get_result()->fetch_assoc();
    $headerStmt->close();

    if (!$originalHeader) {
        throw new Exception("Journal ID $originalJournalId not found.", 404);
    }

    if ($originalHeader['journal_type'] !== 'Journal') {
        throw new Exception(
            "Journal $originalJournalId is of type '{$originalHeader['journal_type']}'. " .
            "Only FX Revaluation journals (type 'Journal') can be reversed via this endpoint.",
            400
        );
    }

    // ── Confirm it actually contains FX Revaluation lines ────────────────────
    $fxCheckStmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM main_journal_table
        WHERE journal_id   = ?
          AND journal_type = 'Journal'
          AND ledger_number IN (72000002, 69000004)
    ");
    if (!$fxCheckStmt) throw new Exception("DB Error (FX check): " . $conn->error, 500);
    $fxCheckStmt->bind_param("i", $originalJournalId);
    $fxCheckStmt->execute();
    $fxCheckRow = $fxCheckStmt->get_result()->fetch_assoc();
    $fxCheckStmt->close();

    if ((int)$fxCheckRow['cnt'] === 0) {
        throw new Exception(
            "Journal $originalJournalId does not appear to be an FX Revaluation journal " .
            "(no Exchange Gain/Loss lines found).",
            400
        );
    }

    // ── Check it has not already been reversed ────────────────────────────────
    $reversedCheckStmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM main_journal_table
        WHERE journal_description LIKE ?
          AND journal_type = 'Journal'
          AND ledger_number IN (72000002, 69000004)
    ");
    if (!$reversedCheckStmt) throw new Exception("DB Error (reversal check): " . $conn->error, 500);
    $reversalPattern = '%Reversal%JV-' . $originalJournalId . '%';
    $reversedCheckStmt->bind_param("s", $reversalPattern);
    $reversedCheckStmt->execute();
    $reversedCheckRow = $reversedCheckStmt->get_result()->fetch_assoc();
    $reversedCheckStmt->close();

    if ((int)$reversedCheckRow['cnt'] > 0) {
        throw new Exception(
            "Journal $originalJournalId has already been reversed. " .
            "Post a fresh revaluation instead.",
            409
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Check if the reversal date falls in a locked period
    // ════════════════════════════════════════════════════════════════════════

    $lockStmt = $conn->prepare("
        SELECT id, lock_reason
        FROM accounting_periods
        WHERE is_locked = '1'
          AND start_date <= ?
          AND end_date   >= ?
        LIMIT 1
    ");
    if (!$lockStmt) throw new Exception("DB Error (lock check): " . $conn->error, 500);
    $lockStmt->bind_param("ss", $reversalDate, $reversalDate);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if ($lockRow) {
        $reason = $lockRow['lock_reason'] ?? 'Period is locked';
        throw new Exception("Cannot post reversal: accounting period is locked. Reason: $reason", 403);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — Fetch all original journal lines from main_journal_table
    // ════════════════════════════════════════════════════════════════════════

    $linesStmt = $conn->prepare("
        SELECT
            ledger_name, ledger_number, ledger_class, ledger_class_code,
            ledger_sub_class, ledger_type, journal_currency,
            debit, credit, debit_ngn, credit_ngn,
            ngn_rate, usd_rate, eur_rate, gbp_rate,
            rate_date, rate, cost_center
        FROM main_journal_table
        WHERE journal_id   = ?
          AND journal_type = 'Journal'
        ORDER BY id ASC
    ");
    if (!$linesStmt) throw new Exception("DB Error (lines fetch): " . $conn->error, 500);
    $linesStmt->bind_param("i", $originalJournalId);
    $linesStmt->execute();
    $originalLines = $linesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $linesStmt->close();

    if (empty($originalLines)) {
        throw new Exception("No journal lines found for journal ID $originalJournalId.", 404);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 4 — Generate next journal_id inside a transaction
    // ════════════════════════════════════════════════════════════════════════

    $conn->begin_transaction();

    $maxIdStmt = $conn->prepare("
        SELECT COALESCE(MAX(journal_id), 100) AS max_id
        FROM journal_table
        FOR UPDATE
    ");
    if (!$maxIdStmt) {
        $conn->rollback();
        throw new Exception("DB Error (journal_id lock): " . $conn->error, 500);
    }
    $maxIdStmt->execute();
    $maxIdRow      = $maxIdStmt->get_result()->fetch_assoc();
    $maxIdStmt->close();
    $reversalJournalId = (int)$maxIdRow['max_id'] + 1;

    // ════════════════════════════════════════════════════════════════════════
    // STEP 5 — Build reversal lines
    //
    // Reversal logic: swap debit_ngn ↔ credit_ngn (and debit ↔ credit).
    // Every other column (ledger, classification, rates) stays identical.
    // This perfectly nets the original entries to zero across all ledgers.
    // ════════════════════════════════════════════════════════════════════════

    $reversalLines  = [];
    $totalDebitNGN  = 0.0;
    $totalCreditNGN = 0.0;

    foreach ($originalLines as $line) {
        // Swap debit and credit — both FCY (debit/credit) and NGN columns
        $revDebit    = $line['credit'];      // swap
        $revCredit   = $line['debit'];       // swap
        $revDebitNGN = $line['credit_ngn'];  // swap
        $revCreditNGN= $line['debit_ngn'];   // swap

        $totalDebitNGN  += (float)$revDebitNGN;
        $totalCreditNGN += (float)$revCreditNGN;

        $reversalLines[] = [
            (int)    $reversalJournalId,
            'Journal',
            'Journal',
            $reversalDate,
            $line['journal_currency'],
            $reversalDesc,
            $revDebit,           // debit   (FCY)
            $revCredit,          // credit  (FCY)
            $line['rate_date'],
            $line['rate'],
            $revDebitNGN,        // debit_ngn
            $revCreditNGN,       // credit_ngn
            $line['ngn_rate'],
            $line['usd_rate'],
            $line['eur_rate'],
            $line['gbp_rate'],
            $line['cost_center'],
            $line['ledger_name'],
            (int) $line['ledger_number'],
            $line['ledger_class'],
            (int) $line['ledger_class_code'],
            $line['ledger_sub_class'],
            $line['ledger_type'],
            $loggedInUser,
            $loggedInUser,
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 6 — Insert header row into journal_table
    // ════════════════════════════════════════════════════════════════════════

    $totalDebitStr  = (string) round($totalDebitNGN,  2);
    $totalCreditStr = (string) round($totalCreditNGN, 2);

    $jInsertStmt = $conn->prepare("
        INSERT INTO journal_table (
            journal_id, journal_type, transaction_type,
            journal_date, journal_currency, journal_description,
            debit, credit, rate_date, rate,
            debit_ngn, credit_ngn,
            debit_others, credit_others,
            cost_center,
            created_by, updated_by
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?,
            ?, ?
        )
    ");
    if (!$jInsertStmt) {
        $conn->rollback();
        throw new Exception("DB Error (journal_table prepare): " . $conn->error, 500);
    }

    $jType         = 'Journal';
    $jTxType       = 'Journal';
    $jCurrency     = $originalHeader['journal_currency'];
    $jRate         = $originalHeader['rate'];
    $jRateDate     = $originalHeader['rate_date'];
    $jDebitOthers  = '0';
    $jCreditOthers = '0';
    $jCostCenter   = '';

    $jInsertStmt->bind_param(
        "issssssssssssssss",
        $reversalJournalId,  // i
        $jType,              // s
        $jTxType,            // s
        $reversalDate,       // s
        $jCurrency,          // s
        $reversalDesc,       // s
        $totalDebitStr,      // s  debit
        $totalCreditStr,     // s  credit
        $jRateDate,          // s  rate_date
        $jRate,              // s  rate
        $totalDebitStr,      // s  debit_ngn
        $totalCreditStr,     // s  credit_ngn
        $jDebitOthers,       // s  debit_others
        $jCreditOthers,      // s  credit_others
        $jCostCenter,        // s  cost_center
        $loggedInUser,       // s  created_by
        $loggedInUser        // s  updated_by
    );

    if (!$jInsertStmt->execute()) {
        $conn->rollback();
        throw new Exception("DB Error (journal_table insert): " . $jInsertStmt->error, 500);
    }
    $jInsertStmt->close();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 7 — Insert reversal lines into main_journal_table
    //
    // Type string: "isssssssssssssssssisissss" (25 params)
    // Identical to postFxRevaluation.php — all monetary cols are VARCHAR.
    // ════════════════════════════════════════════════════════════════════════

    $mInsertSQL = "
        INSERT INTO main_journal_table (
            journal_id, journal_type, transaction_type,
            journal_date, journal_currency, journal_description,
            debit, credit, rate_date, rate,
            debit_ngn, credit_ngn,
            ngn_rate, usd_rate, eur_rate, gbp_rate,
            cost_center,
            ledger_name, ledger_number, ledger_class, ledger_class_code,
            ledger_sub_class, ledger_type,
            created_by, updated_by
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?
        )
    ";

    $mInsertStmt = $conn->prepare($mInsertSQL);
    if (!$mInsertStmt) {
        $conn->rollback();
        throw new Exception("DB Error (main_journal prepare): " . $conn->error, 500);
    }

    $postedCount = 0;

    foreach ($reversalLines as $l) {
        $mInsertStmt->bind_param(
            "isssssssssssssssssisissss",
            $l[0],   // journal_id           i
            $l[1],   // journal_type         s
            $l[2],   // transaction_type     s
            $l[3],   // journal_date         s
            $l[4],   // journal_currency     s
            $l[5],   // journal_description  s
            $l[6],   // debit                s
            $l[7],   // credit               s
            $l[8],   // rate_date            s
            $l[9],   // rate                 s
            $l[10],  // debit_ngn            s
            $l[11],  // credit_ngn           s
            $l[12],  // ngn_rate             s
            $l[13],  // usd_rate             s
            $l[14],  // eur_rate             s
            $l[15],  // gbp_rate             s
            $l[16],  // cost_center          s
            $l[17],  // ledger_name          s
            $l[18],  // ledger_number        i
            $l[19],  // ledger_class         s
            $l[20],  // ledger_class_code    i
            $l[21],  // ledger_sub_class     s
            $l[22],  // ledger_type          s
            $l[23],  // created_by           s
            $l[24]   // updated_by           s
        );

        if (!$mInsertStmt->execute()) {
            $conn->rollback();
            throw new Exception("DB Error (main_journal insert): " . $mInsertStmt->error, 500);
        }
        $postedCount++;
    }

    $mInsertStmt->close();
    $conn->commit();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 8 — Respond
    // ════════════════════════════════════════════════════════════════════════

    http_response_code(201);
    echo json_encode([
        "status"               => "Success",
        "message"              => "FX Revaluation journal $originalJournalId reversed successfully. You may now post a fresh revaluation for the same period.",
        "original_journal_id"  => $originalJournalId,
        "reversal_journal_id"  => $reversalJournalId,
        "posted"               => $postedCount,
        "reversal_date"        => $reversalDate,
        "posted_by"            => $loggedInUser,
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    error_log("FX Reversal Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}