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
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin','Controller'])) brFail('Unauthorized', 401);
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

    if ($bl['direction'] !== $ll['direction'])
        brFail("Direction mismatch: bank line is {$bl['direction']}, ledger line is {$ll['direction']}. Both must be IN or both must be OUT.");

    if ($bl['match_status'] === 'Matched') brFail('Bank line is already matched. Unmatch it first.');
    if ($ll['match_status'] === 'Matched') brFail('Ledger line is already matched. Unmatch it first.');

    $conn->begin_transaction();

    $mg      = 'MM-' . date('Ymd-His') . '-' . $bankLineId . '-' . $ledgerLineId;
    $mgE     = $conn->real_escape_string($mg);
    $amtDiff = round(abs((float)$bl['amount'] - (float)$ll['amount']), 2);
    $dayDiff = (int)(abs(strtotime($bl['txn_date']) - strtotime($ll['txn_date'])) / 86400);
    $conf    = max(60, 100 - ($dayDiff * 5) - ($amtDiff > 0 ? 10 : 0));
    $byE     = $conn->real_escape_string($by);

    $conn->query("UPDATE bank_recon_bank_lines   SET match_status='Matched', match_group='$mgE', auto_matched=0 WHERE id=$bankLineId");
    $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Matched', match_group='$mgE', auto_matched=0 WHERE id=$ledgerLineId");
    $conn->query("INSERT INTO bank_recon_matches (recon_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, amount_difference, day_difference, matched_by)
                  VALUES ($reconId, '$mgE', $bankLineId, $ledgerLineId, 'Manual', $conf, $amtDiff, $dayDiff, '$byE')");

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
