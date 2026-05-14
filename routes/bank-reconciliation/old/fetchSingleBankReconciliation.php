<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) throw new Exception('Unauthorized', 401);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('id is required', 400);
    $r = $conn->prepare('SELECT * FROM bank_reconciliations WHERE id=? LIMIT 1');
    $r->bind_param('i', $id);
    $r->execute();
    $recon = $r->get_result()->fetch_assoc();
    $r->close();
    if (!$recon) throw new Exception('Reconciliation not found', 404);
    $banks = $conn->query("SELECT * FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id ORDER BY match_status, transaction_date, id")->fetch_all(MYSQLI_ASSOC);
    $ledgers = $conn->query("SELECT * FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id ORDER BY match_status, transaction_date, id")->fetch_all(MYSQLI_ASSOC);
    $matches = $conn->query("SELECT * FROM bank_reconciliation_matches WHERE reconciliation_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $adjustments = $conn->query("SELECT * FROM bank_reconciliation_adjustments WHERE reconciliation_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $summary = ['bank_total' => count($banks), 'ledger_total' => count($ledgers), 'matched_bank' => 0, 'unmatched_bank' => 0, 'suggested_bank' => 0, 'unmatched_ledger' => 0];
    foreach ($banks as $b) {
        if ($b['match_status'] === 'Matched') $summary['matched_bank']++;
        if ($b['match_status'] === 'Unmatched') $summary['unmatched_bank']++;
        if ($b['match_status'] === 'Suggested') $summary['suggested_bank']++;
    }
    foreach ($ledgers as $l) {
        if ($l['match_status'] === 'Unmatched') $summary['unmatched_ledger']++;
    }
    echo json_encode(['status' => 'Success', 'data' => ['reconciliation' => $recon, 'bank_lines' => $banks, 'ledger_lines' => $ledgers, 'matches' => $matches, 'adjustments' => $adjustments, 'summary' => $summary]]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
