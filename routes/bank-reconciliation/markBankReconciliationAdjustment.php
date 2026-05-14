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
    $bank = $conn->query("SELECT COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_bank_lines WHERE reconciliation_id=".(int)$id)->fetch_assoc();
    $ledger = $conn->query("SELECT COALESCE(SUM(CASE WHEN match_status NOT IN ('Matched','Adjustment') THEN amount ELSE 0 END),0) open_amount FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=".(int)$id)->fetch_assoc();
    $diff = round((float)$bank['open_amount'] - (float)$ledger['open_amount'], 2);
    $status = abs($diff) <= 0.01 ? 'Balanced' : 'Needs Review';
    $stmt = $conn->prepare("UPDATE bank_reconciliations SET unreconciled_difference=?, status=? WHERE id=?");
    $stmt->bind_param('dsi', $diff, $status, $id);
    $stmt->execute(); $stmt->close();
    return ['unreconciled_difference' => $diff, 'status' => $status];
}
function recommendedLedgers($source, $line, $category) {
    $debit = 'Suspense / Clearing'; $credit = 'Suspense / Clearing';
    $cat = strtolower($category);
    if ($source === 'Bank') {
        if ((float)$line['debit'] > 0) {
            $debit = preg_match('/vat|tax/', $cat) ? 'Input VAT / Tax Recoverable' : (preg_match('/charge|commission|fee|duty/', $cat) ? 'Bank Charges / Finance Cost' : 'Expense / Clearing');
            $credit = 'Bank';
        } else {
            $debit = 'Bank';
            $credit = preg_match('/interest/', $cat) ? 'Interest Income / Other Income' : 'Income / Clearing';
        }
    } else {
        if ((float)$line['debit'] > 0) { $debit = 'Bank'; $credit = 'Outstanding Deposit / Clearing'; }
        else { $debit = 'Outstanding Payment / Clearing'; $credit = 'Bank'; }
    }
    return [$debit, $credit];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized', 401);
    $p = payload();
    $id = (int)($p['reconciliation_id'] ?? $p['id'] ?? 0);
    $source = ucfirst(strtolower(trim((string)($p['source'] ?? 'Bank'))));
    $lineIds = ids($p['line_ids'] ?? $p['bank_line_ids'] ?? $p['ledger_line_ids'] ?? []);
    $category = trim((string)($p['category'] ?? $p['adjustment_type'] ?? 'Other'));
    $notes = trim((string)($p['notes'] ?? ''));
    if (!$id) fail('reconciliation_id is required.');
    if (!in_array($source, ['Bank','Ledger'])) fail('source must be Bank or Ledger.');
    if (!$lineIds) fail('Select at least one line to classify.');
    if ($category === '') fail('Classification category is required.');

    $table = $source === 'Bank' ? 'bank_reconciliation_bank_lines' : 'bank_reconciliation_ledger_lines';
    $createdBy = $user['email'] ?? $user['username'] ?? 'System';
    $conn->begin_transaction();
    $ins = $conn->prepare("INSERT INTO bank_reconciliation_adjustments (reconciliation_id, source_line_type, source_line_id, adjustment_type, recommended_debit_ledger, recommended_credit_ledger, amount, narration, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($lineIds as $lineId) {
        $line = $conn->query("SELECT * FROM $table WHERE reconciliation_id=".(int)$id." AND id=".(int)$lineId." AND match_status NOT IN ('Matched','Adjustment') LIMIT 1")->fetch_assoc();
        if (!$line) continue;
        [$debit, $credit] = recommendedLedgers($source, $line, $category);
        $amount = abs((float)$line['amount']);
        $narration = ($notes !== '' ? $notes.' - ' : '') . $line['description'];
        $ins->bind_param('isisssdss', $id, $source, $lineId, $category, $debit, $credit, $amount, $narration, $createdBy);
        $ins->execute();
        $safeCat = $conn->real_escape_string($category);
        $conn->query("UPDATE $table SET match_status='Adjustment', suggested_type='$safeCat', confidence=100 WHERE reconciliation_id=".(int)$id." AND id=".(int)$lineId);
    }
    $ins->close();
    $summary = recalc($conn, $id);
    $conn->commit();
    echo json_encode(['status' => 'Success', 'message' => 'Selected lines classified.', 'data' => $summary]);
} catch (Exception $e) {
    try { if (isset($conn)) $conn->rollback(); } catch (Throwable $t) {}
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
