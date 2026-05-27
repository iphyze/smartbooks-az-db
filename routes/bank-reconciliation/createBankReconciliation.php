<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function fail($message, $code = 400) { throw new Exception($message, $code); }
function clean($v) { return trim((string)($v ?? '')); }
function money($v) {
    $v = str_replace([',', '₦', '$', '€', '£', ' '], '', (string)$v);
    if ($v === '' || strtolower($v) === 'null') return 0.00;
    if (preg_match('/^\((.*)\)$/', $v, $m)) $v = '-' . $m[1];
    return round((float)$v, 2);
}
function normDate($v) {
    $v = clean($v);
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}
function readCsvRows($filePath) {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) === false) return $rows;
    $headers = null;
    while (($data = fgetcsv($handle, 0, ',')) !== false) {
        if (!$headers) {
            $headers = array_map(function($h) { return strtolower(trim(preg_replace('/[^a-zA-Z0-9_ ]/', '', $h))); }, $data);
            continue;
        }
        if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = $data[$i] ?? '';
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}
function getAny($row, $keys, $default = '') {
    foreach ($keys as $k) {
        $key = strtolower($k);
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') return $row[$key];
    }
    return $default;
}
function normalizeBankLine($row) {
    $date = normDate(getAny($row, ['transaction date','date','posting date','txn date','tran date']));
    $valueDate = normDate(getAny($row, ['value date','effective date']));
    $description = clean(getAny($row, ['description','narration','details','transaction details','remarks','particulars']));
    $reference = clean(getAny($row, ['reference','ref','transaction reference','cheque no','instrument no']));
    $debit = money(getAny($row, ['debit','withdrawal','withdrawals','money out','paid out'], 0));
    $credit = money(getAny($row, ['credit','deposit','deposits','money in','paid in'], 0));
    $amount = money(getAny($row, ['amount','transaction amount'], 0));
    if ($amount == 0 && ($debit || $credit)) $amount = $credit - $debit;
    if ($debit == 0 && $amount < 0) $debit = abs($amount);
    if ($credit == 0 && $amount > 0) $credit = $amount;
    $balance = money(getAny($row, ['balance','running balance','closing balance'], 0));
    return compact('date','valueDate','description','reference','debit','credit','amount','balance') + ['raw' => $row];
}
function normalizeLedgerLine($row) {
    $date = normDate(getAny($row, ['transaction date','date','posting date','journal date','entry date']));
    $description = clean(getAny($row, ['description','narration','details','transaction details','remarks','particulars']));
    $reference = clean(getAny($row, ['reference','ref','journal number','voucher number','transaction id']));
    $ledgerNumber = clean(getAny($row, ['ledger number','ledger no','account number','account code']));
    $ledgerName = clean(getAny($row, ['ledger name','account name','ledger','account']));
    $debit = money(getAny($row, ['debit','dr'], 0));
    $credit = money(getAny($row, ['credit','cr'], 0));
    $amount = money(getAny($row, ['amount','transaction amount'], 0));
    if ($amount == 0 && ($debit || $credit)) $amount = $debit - $credit; // ledger positive = debit to bank, negative = credit to bank
    return compact('date','description','reference','ledgerNumber','ledgerName','debit','credit','amount') + ['raw' => $row];
}
function classifyBankOnly($line) {
    $txt = strtolower($line['description'] . ' ' . $line['reference']);
    if ($line['amount'] < 0 && preg_match('/charge|fee|commission|sms|vat|stamp|maintenance|levy|transfer charge|bank charge/', $txt)) return 'Bank Charge';
    if ($line['amount'] > 0 && preg_match('/interest|yield|credit interest/', $txt)) return 'Bank Interest';
    if ($line['amount'] > 0) return 'Ledger Omission';
    if ($line['amount'] < 0) return 'Ledger Omission';
    return 'Unknown';
}
function hashLine($prefix, $line) {
    return hash('sha256', $prefix . '|' . ($line['date'] ?? '') . '|' . ($line['amount'] ?? '') . '|' . strtolower($line['description'] ?? '') . '|' . ($line['reference'] ?? ''));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized: Only Admins or Controllers can access this resource', 401);

    $bankIdRaw = $_POST['bank_id'] ?? null;
    $bankId = ($bankIdRaw === null || $bankIdRaw === '' || (int)$bankIdRaw <= 0) ? null : (int)$bankIdRaw;
    $companyName = clean($_POST['company_name'] ?? '');
    $bankName = clean($_POST['bank_name'] ?? '');
    $accountName = clean($_POST['account_name'] ?? '');
    $accountNumber = clean($_POST['account_number'] ?? '');
    $currency = strtoupper(clean($_POST['currency'] ?? 'NGN'));
    $notes = clean($_POST['notes'] ?? '');
    $dateFrom = normDate($_POST['datefrom'] ?? '');
    $dateTo = normDate($_POST['dateto'] ?? '');
    $openingBank = money($_POST['statement_opening_balance'] ?? 0);
    $closingBank = money($_POST['statement_closing_balance'] ?? 0);
    $openingLedger = money($_POST['ledger_opening_balance'] ?? 0);
    $closingLedger = money($_POST['ledger_closing_balance'] ?? 0);
    $toleranceDays = max(0, min(30, (int)($_POST['match_tolerance_days'] ?? 3)));
    $toleranceAmount = money($_POST['match_tolerance_amount'] ?? 0);

    if ($companyName === '') fail('Company / Client Name is required.');
    if ($currency === '') $currency = 'NGN';
    if (!$dateFrom || !$dateTo) fail('Date From and Date To are required.');
    if (!isset($_FILES['bank_statement']) || $_FILES['bank_statement']['error'] !== UPLOAD_ERR_OK) fail('Bank statement CSV is required.');
    if (!isset($_FILES['ledger_statement']) || $_FILES['ledger_statement']['error'] !== UPLOAD_ERR_OK) fail('Ledger statement CSV is required.');


    $bankRows = array_map('normalizeBankLine', readCsvRows($_FILES['bank_statement']['tmp_name']));
    $ledgerRows = array_map('normalizeLedgerLine', readCsvRows($_FILES['ledger_statement']['tmp_name']));
    $bankRows = array_values(array_filter($bankRows, fn($r) => $r['date'] && $r['description'] !== '' && $r['amount'] != 0));
    $ledgerRows = array_values(array_filter($ledgerRows, fn($r) => $r['date'] && $r['description'] !== '' && $r['amount'] != 0));
    if (count($bankRows) === 0) fail('No valid bank statement rows were found. Use CSV columns like Date, Description, Debit, Credit, Balance.');
    if (count($ledgerRows) === 0) fail('No valid ledger rows were found. Use CSV columns like Date, Description, Debit, Credit.');

    $conn->begin_transaction();
    $reconNo = 'BR-' . date('Ymd-His') . '-' . random_int(100, 999);
    $createdBy = $user['email'] ?? $user['username'] ?? 'System';
    $bankFileName = $_FILES['bank_statement']['name'];
    $ledgerFileName = $_FILES['ledger_statement']['name'];

    $stmt = $conn->prepare("INSERT INTO bank_reconciliations (reconciliation_number, bank_id, company_name, bank_name, account_name, account_number, currency, date_from, date_to, statement_opening_balance, statement_closing_balance, ledger_opening_balance, ledger_closing_balance, status, match_tolerance_days, match_tolerance_amount, bank_file_name, ledger_file_name, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sisssssssddddidssss', $reconNo, $bankId, $companyName, $bankName, $accountName, $accountNumber, $currency, $dateFrom, $dateTo, $openingBank, $closingBank, $openingLedger, $closingLedger, $toleranceDays, $toleranceAmount, $bankFileName, $ledgerFileName, $notes, $createdBy);
    $stmt->execute();
    $reconId = $stmt->insert_id;
    $stmt->close();

    $bStmt = $conn->prepare("INSERT IGNORE INTO bank_reconciliation_bank_lines (reconciliation_id, line_hash, transaction_date, value_date, reference, description, debit, credit, amount, running_balance, raw_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($bankRows as $r) {
        $hash = hashLine('bank', $r);
        $raw = json_encode($r['raw'], JSON_UNESCAPED_UNICODE);
        $bStmt->bind_param('isssssdddds', $reconId, $hash, $r['date'], $r['valueDate'], $r['reference'], $r['description'], $r['debit'], $r['credit'], $r['amount'], $r['balance'], $raw);
        $bStmt->execute();
    }
    $bStmt->close();

    $lStmt = $conn->prepare("INSERT IGNORE INTO bank_reconciliation_ledger_lines (reconciliation_id, line_hash, transaction_date, reference, ledger_number, ledger_name, description, debit, credit, amount, raw_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($ledgerRows as $r) {
        $hash = hashLine('ledger', $r);
        $raw = json_encode($r['raw'], JSON_UNESCAPED_UNICODE);
        $lStmt->bind_param('issssssddds', $reconId, $hash, $r['date'], $r['reference'], $r['ledgerNumber'], $r['ledgerName'], $r['description'], $r['debit'], $r['credit'], $r['amount'], $raw);
        $lStmt->execute();
    }
    $lStmt->close();

    $conn->commit();

    echo json_encode(['status' => 'Success', 'message' => 'Bank reconciliation created and files imported.', 'id' => $reconId, 'reconciliation_number' => $reconNo, 'data' => ['id' => $reconId, 'reconciliation_number' => $reconNo]]);
} catch (Exception $e) {
    if (isset($conn) && $conn->errno === 0) { try { $conn->rollback(); } catch (Throwable $t) {} }
    error_log('Bank reconciliation create error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}
