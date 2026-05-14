<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');

function brFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

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
    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();

    $ledgerInFlow  = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_ledger_lines WHERE recon_id=$id AND match_status='Unmatched' AND direction='IN'")->fetch_assoc()['v'];
    $ledgerOutFlow = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_ledger_lines WHERE recon_id=$id AND match_status='Unmatched' AND direction='OUT'")->fetch_assoc()['v'];
    $bankOnlyIn    = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_bank_lines   WHERE recon_id=$id AND match_status='Bank-Only'  AND direction='IN'")->fetch_assoc()['v'];
    $bankOnlyOut   = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_bank_lines   WHERE recon_id=$id AND match_status='Bank-Only'  AND direction='OUT'")->fetch_assoc()['v'];

    $adjBank   = (float)$r['bank_closing'] + $ledgerInFlow - $ledgerOutFlow + $bankOnlyIn - $bankOnlyOut;
    $adjLedger = (float)$r['ledger_closing'];
    $diff      = round($adjBank - $adjLedger, 2);
    $status    = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';

    $conn->query(sprintf(
        "UPDATE bank_recons SET adjusted_bank_balance=%.2f, adjusted_ledger_balance=%.2f,
         unreconciled_difference=%.2f, status='%s' WHERE id=%d",
        round($adjBank, 2), $adjLedger, $diff, $conn->real_escape_string($status), $id
    ));

    return [
        'adjusted_bank_balance'   => round($adjBank, 2),
        'adjusted_ledger_balance' => $adjLedger,
        'unreconciled_difference' => $diff,
        'status'                  => $status,
    ];
}


// ═══════════════════════════════════════════════════════════════════════
// FILE C — classifyBankLine.php
// POST /bank-recon/classify
// Body (FormData): recon_id, bank_line_id, type, dr_ledger, cr_ledger, note
//
// Marks an unmatched bank line as a Bank-Only item with classification.
// Updates the suggested journal entry and recomputes the summary balance.
// ═══════════════════════════════════════════════════════════════════════

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin','Controller'])) brFail('Unauthorized', 401);

    $reconId    = (int)($_POST['recon_id']    ?? 0);
    $bankLineId = (int)($_POST['bank_line_id'] ?? 0);
    $type       = trim($_POST['type']         ?? '');
    $drLedger   = trim($_POST['dr_ledger']    ?? '');
    $crLedger   = trim($_POST['cr_ledger']    ?? '');
    $note       = trim($_POST['note']         ?? '');

    if (!$reconId || !$bankLineId || !$type)
        brFail('recon_id, bank_line_id and type are required.');

    $validTypes = ['Bank Charge','Bank Interest','Stamp Duty','WHT Remittance',
                   'Direct Debit','Direct Credit','Reversal','Other'];
    if (!in_array($type, $validTypes)) brFail('Invalid type: ' . $type);

    // Ensure the line belongs to this reconciliation and is not already matched
    $bl = $conn->query("SELECT * FROM bank_recon_bank_lines WHERE id=$bankLineId AND recon_id=$reconId LIMIT 1")->fetch_assoc();
    if (!$bl) brFail('Bank line not found in this reconciliation.', 404);
    if ($bl['match_status'] === 'Matched') brFail('Cannot classify a matched line. Unmatch it first.');

    $tE  = $conn->real_escape_string($type);
    $drE = $conn->real_escape_string($drLedger);
    $crE = $conn->real_escape_string($crLedger);
    $nE  = $conn->real_escape_string($note);

    $conn->query("UPDATE bank_recon_bank_lines
                  SET match_status='Bank-Only',
                      bank_only_type='$tE',
                      suggested_dr_ledger='$drE',
                      suggested_cr_ledger='$crE',
                      journal_note='$nE'
                  WHERE id=$bankLineId AND recon_id=$reconId");

    $summary = recomputeSummary($conn, $reconId);

    echo json_encode([
        'status'  => 'Success',
        'message' => "Line classified as $type.",
        'data'    => ['summary' => $summary],
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
