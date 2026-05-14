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
function daysBetween($a, $b) { return abs((strtotime($a) - strtotime($b)) / 86400); }
function simScore($a, $b) {
    $a = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', (string)$a));
    $b = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', (string)$b));
    similar_text($a, $b, $p);
    return round($p, 2);
}
function guessType($description, $source, $amount) {
    $txt = strtolower((string)$description);
    if (preg_match('/vat.*charge|charge.*vat/', $txt)) return 'VAT on Bank Charges';
    if (preg_match('/stamp|duty/', $txt)) return 'Stamp Duty';
    if (preg_match('/commission|lc commission|l\/c|letter of credit/', $txt)) return 'LC Commission';
    if (preg_match('/charge|fee|sms|maintenance|levy|coti|handling/', $txt)) return 'Bank Charges';
    if (preg_match('/interest|yield/', $txt)) return 'Interest Income';
    if ($source === 'Bank' && (float)$amount < 0) return 'Unposted Debit';
    if ($source === 'Bank' && (float)$amount > 0) return 'Unposted Credit';
    return 'Timing Difference';
}
function recalc($conn, $id) {
    $id = (int)$id;
    $bank = $conn->query("SELECT COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id")->fetch_assoc();
    $ledger = $conn->query("SELECT COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id")->fetch_assoc();
    $diff = round((float)$bank['open_amount'] - (float)$ledger['open_amount'], 2);
    $status = abs($diff) <= 0.01 ? 'Balanced' : 'Needs Review';
    $stmt = $conn->prepare("UPDATE bank_reconciliations SET unreconciled_difference=?, status=? WHERE id=?");
    $stmt->bind_param('dsi', $diff, $status, $id);
    $stmt->execute();
    $stmt->close();
    return [$diff, $status];
}
function summaryFor($conn, $id) {
    $id = (int)$id;
    $bank = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(match_status='Matched'),0) matched, COALESCE(SUM(match_status='Adjustment'),0) classified, COALESCE(SUM(match_status NOT IN ('Matched','Adjustment')),0) unmatched, COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id")->fetch_assoc();
    $ledger = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(match_status='Matched'),0) matched, COALESCE(SUM(match_status='Adjustment'),0) classified, COALESCE(SUM(match_status NOT IN ('Matched','Adjustment')),0) unmatched, COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id")->fetch_assoc();
    return [
        'bank_total' => (int)$bank['total'], 'ledger_total' => (int)$ledger['total'],
        'matched_bank' => (int)$bank['matched'], 'matched_ledger' => (int)$ledger['matched'],
        'unmatched_bank' => (int)$bank['unmatched'], 'unmatched_ledger' => (int)$ledger['unmatched'],
        'classified_total' => (int)$bank['classified'] + (int)$ledger['classified'],
        'open_difference' => round((float)$bank['open_amount'] - (float)$ledger['open_amount'], 2),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized', 401);
    $p = payload();
    $id = (int)($p['reconciliation_id'] ?? $p['id'] ?? 0);
    if (!$id) fail('reconciliation_id is required.');

    $r = $conn->prepare('SELECT * FROM bank_reconciliations WHERE id=? LIMIT 1');
    $r->bind_param('i', $id);
    $r->execute();
    $recon = $r->get_result()->fetch_assoc();
    $r->close();
    if (!$recon) fail('Reconciliation not found.', 404);

    $tolDays = (int)($recon['match_tolerance_days'] ?? 3);
    $tolAmount = (float)($recon['match_tolerance_amount'] ?? 0);
    $createdBy = $user['email'] ?? $user['username'] ?? 'System';

    $conn->begin_transaction();
    $conn->query("DELETE FROM bank_reconciliation_matches WHERE reconciliation_id=$id AND match_type IN ('Exact','Date Tolerance','Amount Tolerance','Narration Similarity')");
    $conn->query("UPDATE bank_reconciliation_bank_lines SET match_status='Unmatched', match_group=NULL, confidence=0 WHERE reconciliation_id=$id AND match_status='Matched' AND match_group LIKE 'AUTO-%'");
    $conn->query("UPDATE bank_reconciliation_ledger_lines SET match_status='Unmatched', match_group=NULL, confidence=0 WHERE reconciliation_id=$id AND match_status='Matched' AND match_group LIKE 'AUTO-%'");

    $banks = $conn->query("SELECT * FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id AND match_status='Unmatched' ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $ledgers = $conn->query("SELECT * FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id AND match_status='Unmatched' ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $usedLedger = [];
    $matchIns = $conn->prepare("INSERT INTO bank_reconciliation_matches (reconciliation_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");

    foreach ($banks as $b) {
        $best = null; $bestScore = -9999;
        foreach ($ledgers as $l) {
            if (isset($usedLedger[$l['id']])) continue;
            if (((float)$b['debit'] > 0 && (float)$l['credit'] <= 0) || ((float)$b['credit'] > 0 && (float)$l['debit'] <= 0)) continue;
            $amountDiff = abs(abs((float)$b['amount']) - abs((float)$l['amount']));
            if ($amountDiff > $tolAmount) continue;
            $dayDiff = daysBetween($b['transaction_date'], $l['transaction_date']);
            if ($dayDiff > $tolDays) continue;
            $text = simScore($b['description'].' '.$b['reference'], $l['description'].' '.$l['reference']);
            $score = 100 - ($dayDiff * 8) - ($amountDiff > 0 ? 15 : 0) + ($text / 10);
            if ($score > $bestScore) { $bestScore = $score; $best = $l; }
        }
        if ($best) {
            $group = 'AUTO-' . $id . '-' . $b['id'] . '-' . $best['id'];
            $type = daysBetween($b['transaction_date'], $best['transaction_date']) === 0 ? 'Exact' : 'Date Tolerance';
            $conf = max(60, min(100, round($bestScore, 2)));
            $safeGroup = $conn->real_escape_string($group);
            $conn->query("UPDATE bank_reconciliation_bank_lines SET match_status='Matched', match_group='$safeGroup', confidence=$conf WHERE id=".(int)$b['id']);
            $conn->query("UPDATE bank_reconciliation_ledger_lines SET match_status='Matched', match_group='$safeGroup', confidence=$conf WHERE id=".(int)$best['id']);
            $notes = 'Auto matched: bank debit to ledger credit, or bank credit to ledger debit';
            $bid = (int)$b['id']; $lid = (int)$best['id'];
            $matchIns->bind_param('isiisdss', $id, $group, $bid, $lid, $type, $conf, $notes, $createdBy);
            $matchIns->execute();
            $usedLedger[$best['id']] = true;
        }
    }
    $matchIns->close();

    $openBanks = $conn->query("SELECT * FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id AND match_status='Unmatched'")->fetch_all(MYSQLI_ASSOC);
    foreach ($openBanks as $b) {
        $type = $conn->real_escape_string(guessType($b['description'], 'Bank', $b['amount']));
        $conn->query("UPDATE bank_reconciliation_bank_lines SET suggested_type='$type', confidence=45 WHERE id=".(int)$b['id']);
    }
    $openLedgers = $conn->query("SELECT * FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id AND match_status='Unmatched'")->fetch_all(MYSQLI_ASSOC);
    foreach ($openLedgers as $l) {
        $type = ((float)$l['debit'] > 0) ? 'Outstanding Deposit' : 'Outstanding Payment';
        $conn->query("UPDATE bank_reconciliation_ledger_lines SET suggested_type='".$conn->real_escape_string($type)."', confidence=45 WHERE id=".(int)$l['id']);
    }

    [$diff, $status] = recalc($conn, $id);
    $conn->commit();
    echo json_encode(['status' => 'Success', 'message' => 'Auto-match completed.', 'data' => ['id' => $id, 'status' => $status, 'unreconciled_difference' => $diff, 'summary' => summaryFor($conn, $id)]]);
} catch (Exception $e) {
    try { if (isset($conn)) $conn->rollback(); } catch (Throwable $t) {}
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
