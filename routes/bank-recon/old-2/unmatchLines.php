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
// FILE B — unmatchLines.php
// POST /bank-recon/unmatch
// Body (FormData): recon_id, match_group
//
// Removes a match group, restoring both sides to Unmatched.
// ═══════════════════════════════════════════════════════════════════════

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin','Controller'])) brFail('Unauthorized', 401);

    $reconId    = (int)($_POST['recon_id']   ?? 0);
    $matchGroup = trim($_POST['match_group'] ?? '');
    if (!$reconId || !$matchGroup) brFail('recon_id and match_group are required.');

    // Validate the match_group belongs to this reconciliation
    $check = $conn->query("SELECT id FROM bank_recon_matches WHERE recon_id=$reconId AND match_group='" . $conn->real_escape_string($matchGroup) . "' LIMIT 1")->fetch_assoc();
    if (!$check) brFail('Match group not found in this reconciliation.', 404);

    $mg = $conn->real_escape_string($matchGroup);
    $conn->begin_transaction();

    $conn->query("UPDATE bank_recon_bank_lines   SET match_status='Unmatched', match_group=NULL, auto_matched=0 WHERE recon_id=$reconId AND match_group='$mg'");
    $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Unmatched', match_group=NULL, auto_matched=0 WHERE recon_id=$reconId AND match_group='$mg'");
    $conn->query("DELETE FROM bank_recon_matches WHERE recon_id=$reconId AND match_group='$mg'");

    $summary = recomputeSummary($conn, $reconId);
    $conn->commit();

    echo json_encode([
        'status'  => 'Success',
        'message' => 'Match removed. Both lines are now unmatched.',
        'data'    => ['summary' => $summary],
    ]);

} catch (Exception $e) {
    try { $conn->rollback(); } catch (Throwable $t) {}
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
