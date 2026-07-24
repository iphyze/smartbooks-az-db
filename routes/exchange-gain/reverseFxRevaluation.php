<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/fx_helpers.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $integrity = (string) ($userData['integrity'] ?? '');
    $userEmail = trim((string) ($userData['email'] ?? $userData['username'] ?? 'system'));
    if (!in_array($integrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can reverse FX revaluations.', 403);
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $originalJournalId = (int) ($body['journal_id'] ?? 0);
    if ($originalJournalId <= 0) {
        throw new RuntimeException('Select a valid FX revaluation journal.', 422);
    }

    $reversalDate = smartbooksFxValidateDate((string) ($body['reversal_date'] ?? ''), 'reversal date');
    $reversalDescription = trim((string) ($body['reversal_description'] ?? ''));
    if ($reversalDescription === '') {
        throw new RuntimeException('Reversal description is required.', 422);
    }
    if (mb_strlen($reversalDescription) > 1000) {
        throw new RuntimeException('Reversal description cannot exceed 1,000 characters.', 422);
    }

    smartbooksFxRequireSchema($conn);
    $conn->begin_transaction();
    smartbooksFxAssertPostingDateOpen($conn, $reversalDate);

    $batchStmt = $conn->prepare(
        'SELECT id, batch_code, currency, date_from, date_to, closing_rate_date, closing_rate,
                journal_date, journal_id, status, journal_description, cost_center,
                reversal_journal_id, reversal_date
         FROM fx_revaluation_batches
         WHERE journal_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$batchStmt) {
        throw new RuntimeException('Unable to load the FX revaluation batch.', 500);
    }
    $batchStmt->bind_param('i', $originalJournalId);
    $batchStmt->execute();
    $batch = $batchStmt->get_result()->fetch_assoc();
    $batchStmt->close();

    if (!$batch) {
        throw new RuntimeException(
            'This journal is not a tracked FX revaluation. Legacy FX journals must be reviewed and reversed before this migration is adopted.',
            409
        );
    }
    if (!empty($batch['reversal_journal_id'])) {
        throw new RuntimeException(
            "FX revaluation journal {$originalJournalId} has already been reversed by journal {$batch['reversal_journal_id']}.",
            409
        );
    }

    $originalJournalDate = smartbooksFxValidateDate((string) $batch['journal_date'], 'original journal date');
    if ($reversalDate < $originalJournalDate) {
        throw new RuntimeException('The reversal date cannot be earlier than the original revaluation date.', 422);
    }

    /*
     * Revaluations are incremental: a later batch is calculated from the carrying
     * value created by earlier active batches. Therefore an older batch cannot be
     * reversed while a later active batch still depends on it. Reverse the newest
     * batch first so each mirror journal unwinds in the correct order.
     */
    $laterBatchStmt = $conn->prepare(
        'SELECT b2.journal_id, b2.date_to, l2.ledger_name
         FROM fx_revaluation_batches b2
         INNER JOIN fx_revaluation_lines l2 ON l2.batch_id = b2.id
         INNER JOIN fx_revaluation_lines l1
                 ON l1.batch_id = ? AND l1.ledger_number = l2.ledger_number
         WHERE b2.id <> ?
           AND b2.currency = ?
           AND (
                b2.journal_date > ?
                OR (b2.journal_date = ? AND b2.journal_id > ?)
           )
           AND b2.journal_date <= ?
           AND (
                b2.reversal_journal_id IS NULL
                OR b2.reversal_date IS NULL
                OR b2.reversal_date > ?
           )
         ORDER BY b2.journal_date ASC, b2.journal_id ASC
         LIMIT 1'
    );
    if (!$laterBatchStmt) {
        throw new RuntimeException('Unable to validate later FX revaluations.', 500);
    }
    $batchId = (int) $batch['id'];
    $currency = (string) $batch['currency'];
    $laterBatchStmt->bind_param(
        'iisssiss',
        $batchId,
        $batchId,
        $currency,
        $originalJournalDate,
        $originalJournalDate,
        $originalJournalId,
        $reversalDate,
        $reversalDate
    );
    $laterBatchStmt->execute();
    $laterBatch = $laterBatchStmt->get_result()->fetch_assoc();
    $laterBatchStmt->close();

    if ($laterBatch) {
        throw new RuntimeException(
            "Reverse later FX journal {$laterBatch['journal_id']} dated {$laterBatch['date_to']} before reversing journal {$originalJournalId}; it contains a subsequent adjustment for {$laterBatch['ledger_name']}.",
            409
        );
    }

    /*
     * Once a foreign-currency movement has used the revalued carrying amount,
     * reversing the entire old batch would also reverse the portion already
     * realised through settlement. That would leave a residual in the ledger.
     * Block that unsafe operation and require a fresh closing revaluation instead.
     */
    $movementStmt = $conn->prepare(
        'SELECT
            m.ledger_number,
            MAX(m.ledger_name) AS ledger_name,
            MIN(m.journal_date) AS first_movement_date,
            COALESCE(SUM(CAST(m.debit AS DECIMAL(20,6)) - CAST(m.credit AS DECIMAL(20,6))), 0) AS net_fcy_movement,
            COALESCE(SUM(CAST(m.debit_ngn AS DECIMAL(20,6)) - CAST(m.credit_ngn AS DECIMAL(20,6))), 0) AS net_ngn_movement
         FROM main_journal_table m
         INNER JOIN fx_revaluation_lines l
                 ON l.batch_id = ? AND l.ledger_number = m.ledger_number
         WHERE m.journal_id <> ?
           AND m.journal_currency = ?
           AND (
                m.journal_date > ?
                OR (m.journal_date = ? AND m.journal_id > ?)
           )
           AND m.journal_date <= ?
         GROUP BY m.ledger_number
         HAVING ABS(net_fcy_movement) > 0.00005
             OR ABS(net_ngn_movement) > 0.005
         ORDER BY first_movement_date ASC, m.ledger_number ASC
         LIMIT 1'
    );
    if (!$movementStmt) {
        throw new RuntimeException('Unable to validate later foreign-currency movements.', 500);
    }
    $movementStmt->bind_param(
        'iisssis',
        $batchId,
        $originalJournalId,
        $currency,
        $originalJournalDate,
        $originalJournalDate,
        $originalJournalId,
        $reversalDate
    );
    $movementStmt->execute();
    $laterMovement = $movementStmt->get_result()->fetch_assoc();
    $movementStmt->close();

    if ($laterMovement) {
        throw new RuntimeException(
            "This revaluation cannot be fully reversed because {$laterMovement['ledger_name']} has a net later {$currency} movement from {$laterMovement['first_movement_date']}. Post a new closing revaluation to adjust the remaining balance instead.",
            409
        );
    }

    $headerStmt = $conn->prepare(
        'SELECT journal_id, journal_date, journal_currency, rate_date, rate,
                debit, credit, debit_ngn, credit_ngn, debit_others, credit_others,
                cost_center
         FROM journal_table
         WHERE journal_id = ?
         LIMIT 1'
    );
    if (!$headerStmt) {
        throw new RuntimeException('Unable to load the original FX journal header.', 500);
    }
    $headerStmt->bind_param('i', $originalJournalId);
    $headerStmt->execute();
    $originalHeader = $headerStmt->get_result()->fetch_assoc();
    $headerStmt->close();
    if (!$originalHeader) {
        throw new RuntimeException("Journal {$originalJournalId} was not found.", 404);
    }

    $linesStmt = $conn->prepare(
        'SELECT id, journal_currency, debit, credit, rate_date, rate,
                debit_ngn, credit_ngn, ngn_rate, usd_rate, eur_rate, gbp_rate,
                cost_center, ledger_name, ledger_number, ledger_class,
                ledger_class_code, ledger_sub_class, ledger_type
         FROM main_journal_table
         WHERE journal_id = ?
         ORDER BY id ASC'
    );
    if (!$linesStmt) {
        throw new RuntimeException('Unable to load the original FX journal lines.', 500);
    }
    $linesStmt->bind_param('i', $originalJournalId);
    $linesStmt->execute();
    $originalLines = $linesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $linesStmt->close();
    if (!$originalLines) {
        throw new RuntimeException("No journal lines were found for journal {$originalJournalId}.", 404);
    }

    $totalDebitNgn = 0.0;
    $totalCreditNgn = 0.0;
    foreach ($originalLines as $line) {
        $totalDebitNgn += (float) $line['credit_ngn'];
        $totalCreditNgn += (float) $line['debit_ngn'];
    }
    $totalDebitNgn = round($totalDebitNgn, 2);
    $totalCreditNgn = round($totalCreditNgn, 2);
    if (abs($totalDebitNgn - $totalCreditNgn) > 0.009) {
        throw new RuntimeException('The original FX journal is not balanced and cannot be reversed automatically.', 409);
    }

    $reversalJournalId = smartbooksFxNextJournalId($conn);
    $headerCurrency = trim((string) ($originalHeader['journal_currency'] ?? 'NGN')) ?: 'NGN';
    $headerRateDate = trim((string) ($originalHeader['rate_date'] ?? $batch['closing_rate_date']));
    $headerRate = (float) ($originalHeader['rate'] ?? 1);
    $costCenter = trim((string) ($originalHeader['cost_center'] ?? $batch['cost_center'] ?? ''));
    $debitOthers = (float) ($originalHeader['credit_others'] ?? 0);
    $creditOthers = (float) ($originalHeader['debit_others'] ?? 0);

    smartbooksFxInsertJournalHeader(
        $conn,
        $reversalJournalId,
        'FX Revaluation Reversal',
        $reversalDate,
        $headerCurrency,
        $reversalDescription,
        $totalDebitNgn,
        $totalCreditNgn,
        $headerRateDate,
        $headerRate > 0 ? $headerRate : 1.0,
        $debitOthers,
        $creditOthers,
        $costCenter,
        $userEmail
    );

    foreach ($originalLines as $line) {
        $ledger = [
            'ledger_name' => (string) $line['ledger_name'],
            'ledger_number' => (int) $line['ledger_number'],
            'ledger_class' => (string) $line['ledger_class'],
            'ledger_class_code' => (int) $line['ledger_class_code'],
            'ledger_sub_class' => (string) $line['ledger_sub_class'],
            'ledger_type' => (string) $line['ledger_type'],
        ];
        $lineRates = [
            'ngn_rate' => (float) $line['ngn_rate'],
            'usd_rate' => (float) $line['usd_rate'],
            'eur_rate' => (float) $line['eur_rate'],
            'gbp_rate' => (float) $line['gbp_rate'],
        ];

        smartbooksFxInsertJournalLine(
            $conn,
            $reversalJournalId,
            'FX Revaluation Reversal',
            $reversalDate,
            (string) $line['journal_currency'],
            $reversalDescription,
            (float) $line['credit'],
            (float) $line['debit'],
            (string) $line['rate_date'],
            (float) $line['rate'],
            (float) $line['credit_ngn'],
            (float) $line['debit_ngn'],
            $lineRates,
            (string) $line['cost_center'],
            $ledger,
            $userEmail
        );
    }

    $updateStmt = $conn->prepare(
        "UPDATE fx_revaluation_batches
         SET status = 'Reversed',
             reversal_journal_id = ?,
             reversal_date = ?,
             reversal_description = ?,
             reversed_by = ?,
             reversed_at = NOW()
         WHERE id = ?
           AND reversal_journal_id IS NULL"
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to update the FX revaluation batch.', 500);
    }
    $updateStmt->bind_param(
        'isssi',
        $reversalJournalId,
        $reversalDate,
        $reversalDescription,
        $userEmail,
        $batchId
    );
    $updateStmt->execute();
    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new RuntimeException('The FX revaluation was changed by another request. Refresh and try again.', 409);
    }
    $updateStmt->close();

    $conn->commit();

    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX revaluation journal reversed successfully.',
        'original_journal_id' => $originalJournalId,
        'reversal_journal_id' => $reversalJournalId,
        'batch_id' => $batchId,
        'batch_code' => (string) $batch['batch_code'],
        'reversal_date' => $reversalDate,
        'posted' => count($originalLines),
        'summary' => [
            'total_debit_ngn' => $totalDebitNgn,
            'total_credit_ngn' => $totalCreditNgn,
        ],
    ], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log('FX Revaluation Reversal Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($error),
    ]);
}
