<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');

function fail($message, $code = 400) { throw new Exception($message, $code); }
function summaryFor($conn, $id) {
    $id = (int)$id;
    $bank = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(match_status='Matched'),0) matched, COALESCE(SUM(match_status='Adjustment'),0) classified, COALESCE(SUM(match_status NOT IN ('Matched','Adjustment')),0) unmatched, COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id")->fetch_assoc();
    $ledger = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(match_status='Matched'),0) matched, COALESCE(SUM(match_status='Adjustment'),0) classified, COALESCE(SUM(match_status NOT IN ('Matched','Adjustment')),0) unmatched, COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id")->fetch_assoc();
    $openDiff = round((float)($bank['open_amount'] ?? 0) - (float)($ledger['open_amount'] ?? 0), 2);
    return [
        'bank_total' => (int)($bank['total'] ?? 0),
        'ledger_total' => (int)($ledger['total'] ?? 0),
        'matched_bank' => (int)($bank['matched'] ?? 0),
        'matched_ledger' => (int)($ledger['matched'] ?? 0),
        'unmatched_bank' => (int)($bank['unmatched'] ?? 0),
        'unmatched_ledger' => (int)($ledger['unmatched'] ?? 0),
        'classified_bank' => (int)($bank['classified'] ?? 0),
        'classified_ledger' => (int)($ledger['classified'] ?? 0),
        'classified_total' => (int)($bank['classified'] ?? 0) + (int)($ledger['classified'] ?? 0),
        'open_bank_amount' => (float)($bank['open_amount'] ?? 0),
        'open_ledger_amount' => (float)($ledger['open_amount'] ?? 0),
        'open_difference' => $openDiff,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized', 401);
    $id = (int)($_GET['id'] ?? $_GET['reconciliation_id'] ?? 0);
    if (!$id) fail('id is required.');

    $stmt = $conn->prepare('SELECT * FROM bank_reconciliations WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $reconciliation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$reconciliation) fail('Reconciliation not found.', 404);

    $bankLines = $conn->query("SELECT * FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id ORDER BY match_status='Matched', transaction_date, ABS(amount), id")->fetch_all(MYSQLI_ASSOC);
    $ledgerLines = $conn->query("SELECT * FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id ORDER BY match_status='Matched', transaction_date, ABS(amount), id")->fetch_all(MYSQLI_ASSOC);
    $matches = $conn->query("SELECT * FROM bank_reconciliation_matches WHERE reconciliation_id=$id ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
    $adjustments = $conn->query("SELECT * FROM bank_reconciliation_adjustments WHERE reconciliation_id=$id ORDER BY adjustment_type, id")->fetch_all(MYSQLI_ASSOC);
    $summary = summaryFor($conn, $id);

    echo json_encode(['status' => 'Success', 'data' => [
        'reconciliation' => $reconciliation,
        'bank_lines' => $bankLines,
        'ledger_lines' => $ledgerLines,
        'matches' => $matches,
        'adjustments' => $adjustments,
        'summary' => $summary,
    ]]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
