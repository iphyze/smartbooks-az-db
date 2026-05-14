<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');

function fail($m, $c = 400) { throw new Exception($m, $c); }
function payload() {
    $p = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) $p = array_merge($p, $json);
        else { parse_str($raw, $form); if (is_array($form)) $p = array_merge($p, $form); }
    }
    return array_merge($_GET, $p);
}
function ids($v) {
    if (is_array($v)) return array_values(array_filter(array_map('intval', $v)));
    $decoded = json_decode((string)$v, true);
    if (is_array($decoded)) return array_values(array_filter(array_map('intval', $decoded)));
    return array_values(array_filter(array_map('intval', explode(',', (string)$v))));
}
function recalc($conn, $id) {
    $recon = $conn->query("SELECT statement_closing_balance, ledger_closing_balance FROM bank_reconciliations WHERE id=".(int)$id)->fetch_assoc();
    $bankUnmatched = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_reconciliation_bank_lines WHERE reconciliation_id=".(int)$id." AND match_status IN ('Unmatched','Suggested','Adjustment')")->fetch_assoc()['v'];
    $ledgerUnmatched = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=".(int)$id." AND match_status='Unmatched'")->fetch_assoc()['v'];
    $adjustedBank = (float)($recon['statement_closing_balance'] ?? 0) - $ledgerUnmatched;
    $adjustedLedger = (float)($recon['ledger_closing_balance'] ?? 0) + $bankUnmatched;
    $diff = round($adjustedBank - $adjustedLedger, 2);
    $status = abs($diff) <= 0.01 ? 'Balanced' : 'Needs Review';
    $stmt = $conn->prepare("UPDATE bank_reconciliations SET adjusted_bank_balance=?, adjusted_ledger_balance=?, unreconciled_difference=?, status=? WHERE id=?");
    $stmt->bind_param('dddsi', $adjustedBank, $adjustedLedger, $diff, $status, $id);
    $stmt->execute();
    $stmt->close();
    return ['adjusted_bank_balance' => $adjustedBank, 'adjusted_ledger_balance' => $adjustedLedger, 'unreconciled_difference' => $diff, 'status' => $status];
}
function sumLines($conn, $table, $id, $lineIds) {
    if (!$lineIds) return 0.0;
    $safe = implode(',', array_map('intval', $lineIds));
    $row = $conn->query("SELECT COALESCE(SUM(amount),0) v FROM $table WHERE reconciliation_id=".(int)$id." AND id IN ($safe)")->fetch_assoc();
    return round((float)$row['v'], 2);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized', 401);

    $p = payload();
    $id = (int)($p['reconciliation_id'] ?? $p['id'] ?? 0);
    $bankIds = ids($p['bank_line_ids'] ?? []);
    $ledgerIds = ids($p['ledger_line_ids'] ?? []);
    $notes = trim((string)($p['notes'] ?? 'Manual match'));
    if (!$id) fail('reconciliation_id is required.');
    if (!$bankIds || !$ledgerIds) fail('Select at least one bank line and one ledger line to match.');

    $recon = $conn->query("SELECT * FROM bank_reconciliations WHERE id=".(int)$id)->fetch_assoc();
    if (!$recon) fail('Reconciliation not found.', 404);

    $bankSum = sumLines($conn, 'bank_reconciliation_bank_lines', $id, $bankIds);
    $ledgerSum = sumLines($conn, 'bank_reconciliation_ledger_lines', $id, $ledgerIds);
    $amountTolerance = max(0.0, (float)($recon['match_tolerance_amount'] ?? 0));
    $difference = round($bankSum - $ledgerSum, 2);
    if (abs($difference) > $amountTolerance) {
        fail('Selected bank and ledger values do not balance. Difference: ' . number_format($difference, 2));
    }

    $createdBy = $user['email'] ?? $user['username'] ?? 'System';
    $group = 'MAN-' . $id . '-' . date('YmdHis') . '-' . random_int(100, 999);
    $safeGroup = $conn->real_escape_string($group);
    $conn->begin_transaction();

    $conn->query("UPDATE bank_reconciliation_bank_lines SET match_status='Matched', match_group='$safeGroup', suggested_type='Unknown', confidence=100 WHERE reconciliation_id=".(int)$id." AND id IN (".implode(',', $bankIds).")");
    $conn->query("UPDATE bank_reconciliation_ledger_lines SET match_status='Matched', match_group='$safeGroup', suggested_type='Unknown', confidence=100 WHERE reconciliation_id=".(int)$id." AND id IN (".implode(',', $ledgerIds).")");

    $ins = $conn->prepare("INSERT INTO bank_reconciliation_matches (reconciliation_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, notes, created_by) VALUES (?, ?, ?, ?, 'Manual', 100, ?, ?)");
    foreach ($bankIds as $bid) {
        $nullLedger = null;
        $ins->bind_param('isiiss', $id, $group, $bid, $nullLedger, $notes, $createdBy);
        $ins->execute();
    }
    foreach ($ledgerIds as $lid) {
        $nullBank = null;
        $ins->bind_param('isiiss', $id, $group, $nullBank, $lid, $notes, $createdBy);
        $ins->execute();
    }
    $ins->close();
    $summary = recalc($conn, $id);
    $conn->commit();

    echo json_encode(['status' => 'Success', 'message' => 'Manual match saved.', 'data' => ['match_group' => $group, 'bank_total' => $bankSum, 'ledger_total' => $ledgerSum, 'difference' => $difference] + $summary]);
} catch (Exception $e) {
    try { if (isset($conn)) $conn->rollback(); } catch (Throwable $t) {}
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
