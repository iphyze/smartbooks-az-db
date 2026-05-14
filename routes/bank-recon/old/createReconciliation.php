<?php
/**
 * POST /bank-recon/create
 *
 * Accepts multipart/form-data.
 * Bank file: CSV or XLSX (Date, Description, Debit, Credit, Balance)
 * Ledger file: CSV or XLSX (Date, Description, Debit, Credit, Ledger[, Balance])
 *
 * FIXES vs previous version:
 *   - match() replaced with switch for PHP 7.4 compatibility
 *   - function renamed to brFail() consistently
 *   - XLSX file upload support added via PhpSpreadsheet
 *   - Ledger balance column now supported
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function brFail(string $msg, int $code = 400): void {
    throw new Exception($msg, $code);
}

/** Strip currency symbols, commas, parentheses; return absolute float */
function parseAmt(string $raw): float {
    $v = trim($raw);
    if ($v === '' || strtolower($v) === 'null') return 0.0;
    $v = str_replace([',', '₦', '$', '£', '€', ' ', "\xc2\xa0"], '', $v);
    if (preg_match('/^\((.+)\)$/', $v, $m)) $v = $m[1];
    return round((float)ltrim($v, '-+'), 2);
}

/** Parse any common date string → YYYY-MM-DD or null */
function parseDateStr(string $raw): ?string {
    $v = trim($raw);
    if ($v === '') return null;
    // Handle Excel numeric date serial (float)
    if (is_numeric($v) && (float)$v > 40000) {
        $ts = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Normalise a CSV/header cell */
function normHdr(string $h): string {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', ' ', $h)));
}

/**
 * Read any file (CSV or XLSX) into array of associative rows.
 * Returns rows keyed by normalised headers.
 */
function readFile(string $path, string $origName): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'xls') {
        return readXlsx($path, $ext);
    }
    return readCsv($path);
}

function readCsv(string $path): array {
    $rows = [];
    if (!($fh = fopen($path, 'r'))) return $rows;
    $bom = fread($fh, 3);
    if ($bom !== "\xef\xbb\xbf") rewind($fh);
    $headers = null;
    while (($cells = fgetcsv($fh, 0, ',')) !== false) {
        if ($cells === [null]) continue;
        if ($headers === null) { $headers = array_map('normHdr', $cells); continue; }
        if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) continue;
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function readXlsx(string $path, string $ext): array {
    $reader = $ext === 'xls'
        ? new \PhpOffice\PhpSpreadsheet\Reader\Xls()
        : new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $reader->setReadDataOnly(true);
    $ss      = $reader->load($path);
    $ws      = $ss->getActiveSheet();
    $rows    = [];
    $headers = null;
    foreach ($ws->getRowIterator() as $row) {
        $cells = [];
        foreach ($row->getCellIterator() as $cell) {
            $v = $cell->getValue();
            // Convert date serials
            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($v)) {
                $v = date('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($v));
            }
            $cells[] = $v !== null ? trim((string)$v) : '';
        }
        if (array_filter($cells, fn($c) => $c !== '') === []) continue;
        if ($headers === null) { $headers = array_map('normHdr', $cells); continue; }
        $row2 = [];
        foreach ($headers as $i => $h) $row2[$h] = $cells[$i] ?? '';
        $rows[] = $row2;
    }
    return $rows;
}

/** Return first non-empty value from a row using candidate keys */
function pick(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $k) {
        $k = strtolower($k);
        if (isset($row[$k]) && trim($row[$k]) !== '') return $row[$k];
    }
    return $default;
}

/**
 * Parse a bank row.
 * Debit column = money OUT (direction=OUT)
 * Credit column = money IN  (direction=IN)
 */
function parseBankRow(array $row): ?array {
    $date = parseDateStr(pick($row, ['date','create date','transaction date','txn date','posting date','value date','effective date']));
    if (!$date) return null;
    $desc = pick($row, ['description','description payee memo','description/payee/memo','narration','details','remarks','particulars']);
    if ($desc === '') return null;
    $ref     = pick($row, ['reference','ref','check no','cheque no']);
    $debit   = parseAmt(pick($row, ['debit','debit amount','withdrawal','dr','money out']));
    $credit  = parseAmt(pick($row, ['credit','credit amount','deposit','cr','money in']));
    $balance = parseAmt(pick($row, ['balance','running balance','closing balance']));
    if ($debit == 0 && $credit == 0) return null;
    return [
        'date'      => $date,
        'description' => $desc,
        'reference' => $ref,
        'amount'    => $debit > 0 ? $debit : $credit,
        'direction' => $debit > 0 ? 'OUT' : 'IN',
        'balance'   => $balance,
    ];
}

/**
 * Parse a ledger row.
 * Credit column = payment OUT (bank ledger credited) direction=OUT
 * Debit  column = receipt IN  (bank ledger debited)  direction=IN
 */
function parseLedgerRow(array $row): ?array {
    $date = parseDateStr(pick($row, ['date','transaction date','journal date','posting date','entry date','value date']));
    if (!$date) return null;
    $desc = pick($row, ['description','narration','details','particulars','remarks']);
    if ($desc === '') return null;
    $ref     = pick($row, ['reference','ref','folio','journal number','voucher']);
    $ledger  = pick($row, ['ledger','ledger name','account','account name','bank account']);
    $debit   = parseAmt(pick($row, ['debit','dr']));
    $credit  = parseAmt(pick($row, ['credit','cr']));
    $balance = parseAmt(pick($row, ['balance','running balance']));
    if ($debit == 0 && $credit == 0) return null;
    return [
        'date'        => $date,
        'description' => $desc,
        'reference'   => $ref,
        'ledger_name' => $ledger,
        'amount'      => $credit > 0 ? $credit : $debit,
        'direction'   => $credit > 0 ? 'OUT' : 'IN',
        'balance'     => $balance,
    ];
}

function textSim(string $a, string $b): float {
    $a = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $a));
    $b = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $b));
    similar_text($a, $b, $pct);
    return $pct;
}

function detectBankOnlyType(string $desc, string $dir): ?string {
    $t = strtolower($desc);
    if (preg_match('/nip charge|bank charge|sms|commission|maintenance fee|vat on.*maint|vat on.*fee|vat for.*charge|vat for.*handl/i', $t)) return 'Bank Charge';
    if (preg_match('/stamp duty|fgn stamp|ltr dd.*fgn|duty pyt/i', $t)) return 'Stamp Duty';
    if (preg_match('/wht|withhold|with.*tax/i', $t) && $dir === 'OUT') return 'WHT Remittance';
    if (preg_match('/interest|yield|credit interest/i', $t) && $dir === 'IN') return 'Bank Interest';
    if (preg_match('/rvsl|reversal/i', $t)) return 'Reversal';
    if (preg_match('/lc|letter of credit|discchg|avswfchg|paar charge|medufc|discch amt|shipping doc|doc handl/i', $t)) return 'LC/Trade Finance';
    return null;
}

/** PHP 7.4-safe: no match() expression */
function suggestLedgers(string $type): array {
    switch ($type) {
        case 'Bank Charge':       return ['dr' => 'Bank Charges & Commission', 'cr' => 'Bank Ledger'];
        case 'Bank Interest':     return ['dr' => 'Bank Ledger', 'cr' => 'Interest Income'];
        case 'Stamp Duty':        return ['dr' => 'Stamp Duty Expense', 'cr' => 'Bank Ledger'];
        case 'WHT Remittance':    return ['dr' => 'WHT Payable', 'cr' => 'Bank Ledger'];
        case 'LC/Trade Finance':  return ['dr' => 'LC/Trade Finance Charges', 'cr' => 'Bank Ledger'];
        case 'Reversal':          return ['dr' => 'Suspense', 'cr' => 'Suspense'];
        default:                  return ['dr' => 'Suspense', 'cr' => 'Bank Ledger'];
    }
}

function recomputeSummary(mysqli $conn, int $id): array {
    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    $ledgerUnmIn  = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_ledger_lines WHERE recon_id=$id AND match_status='Unmatched' AND direction='IN'")->fetch_assoc()['v'];
    $ledgerUnmOut = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_ledger_lines WHERE recon_id=$id AND match_status='Unmatched' AND direction='OUT'")->fetch_assoc()['v'];
    $bankOnlyIn   = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_bank_lines   WHERE recon_id=$id AND match_status='Bank-Only' AND direction='IN'")->fetch_assoc()['v'];
    $bankOnlyOut  = (float)$conn->query("SELECT COALESCE(SUM(amount),0) v FROM bank_recon_bank_lines   WHERE recon_id=$id AND match_status='Bank-Only' AND direction='OUT'")->fetch_assoc()['v'];
    $adjBank   = (float)$r['bank_closing'] + $ledgerUnmIn - $ledgerUnmOut + $bankOnlyIn - $bankOnlyOut;
    $adjLedger = (float)$r['ledger_closing'];
    $diff      = round($adjBank - $adjLedger, 2);
    $status    = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';
    $conn->query(sprintf("UPDATE bank_recons SET adjusted_bank_balance=%.2f, adjusted_ledger_balance=%.2f, unreconciled_difference=%.2f, status='%s' WHERE id=%d",
        round($adjBank,2), $adjLedger, $diff, $conn->real_escape_string($status), $id));
    return ['adjusted_bank_balance'=>round($adjBank,2), 'adjusted_ledger_balance'=>$adjLedger, 'unreconciled_difference'=>$diff, 'status'=>$status];
}

// ═══════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) brFail('Unauthorized', 401);
    $by = $user['email'] ?? $user['username'] ?? 'system';

    $companyName   = trim((string)($_POST['company_name']   ?? ''));
    $bankName      = trim((string)($_POST['bank_name']      ?? ''));
    $accountName   = trim((string)($_POST['account_name']   ?? ''));
    $accountNumber = trim((string)($_POST['account_number'] ?? ''));
    $currency      = strtoupper(trim((string)($_POST['currency'] ?? 'NGN'))) ?: 'NGN';
    $periodFrom    = parseDateStr((string)($_POST['period_from'] ?? ''));
    $periodTo      = parseDateStr((string)($_POST['period_to']   ?? ''));
    $bankOpening   = parseAmt((string)($_POST['bank_opening']   ?? '0'));
    $bankClosing   = parseAmt((string)($_POST['bank_closing']   ?? '0'));
    $ledgerOpening = parseAmt((string)($_POST['ledger_opening'] ?? '0'));
    $ledgerClosing = parseAmt((string)($_POST['ledger_closing'] ?? '0'));
    $tolDays       = max(0, min(30, (int)($_POST['tolerance_days']   ?? 7)));
    $tolAmt        = parseAmt((string)($_POST['tolerance_amount'] ?? '0'));
    $notes         = trim((string)($_POST['notes'] ?? ''));

    if (!$companyName) brFail('Company / Client Name is required.');
    if (!$periodFrom || !$periodTo) brFail('Period From and Period To are required.');
    if ($periodFrom > $periodTo) brFail('Period From must be on or before Period To.');
    if (!isset($_FILES['bank_file'])   || $_FILES['bank_file']['error']   !== UPLOAD_ERR_OK) brFail('Bank statement file is required (CSV or XLSX).');
    if (!isset($_FILES['ledger_file']) || $_FILES['ledger_file']['error'] !== UPLOAD_ERR_OK) brFail('Ledger statement file is required (CSV or XLSX).');

    $bankRows   = array_values(array_filter(array_map('parseBankRow',   readFile($_FILES['bank_file']['tmp_name'],   $_FILES['bank_file']['name']))));
    $ledgerRows = array_values(array_filter(array_map('parseLedgerRow', readFile($_FILES['ledger_file']['tmp_name'], $_FILES['ledger_file']['name']))));

    if (!$bankRows)   brFail('No valid transactions found in the bank file. Expected columns: Date, Description, Debit, Credit, Balance.');
    if (!$ledgerRows) brFail('No valid transactions found in the ledger file. Expected columns: Date, Description, Debit, Credit, Ledger.');

    $conn->begin_transaction();

    $reconNo = 'BR-' . date('Ymd-His') . '-' . random_int(100, 999);

    $stmt = $conn->prepare("INSERT INTO bank_recons (recon_number, company_name, bank_name, account_name, account_number, currency, period_from, period_to, bank_opening, bank_closing, ledger_opening, ledger_closing, tolerance_days, tolerance_amount, bank_file_name, ledger_file_name, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('ssssssssddddidssss',
        $reconNo, $companyName, $bankName, $accountName, $accountNumber,
        $currency, $periodFrom, $periodTo,
        $bankOpening, $bankClosing, $ledgerOpening, $ledgerClosing,
        $tolDays, $tolAmt,
        $_FILES['bank_file']['name'], $_FILES['ledger_file']['name'],
        $notes, $by
    );
    if (!$stmt->execute()) brFail('DB error (header): ' . $stmt->error, 500);
    $reconId = (int)$stmt->insert_id;
    $stmt->close();

    // Insert bank lines
    $ins = $conn->prepare("INSERT IGNORE INTO bank_recon_bank_lines (recon_id, txn_date, description, reference, amount, direction, running_balance, line_hash) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($bankRows as $r) {
        $hash = hash('sha256', "$reconId|bank|{$r['date']}|{$r['amount']}|{$r['direction']}|" . substr($r['description'], 0, 60));
        $bal  = (float)($r['balance'] ?? 0);
        $ins->bind_param('issssdds', $reconId, $r['date'], $r['description'], $r['reference'], $r['amount'], $r['direction'], $bal, $hash);
        $ins->execute();
    }
    $ins->close();

    // Insert ledger lines (now with balance)
    $ins2 = $conn->prepare("INSERT IGNORE INTO bank_recon_ledger_lines (recon_id, txn_date, description, reference, ledger_name, amount, direction, running_balance, line_hash) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($ledgerRows as $r) {
        $hash = hash('sha256', "$reconId|ledger|{$r['date']}|{$r['amount']}|{$r['direction']}|" . substr($r['description'], 0, 60));
        $bal  = (float)($r['balance'] ?? 0);
        $ins2->bind_param('isssssdds', $reconId, $r['date'], $r['description'], $r['reference'], $r['ledger_name'], $r['amount'], $r['direction'], $bal, $hash);
        $ins2->execute();
    }
    $ins2->close();

    $bankLines   = $conn->query("SELECT * FROM bank_recon_bank_lines   WHERE recon_id=$reconId ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);
    $ledgerLines = $conn->query("SELECT * FROM bank_recon_ledger_lines WHERE recon_id=$reconId ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);

    // Auto-match
    $usedLedger = [];
    $matchSeq   = 1;
    $autoCount  = 0;
    $mIns = $conn->prepare("INSERT INTO bank_recon_matches (recon_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, amount_difference, day_difference, matched_by) VALUES (?,?,?,?,'Auto',?,?,?,?)");

    foreach ($bankLines as $b) {
        $best = null; $bestScore = -1;
        foreach ($ledgerLines as $l) {
            if (isset($usedLedger[$l['id']])) continue;
            if ($l['direction'] !== $b['direction']) continue;
            $amtDiff = round(abs((float)$b['amount'] - (float)$l['amount']), 2);
            if ($amtDiff > max($tolAmt, 0.01)) continue;
            $dayDiff = (int)(abs(strtotime($b['txn_date']) - strtotime($l['txn_date'])) / 86400);
            if ($dayDiff > $tolDays) continue;
            $score = 50 + ($amtDiff < 0.02 ? 20 : 0) + max(0, 25 - $dayDiff * 5) + (int)round(textSim($b['description'], $l['description']) * 0.15);
            if ($score > $bestScore) { $bestScore = $score; $best = $l; }
        }
        if ($bestScore >= 65 && $best) {
            $mg  = 'AM-' . str_pad($matchSeq++, 4, '0', STR_PAD_LEFT) . '-' . $reconId;
            $mgE = $conn->real_escape_string($mg);
            $aD  = round(abs((float)$b['amount'] - (float)$best['amount']), 2);
            $dD  = (int)(abs(strtotime($b['txn_date']) - strtotime($best['txn_date'])) / 86400);
            $conf= min(100, $bestScore);
            $conn->query("UPDATE bank_recon_bank_lines   SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=" . (int)$b['id']);
            $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=" . (int)$best['id']);
            $mIns->bind_param('isiiiids', $reconId, $mg, $b['id'], $best['id'], $conf, $aD, $dD, $by);
            $mIns->execute();
            $usedLedger[$best['id']] = true;
            $autoCount++;
        }
    }
    $mIns->close();

    // Auto-classify obvious bank-only items
    $unmatched = $conn->query("SELECT * FROM bank_recon_bank_lines WHERE recon_id=$reconId AND match_status='Unmatched'")->fetch_all(MYSQLI_ASSOC);
    foreach ($unmatched as $b) {
        $type = detectBankOnlyType($b['description'], $b['direction']);
        if ($type) {
            $leds = suggestLedgers($type);
            $tE  = $conn->real_escape_string($type);
            $drE = $conn->real_escape_string($leds['dr']);
            $crE = $conn->real_escape_string($leds['cr']);
            $conn->query("UPDATE bank_recon_bank_lines SET match_status='Bank-Only', bank_only_type='$tE', suggested_dr_ledger='$drE', suggested_cr_ledger='$crE' WHERE id=" . (int)$b['id']);
        }
    }

    $summary = recomputeSummary($conn, $reconId);
    $conn->commit();

    http_response_code(201);
    echo json_encode([
        'status'  => 'Success',
        'message' => "Reconciliation created — $autoCount of " . count($bankLines) . " bank lines auto-matched.",
        'data'    => ['id' => $reconId, 'recon_number' => $reconNo, 'bank_count' => count($bankLines), 'ledger_count' => count($ledgerLines), 'auto_matched' => $autoCount, 'summary' => $summary],
    ]);

} catch (Exception $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    error_log('BR create error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}