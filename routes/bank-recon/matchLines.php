<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';
header('Content-Type: application/json');

function brFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

function displayReconSide(string $source, string $direction): string {
    $source = strtolower($source);
    $direction = strtoupper($direction);
    if ($source === 'ledger') return $direction === 'OUT' ? 'Ledger Credit' : 'Ledger Debit';
    return $direction === 'OUT' ? 'Bank Debit' : 'Bank Credit';
}

/**
 * Recompute and persist the standard bank reconciliation balance formula.
 * Returns the updated summary array.
 *
 *   Adjusted Bank  = bank_closing
 *                  + deposits-in-transit     (ledger IN  unmatched)
 *                  − outstanding payments    (ledger OUT unmatched)
 *                  + bank-only credits       (bank-only IN)
 *                  − bank-only debits        (bank-only OUT)
 *
 *   Adjusted Ledger = ledger_closing   (no adjustments needed on ledger side)
 *
 *   Difference = Adjusted Bank − Adjusted Ledger   (target: 0)
 */
function recomputeSummary(mysqli $conn, int $id): array {
    return brReconRecomputeSummary($conn, $id);
}


// ═══════════════════════════════════════════════════════════════════════
// FILE A — matchLines.php
// POST /bank-recon/match
// Body (FormData): recon_id, bank_line_id, ledger_line_id
//
// Links one bank line with one ledger line under a shared match_group.
// Validates: both lines must belong to the same reconciliation,
//            must have the same direction, must not already be matched.
// ═══════════════════════════════════════════════════════════════════════


try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);
    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';

    $raw = json_decode(file_get_contents('php://input'), true);
    $body = is_array($raw) ? $raw : $_POST;

    $reconId      = (int)($body['recon_id'] ?? 0);
    $bankLineId   = (int)($body['bank_line_id'] ?? 0);
    $ledgerLineId = (int)($body['ledger_line_id'] ?? 0);

    if (!$reconId || !$bankLineId || !$ledgerLineId)
        brFail('recon_id, bank_line_id and ledger_line_id are all required.');

    $bl = $conn->query("SELECT * FROM bank_recon_bank_lines   WHERE id=$bankLineId   AND recon_id=$reconId LIMIT 1")->fetch_assoc();
    $ll = $conn->query("SELECT * FROM bank_recon_ledger_lines WHERE id=$ledgerLineId AND recon_id=$reconId LIMIT 1")->fetch_assoc();

    if (!$bl) brFail('Bank line not found in this reconciliation.', 404);
    if (!$ll) brFail('Ledger line not found in this reconciliation.', 404);

    // Correct accounting pairing uses the same stored cash-flow direction:
    // Bank OUT (Debit)  pairs with Ledger OUT (Credit)
    // Bank IN  (Credit) pairs with Ledger IN  (Debit)
    if ($bl['direction'] !== $ll['direction']) {
        brFail(
            'Invalid reconciliation pairing. ' .
            displayReconSide('bank', $bl['direction']) . ' must be matched with ' .
            ($bl['direction'] === 'OUT' ? 'Ledger Credit' : 'Ledger Debit') .
            '; Bank Debit pairs with Ledger Credit and Bank Credit pairs with Ledger Debit.',
            422
        );
    }

    brReconEnsureMatchingSchema($conn);
    $bl = brReconLineMapWithOutstanding($bl);
    $ll = brReconLineMapWithOutstanding($ll);

    if (($bl['outstanding_amount'] ?? 0) <= 0.009) brFail('Bank line is already fully matched. Unmatch it first.');
    if (($ll['outstanding_amount'] ?? 0) <= 0.009) brFail('Ledger line is already fully matched. Unmatch it first.');

    $r = $conn->query("SELECT COALESCE(tolerance_amount,0) tolerance_amount FROM bank_recons WHERE id=$reconId LIMIT 1")->fetch_assoc() ?: ['tolerance_amount' => 0];

    $conn->begin_transaction();

    $mg      = 'MM-' . date('Ymd-His') . '-' . $bankLineId . '-' . $ledgerLineId;
    $mgE     = $conn->real_escape_string($mg);
    $amtDiff = round(abs((float)$bl['amount'] - (float)$ll['amount']), 2);
    $dayDiff = (int)(abs(strtotime($bl['txn_date']) - strtotime($ll['txn_date'])) / 86400);
    $conf    = max(60, 100 - ($dayDiff * 5) - ($amtDiff > 0 ? 10 : 0));
    $byE     = $conn->real_escape_string($by);

    brReconEnsureMatchingSchema($conn);
    $availableBank = brReconOutstandingAmount($bl);
    $availableLedger = brReconOutstandingAmount($ll);
    $allocAmount = round(min($availableBank, $availableLedger), 2);
    if ($allocAmount <= 0.009) brFail('One or both lines have no outstanding amount left to match.', 422);
    if (abs($availableBank - $availableLedger) > max((float)($r['tolerance_amount'] ?? 0), 0.01)) {
        brFail('These two lines do not fully balance. Use Match Selected with Partial Match enabled for partial allocation.', 422);
    }

    $stmt = $conn->prepare("INSERT INTO bank_recon_matches
        (recon_id, match_group, bank_line_id, ledger_line_id, bank_allocated_amount, ledger_allocated_amount, is_partial, match_note, match_type, confidence, amount_difference, day_difference, matched_by)
        VALUES (?, ?, ?, ?, ?, ?, 0, NULL, 'Manual', ?, ?, ?, ?)");
    if (!$stmt) brFail('Failed to prepare match insert: ' . $conn->error, 500);
    $zeroDiff = 0.0;
    $stmt->bind_param('isiiddidis', $reconId, $mg, $bankLineId, $ledgerLineId, $allocAmount, $allocAmount, $conf, $zeroDiff, $dayDiff, $by);
    $stmt->execute();
    $stmt->close();

    brReconApplyMatchedDelta($conn, 'bank_recon_bank_lines', $bankLineId, $allocAmount, (float)($r['tolerance_amount'] ?? 0), $mg);
    brReconApplyMatchedDelta($conn, 'bank_recon_ledger_lines', $ledgerLineId, $allocAmount, (float)($r['tolerance_amount'] ?? 0), $mg);

    $summary = recomputeSummary($conn, $reconId);
    $conn->commit();

    echo json_encode([
        'status'  => 'Success',
        'message' => 'Lines matched successfully.',
        'data'    => ['match_group' => $mg, 'summary' => $summary],
    ]);

} catch (Exception $e) {
    try { $conn->rollback(); } catch (Throwable $t) {}
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
