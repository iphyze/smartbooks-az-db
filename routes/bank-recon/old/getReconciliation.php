<?php
/**
 * GET /bank-recon/get?id=X
 *
 * Returns a full reconciliation object:
 *   reconciliation  — header row
 *   bank_lines      — all bank statement lines ordered by date
 *   ledger_lines    — all ledger lines ordered by date
 *   matches         — all match records
 *   summary         — line counts and current balance state
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function brFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) brFail('Unauthorized', 401);

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) brFail('id is required.');

    // ── Header ───────────────────────────────────────────────────────────────
    $recon = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$recon) brFail('Reconciliation not found.', 404);

    // ── Lines ────────────────────────────────────────────────────────────────
    $bankLines   = $conn->query("SELECT * FROM bank_recon_bank_lines   WHERE recon_id=$id ORDER BY txn_date ASC, id ASC")->fetch_all(MYSQLI_ASSOC);
    $ledgerLines = $conn->query("SELECT * FROM bank_recon_ledger_lines WHERE recon_id=$id ORDER BY txn_date ASC, id ASC")->fetch_all(MYSQLI_ASSOC);
    $matches     = $conn->query("SELECT * FROM bank_recon_matches      WHERE recon_id=$id ORDER BY matched_at ASC")->fetch_all(MYSQLI_ASSOC);

    // ── Summary counts ───────────────────────────────────────────────────────
    $bTotal     = count($bankLines);
    $lTotal     = count($ledgerLines);
    $bMatched   = count(array_filter($bankLines,   fn($l) => $l['match_status'] === 'Matched'));
    $lMatched   = count(array_filter($ledgerLines, fn($l) => $l['match_status'] === 'Matched'));
    $bUnmatched = count(array_filter($bankLines,   fn($l) => $l['match_status'] === 'Unmatched'));
    $lUnmatched = count(array_filter($ledgerLines, fn($l) => $l['match_status'] === 'Unmatched'));
    $bBankOnly  = count(array_filter($bankLines,   fn($l) => $l['match_status'] === 'Bank-Only'));
    $matchRate  = $bTotal > 0 ? round($bMatched / $bTotal * 100) : 0;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'data'   => [
            'reconciliation' => $recon,
            'bank_lines'     => $bankLines,
            'ledger_lines'   => $ledgerLines,
            'matches'        => $matches,
            'summary'        => compact('bTotal','lTotal','bMatched','lMatched','bUnmatched','lUnmatched','bBankOnly','matchRate'),
        ],
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
