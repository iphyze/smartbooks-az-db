<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');

function fail($m, $c = 400) { throw new Exception($m, $c); }
function getPayload() {
    $payload = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) $payload = array_merge($payload, $json);
        else {
            parse_str($raw, $form);
            if (is_array($form)) $payload = array_merge($payload, $form);
        }
    }
    return array_merge($_GET, $payload);
}
function daysBetween($a, $b) { return abs((strtotime($a) - strtotime($b)) / 86400); }
function simScore($a, $b) {
    $a = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', (string)$a));
    $b = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', (string)$b));
    similar_text($a, $b, $p);
    return round($p, 2);
}
function classifyBankOnly($line) {
    $txt = strtolower(($line['description'] ?? '') . ' ' . ($line['reference'] ?? ''));
    if ((float)$line['amount'] < 0 && preg_match('/charge|fee|commission|sms|vat|stamp|maintenance|levy|transfer charge|bank charge|card maintenance|coti|duty/', $txt)) return 'Bank Charge';
    if ((float)$line['amount'] > 0 && preg_match('/interest|yield|credit interest/', $txt)) return 'Bank Interest';
    return 'Ledger Omission';
}
function rebuildSummary($conn, $id) {
    $bankUnmatched = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_reconciliation_bank_lines WHERE reconciliation_id=".(int)$id." AND match_status IN ('Unmatched','Suggested','Adjustment')")->fetch_assoc()['v'];
    $ledgerUnmatched = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=".(int)$id." AND match_status='Unmatched'")->fetch_assoc()['v'];
    $recon = $conn->query("SELECT statement_closing_balance, ledger_closing_balance FROM bank_reconciliations WHERE id=".(int)$id)->fetch_assoc();
    $adjustedBank = (float)($recon['statement_closing_balance'] ?? 0) - $ledgerUnmatched;
    $adjustedLedger = (float)($recon['ledger_closing_balance'] ?? 0) + $bankUnmatched;
    $diff = round($adjustedBank - $adjustedLedger, 2);
    $status = abs($diff) <= 0.01 ? 'Balanced' : 'Needs Review';
    $stmt = $conn->prepare("UPDATE bank_reconciliations SET adjusted_bank_balance=?, adjusted_ledger_balance=?, unreconciled_difference=?, status=? WHERE id=?");
    $stmt->bind_param('dddsi', $adjustedBank, $adjustedLedger, $diff, $status, $id);
    $stmt->execute();
    $stmt->close();
    return [$adjustedBank, $adjustedLedger, $diff, $status];
}
function summary($conn, $id) {
    $out = [];
    foreach (['bank' => 'bank_reconciliation_bank_lines', 'ledger' => 'bank_reconciliation_ledger_lines'] as $k => $t) {
        $q = $conn->prepare("SELECT COUNT(*) total_lines, COALESCE(SUM(ABS(amount)),0) total_amount, COALESCE(SUM(match_status='Matched'),0) matched_lines, COALESCE(SUM(match_status='Unmatched'),0) unmatched_lines, COALESCE(SUM(match_status IN ('Suggested','Adjustment')),0) suggested_lines FROM $t WHERE reconciliation_id=?");
        $q->bind_param('i', $id);
        $q->execute();
        $out[$k] = $q->get_result()->fetch_assoc();
        $q->close();
    }
    return $out;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized', 401);

    $payload = getPayload();
    $id = (int)($payload['reconciliation_id'] ?? $payload['id'] ?? 0);
    if (!$id) fail('reconciliation_id is required.');

    $r = $conn->prepare('SELECT * FROM bank_reconciliations WHERE id=? LIMIT 1');
    $r->bind_param('i', $id);
    $r->execute();
    $recon = $r->get_result()->fetch_assoc();
    $r->close();
    if (!$recon) fail('Reconciliation not found.', 404);

    $tolDays = (int)$recon['match_tolerance_days'];
    $tolAmount = (float)$recon['match_tolerance_amount'];
    $createdBy = $user['email'] ?? $user['username'] ?? 'System';

    $conn->begin_transaction();
    $conn->query("DELETE FROM bank_reconciliation_matches WHERE reconciliation_id=".(int)$id);
    $conn->query("DELETE FROM bank_reconciliation_adjustments WHERE reconciliation_id=".(int)$id);
    $conn->query("UPDATE bank_reconciliation_bank_lines SET match_status='Unmatched', match_group=NULL, confidence=0, suggested_type='Unknown' WHERE reconciliation_id=".(int)$id);
    $conn->query("UPDATE bank_reconciliation_ledger_lines SET match_status='Unmatched', match_group=NULL, confidence=0, suggested_type='Unknown' WHERE reconciliation_id=".(int)$id);

    $banks = $conn->query("SELECT * FROM bank_reconciliation_bank_lines WHERE reconciliation_id=".(int)$id." ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $ledgers = $conn->query("SELECT * FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=".(int)$id." ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $used = [];
    $matchIns = $conn->prepare("INSERT INTO bank_reconciliation_matches (reconciliation_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");

    foreach ($banks as $b) {
        $best = null;
        $bestScore = -1;
        foreach ($ledgers as $l) {
            if (isset($used[$l['id']])) continue;
            $amountDiff = abs((float)$b['amount'] - (float)$l['amount']);
            if ($amountDiff > $tolAmount) continue;
            $dayDiff = daysBetween($b['transaction_date'], $l['transaction_date']);
            if ($dayDiff > $tolDays) continue;
            $text = simScore($b['description'].' '.$b['reference'], $l['description'].' '.$l['reference']);
            $score = 100 - ($dayDiff * 7) - ($amountDiff > 0 ? 10 : 0) + ($text / 10);
            if ($score > $bestScore) { $bestScore = $score; $best = $l; }
        }
        if ($best) {
            $group = 'AUTO-' . $id . '-' . $b['id'] . '-' . $best['id'];
            $type = daysBetween($b['transaction_date'], $best['transaction_date']) === 0 ? 'Exact' : 'Date Tolerance';
            $conf = max(60, min(100, round($bestScore, 2)));
            $safeGroup = $conn->real_escape_string($group);
            $conn->query("UPDATE bank_reconciliation_bank_lines SET match_status='Matched', match_group='$safeGroup', confidence=$conf WHERE id=".(int)$b['id']);
            $conn->query("UPDATE bank_reconciliation_ledger_lines SET match_status='Matched', match_group='$safeGroup', confidence=$conf WHERE id=".(int)$best['id']);
            $notes = 'Auto matched by amount/date/narration';
            $matchIns->bind_param('isiisdss', $id, $group, $b['id'], $best['id'], $type, $conf, $notes, $createdBy);
            $matchIns->execute();
            $used[$best['id']] = true;
        }
    }
    $matchIns->close();

    $unmatchedBanks = $conn->query("SELECT * FROM bank_reconciliation_bank_lines WHERE reconciliation_id=".(int)$id." AND match_status='Unmatched'")->fetch_all(MYSQLI_ASSOC);
    $adj = $conn->prepare("INSERT INTO bank_reconciliation_adjustments (reconciliation_id, source_line_type, source_line_id, adjustment_type, recommended_debit_ledger, recommended_credit_ledger, amount, narration, created_by) VALUES (?, 'Bank', ?, ?, ?, ?, ?, ?, ?)");
    foreach ($unmatchedBanks as $b) {
        $type = classifyBankOnly($b);
        $debit = null; $credit = null; $amount = abs((float)$b['amount']);
        if ($type === 'Bank Charge') { $debit = 'Bank Charges / Finance Cost'; $credit = 'Bank'; }
        elseif ($type === 'Bank Interest') { $debit = 'Bank'; $credit = 'Interest Income / Other Income'; }
        else { $debit = ((float)$b['amount'] < 0 ? 'Expense / Payable Ledger' : 'Bank'); $credit = ((float)$b['amount'] < 0 ? 'Bank' : 'Revenue / Receivable Ledger'); }
        $confidence = in_array($type, ['Bank Charge', 'Bank Interest']) ? 82 : 55;
        $status = in_array($type, ['Bank Charge', 'Bank Interest']) ? 'Adjustment' : 'Suggested';
        $conn->query("UPDATE bank_reconciliation_bank_lines SET match_status='$status', suggested_type='".$conn->real_escape_string($type)."', confidence=$confidence WHERE id=".(int)$b['id']);
        $narr = 'Suggested adjustment from bank statement: '.$b['description'];
        $adj->bind_param('iisssdss', $id, $b['id'], $type, $debit, $credit, $amount, $narr, $createdBy);
        $adj->execute();
    }
    $adj->close();

    $unmatchedLedgers = $conn->query("SELECT * FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=".(int)$id." AND match_status='Unmatched'")->fetch_all(MYSQLI_ASSOC);
    foreach ($unmatchedLedgers as $l) {
        $type = ((float)$l['amount'] > 0) ? 'Outstanding Deposit' : 'Outstanding Payment';
        $conn->query("UPDATE bank_reconciliation_ledger_lines SET suggested_type='".$conn->real_escape_string($type)."', confidence=70 WHERE id=".(int)$l['id']);
    }

    [$adjustedBank, $adjustedLedger, $diff, $status] = rebuildSummary($conn, $id);
    $u = $conn->prepare("UPDATE bank_reconciliations SET status=?, updated_by=? WHERE id=?");
    $u->bind_param('ssi', $status, $createdBy, $id);
    $u->execute();
    $u->close();

    $conn->commit();
    echo json_encode(['status' => 'Success', 'message' => 'Reconciliation analysis completed.', 'data' => ['id' => $id, 'reconciliation_number' => $recon['reconciliation_number'], 'status' => $status, 'adjusted_bank_balance' => $adjustedBank, 'adjusted_ledger_balance' => $adjustedLedger, 'unreconciled_difference' => $diff, 'summary' => summary($conn, $id)]]);
} catch (Exception $e) {
    try { if (isset($conn)) $conn->rollback(); } catch (Throwable $t) {}
    error_log('Bank reconciliation analyze error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
