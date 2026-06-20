<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';
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
    return brReconRecomputeSummary($conn, $id);
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
    $user = requireAdmin();
    $reconId    = (int)($_POST['recon_id']   ?? 0);
    $matchGroup = trim($_POST['match_group'] ?? '');
    if (!$reconId || !$matchGroup) brFail('recon_id and match_group are required.');

    // Validate the match_group belongs to this reconciliation
    $check = $conn->query("SELECT id FROM bank_recon_matches WHERE recon_id=$reconId AND match_group='" . $conn->real_escape_string($matchGroup) . "' LIMIT 1")->fetch_assoc();
    if (!$check) brFail('Match group not found in this reconciliation.', 404);

    brReconEnsureMatchingSchema($conn);
    $mg = $conn->real_escape_string($matchGroup);
    $recon = $conn->query("SELECT COALESCE(tolerance_amount,0) tolerance_amount FROM bank_recons WHERE id=$reconId LIMIT 1")->fetch_assoc() ?: ['tolerance_amount' => 0];
    $tolerance = (float)($recon['tolerance_amount'] ?? 0);

    $rows = $conn->query("SELECT bank_line_id, ledger_line_id,
            COALESCE(NULLIF(bank_allocated_amount,0), amount_difference, 0) bank_amount,
            COALESCE(NULLIF(ledger_allocated_amount,0), amount_difference, 0) ledger_amount
        FROM bank_recon_matches
        WHERE recon_id=$reconId AND match_group='$mg'")->fetch_all(MYSQLI_ASSOC);

    $bankDeltas = [];
    $ledgerDeltas = [];
    foreach ($rows as $row) {
        $bankId = (int)($row['bank_line_id'] ?? 0);
        $ledgerId = (int)($row['ledger_line_id'] ?? 0);
        $bankAmount = (float)($row['bank_amount'] ?? 0);
        $ledgerAmount = (float)($row['ledger_amount'] ?? 0);
        if ($bankAmount <= 0 && $bankId) {
            $fallback = $conn->query("SELECT ABS(amount) amount FROM bank_recon_bank_lines WHERE id={$bankId} AND recon_id={$reconId} LIMIT 1")->fetch_assoc();
            $bankAmount = (float)($fallback['amount'] ?? 0);
        }
        if ($ledgerAmount <= 0 && $ledgerId) {
            $fallback = $conn->query("SELECT ABS(amount) amount FROM bank_recon_ledger_lines WHERE id={$ledgerId} AND recon_id={$reconId} LIMIT 1")->fetch_assoc();
            $ledgerAmount = (float)($fallback['amount'] ?? 0);
        }
        if ($bankAmount <= 0) $bankAmount = $ledgerAmount;
        if ($ledgerAmount <= 0) $ledgerAmount = $bankAmount;
        if ($bankId) $bankDeltas[$bankId] = ($bankDeltas[$bankId] ?? 0) + $bankAmount;
        if ($ledgerId) $ledgerDeltas[$ledgerId] = ($ledgerDeltas[$ledgerId] ?? 0) + $ledgerAmount;
    }

    $conn->begin_transaction();

    foreach ($bankDeltas as $lineId => $delta) {
        brReconApplyMatchedDelta($conn, 'bank_recon_bank_lines', (int)$lineId, -(float)$delta, $tolerance, null);
    }
    foreach ($ledgerDeltas as $lineId => $delta) {
        brReconApplyMatchedDelta($conn, 'bank_recon_ledger_lines', (int)$lineId, -(float)$delta, $tolerance, null);
    }

    $conn->query("DELETE FROM bank_recon_matches WHERE recon_id=$reconId AND match_group='$mg'");

    foreach (array_keys($bankDeltas) as $lineId) {
        brReconRefreshLineGroupAfterUnmatch($conn, 'bank_recon_bank_lines', $reconId, (int)$lineId, 'bank_line_id', $tolerance);
    }
    foreach (array_keys($ledgerDeltas) as $lineId) {
        brReconRefreshLineGroupAfterUnmatch($conn, 'bank_recon_ledger_lines', $reconId, (int)$lineId, 'ledger_line_id', $tolerance);
    }

    $summary = recomputeSummary($conn, $reconId);
    $conn->commit();

    echo json_encode([
        'status'  => 'Success',
        'message' => 'Match group removed. Remaining partial allocations were preserved where applicable.',
        'data'    => ['summary' => $summary],
    ]);

} catch (Exception $e) {
    try { $conn->rollback(); } catch (Throwable $t) {}
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
