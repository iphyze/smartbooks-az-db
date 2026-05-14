<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');

function fail($m, $c = 400) { throw new Exception($m, $c); }
function payload() { $p = $_POST; $raw = file_get_contents('php://input'); if ($raw) { $j = json_decode($raw, true); if (is_array($j)) $p = array_merge($p, $j); else { parse_str($raw, $f); if (is_array($f)) $p = array_merge($p, $f); } } return array_merge($_GET, $p); }
function ids($v) { if (is_array($v)) return array_values(array_filter(array_map('intval', $v))); $j = json_decode((string)$v, true); if (is_array($j)) return array_values(array_filter(array_map('intval', $j))); return array_values(array_filter(array_map('intval', explode(',', (string)$v)))); }
function recalc($conn, $id) { $recon = $conn->query("SELECT statement_closing_balance, ledger_closing_balance FROM bank_reconciliations WHERE id=".(int)$id)->fetch_assoc(); $bankUnmatched = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_reconciliation_bank_lines WHERE reconciliation_id=".(int)$id." AND match_status IN ('Unmatched','Suggested','Adjustment')")->fetch_assoc()['v']; $ledgerUnmatched = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=".(int)$id." AND match_status='Unmatched'")->fetch_assoc()['v']; $adjustedBank = (float)($recon['statement_closing_balance'] ?? 0) - $ledgerUnmatched; $adjustedLedger = (float)($recon['ledger_closing_balance'] ?? 0) + $bankUnmatched; $diff = round($adjustedBank - $adjustedLedger, 2); $status = abs($diff) <= 0.01 ? 'Balanced' : 'Needs Review'; $stmt = $conn->prepare("UPDATE bank_reconciliations SET adjusted_bank_balance=?, adjusted_ledger_balance=?, unreconciled_difference=?, status=? WHERE id=?"); $stmt->bind_param('dddsi', $adjustedBank, $adjustedLedger, $diff, $status, $id); $stmt->execute(); $stmt->close(); return ['adjusted_bank_balance'=>$adjustedBank,'adjusted_ledger_balance'=>$adjustedLedger,'unreconciled_difference'=>$diff,'status'=>$status]; }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized', 401);
    $p = payload();
    $id = (int)($p['reconciliation_id'] ?? $p['id'] ?? 0);
    $bankIds = ids($p['bank_line_ids'] ?? []);
    $type = trim((string)($p['adjustment_type'] ?? 'Other'));
    $allowed = ['Bank Charge','Bank Interest','Direct Debit','Direct Credit','Correction','Other'];
    if (!in_array($type, $allowed)) $type = 'Other';
    if (!$id) fail('reconciliation_id is required.');
    if (!$bankIds) fail('Select at least one bank line to classify.');

    $createdBy = $user['email'] ?? $user['username'] ?? 'System';
    $debit = trim((string)($p['debit_ledger'] ?? ''));
    $credit = trim((string)($p['credit_ledger'] ?? ''));
    if ($debit === '' || $credit === '') {
        if ($type === 'Bank Charge') { $debit = 'Bank Charges / Finance Cost'; $credit = 'Bank'; }
        elseif ($type === 'Bank Interest') { $debit = 'Bank'; $credit = 'Interest Income / Other Income'; }
        elseif ($type === 'Direct Debit') { $debit = 'Expense / Payable Ledger'; $credit = 'Bank'; }
        elseif ($type === 'Direct Credit') { $debit = 'Bank'; $credit = 'Income / Receivable Ledger'; }
        else { $debit = 'Suspense / Clearing'; $credit = 'Bank / Clearing'; }
    }

    $conn->begin_transaction();
    $lineSql = implode(',', array_map('intval', $bankIds));
    $lines = $conn->query("SELECT * FROM bank_reconciliation_bank_lines WHERE reconciliation_id=".(int)$id." AND id IN ($lineSql)")->fetch_all(MYSQLI_ASSOC);
    if (!$lines) fail('No valid bank lines selected.');

    $adj = $conn->prepare("INSERT INTO bank_reconciliation_adjustments (reconciliation_id, source_line_type, source_line_id, adjustment_type, recommended_debit_ledger, recommended_credit_ledger, amount, narration, created_by) VALUES (?, 'Bank', ?, ?, ?, ?, ?, ?, ?)");
    foreach ($lines as $line) {
        $amount = abs((float)$line['amount']);
        $narr = trim((string)($p['notes'] ?? 'Manual classification: ' . $line['description']));
        $adj->bind_param('iisssdss', $id, $line['id'], $type, $debit, $credit, $amount, $narr, $createdBy);
        $adj->execute();
    }
    $adj->close();
    $safeType = $conn->real_escape_string($type);
    $conn->query("UPDATE bank_reconciliation_bank_lines SET match_status='Adjustment', suggested_type='$safeType', confidence=100 WHERE reconciliation_id=".(int)$id." AND id IN ($lineSql)");
    $summary = recalc($conn, $id);
    $conn->commit();

    echo json_encode(['status'=>'Success','message'=>'Bank line classified for adjustment.','data'=>$summary]);
} catch (Exception $e) {
    try { if (isset($conn)) $conn->rollback(); } catch (Throwable $t) {}
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status'=>'Failed','message'=>$e->getMessage()]);
}
