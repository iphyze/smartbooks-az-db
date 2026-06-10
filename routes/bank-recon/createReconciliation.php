<?php
/**
 * POST /bank-recon/create
 *
 * Accepts multipart/form-data.
 * Bank file: CSV or XLSX (Date, Description, Debit, Credit, Balance)
 * Ledger file: CSV or XLSX (Date, Description, Debit, Credit, Ledger[, Balance])
 * Date columns may be ISO, dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, month-name dates, or Excel serial dates.
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
    // Preserve sign: accounting parentheses (value) → negative
    if (preg_match('/^\((.+)\)$/', $v, $m)) $v = '-' . $m[1];
    return round((float)$v, 2);
}

/**
 * Parse bank/ledger dates into YYYY-MM-DD without assuming one uploaded format.
 *
 * Supported examples:
 *   2026-01-05, 2026/01/05, 2026.01.05
 *   05/01/2026, 05-01-2026, 05.01.2026   (day-first default)
 *   01/05/2026, 01-05-2026               (ambiguous values are treated day-first)
 *   05 Jan 2026, Jan 05 2026, 5-Jan-26
 *   20260105, 05012026
 *   Excel serial dates from XLS/XLSX/CSV exports
 */
function parseDateStr(string $raw): ?string {
    $v = trim(str_replace(["\xc2\xa0", "\xef\xbb\xbf"], ' ', $raw));
    if ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'n/a') return null;

    // Remove ordinal suffixes: 1st Jan 2026 → 1 Jan 2026
    $v = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $v);
    // Remove common time suffixes while preserving the date portion.
    $datePart = preg_split('/\s+(?:\d{1,2}:\d{2}|00:00:00)/', $v)[0] ?? $v;
    $datePart = trim($datePart);

    // Excel serial date. CSV exports sometimes keep this as a numeric string.
    if (preg_match('/^\d+(?:\.\d+)?$/', $datePart)) {
        $serial = (float)$datePart;
        if ($serial >= 1 && $serial <= 60000) {
            try {
                $ts = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($serial);
                if ($ts) return date('Y-m-d', $ts);
            } catch (Throwable $e) {
                // Fall through to compact numeric date parsing below.
            }
        }
    }

    $makeDate = static function (int $year, int $month, int $day): ?string {
        if ($year < 100) $year += ($year >= 70 ? 1900 : 2000);
        if (!checkdate($month, $day, $year)) return null;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    };

    // ISO-ish: yyyy-mm-dd / yyyy/mm/dd / yyyy.mm.dd, optionally followed by time.
    if (preg_match('/^(\d{4})[\-\/\.](\d{1,2})[\-\/\.](\d{1,2})(?:[T\s].*)?$/', $datePart, $m)) {
        return $makeDate((int)$m[1], (int)$m[2], (int)$m[3]);
    }

    // Compact dates: yyyymmdd or ddmmyyyy. Try yyyymmdd first when it starts with 19/20.
    if (preg_match('/^\d{8}$/', $datePart)) {
        if (preg_match('/^(19|20)\d{6}$/', $datePart)) {
            $parsed = $makeDate((int)substr($datePart, 0, 4), (int)substr($datePart, 4, 2), (int)substr($datePart, 6, 2));
            if ($parsed) return $parsed;
        }
        $parsed = $makeDate((int)substr($datePart, 4, 4), (int)substr($datePart, 2, 2), (int)substr($datePart, 0, 2));
        if ($parsed) return $parsed;
    }

    // Numeric slash/dash/dot dates. Prefer day-first for ambiguous values because
    // uploaded Nigerian bank/ledger files commonly use dd/mm/yyyy. If one side is
    // above 12, infer the only valid order.
    if (preg_match('/^(\d{1,2})[\-\/\.](\d{1,2})[\-\/\.](\d{2,4})(?:\s.*)?$/', $datePart, $m)) {
        $first = (int)$m[1];
        $second = (int)$m[2];
        $year = (int)$m[3];

        if ($first > 12) {
            return $makeDate($year, $second, $first);       // dd/mm/yyyy
        }
        if ($second > 12) {
            return $makeDate($year, $first, $second);       // mm/dd/yyyy
        }

        // Ambiguous: 01/05/2026. Use day-first, then fall back to month-first.
        return $makeDate($year, $second, $first) ?: $makeDate($year, $first, $second);
    }

    // Month-name formats.
    $formats = [
        '!d M Y', '!d F Y', '!j M Y', '!j F Y',
        '!M d Y', '!F d Y', '!M j Y', '!F j Y',
        '!d-M-Y', '!d-F-Y', '!j-M-Y', '!j-F-Y',
        '!M-d-Y', '!F-d-Y', '!M-j-Y', '!F-j-Y',
        '!d M y', '!d F y', '!j M y', '!j F y',
        '!M d y', '!F d y', '!M j y', '!F j y',
        '!d-M-y', '!d-F-y', '!j-M-y', '!j-F-y',
        '!M-d-y', '!F-d-y', '!M-j-y', '!F-j-y',
    ];
    $normalisedNameDate = preg_replace('/[,]+/', ' ', preg_replace('/\s+/', ' ', $datePart));
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $normalisedNameDate);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt && (($errors === false) || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
            return $dt->format('Y-m-d');
        }
    }

    // Final fallback for stable English date strings like "January 5, 2026".
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
function readUploadedReconFile(string $path, string $origName): array {
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

    if (preg_match('/vat\s+(on|for).*?(charge|fee|maint|handling|handl|commission)|vat.*?(bank|nip|sms|commission|maintenance)/i', $t)) return 'VAT on Bank Charges';
    if (preg_match('/lc\s*commission|letter of credit commission|commission.*\blc\b/i', $t)) return 'LC Commission';
    if (preg_match('/lc|letter of credit|discchg|avswfchg|paar charge|medufc|discch amt|shipping doc|doc handl/i', $t)) return 'LC/Trade Finance';
    if (preg_match('/stamp duty|fgn stamp|ltr dd.*fgn|duty pyt/i', $t)) return 'Stamp Duty';
    if (preg_match('/wht|withhold|with.*tax/i', $t) && $dir === 'OUT') return 'WHT Remittance';
    if (preg_match('/interest|yield|credit interest/i', $t) && $dir === 'IN') return 'Bank Interest';
    if (preg_match('/rvsl|reversal/i', $t)) return 'Reversal';
    if (preg_match('/nip charge|bank charge|sms|commission|maintenance fee|monthly fee|account maintenance|card charge|transfer charge|transaction charge/i', $t)) return 'Bank Charge';
    return null;
}

/** PHP 7.4-safe: no match() expression */
function suggestLedgers(string $type): array {
    switch ($type) {
        case 'Bank Charge':       return ['dr' => 'Bank Charges & Commission', 'cr' => 'Bank Ledger'];
        case 'Bank Interest':     return ['dr' => 'Bank Ledger', 'cr' => 'Interest Income'];
        case 'VAT on Bank Charges': return ['dr' => 'Input VAT / VAT Receivable', 'cr' => 'Bank Ledger'];
        case 'LC Commission':     return ['dr' => 'LC Commission / Bank Charges', 'cr' => 'Bank Ledger'];
        case 'Stamp Duty':        return ['dr' => 'Stamp Duty Expense', 'cr' => 'Bank Ledger'];
        case 'WHT Remittance':    return ['dr' => 'WHT Payable', 'cr' => 'Bank Ledger'];
        case 'LC/Trade Finance':  return ['dr' => 'LC/Trade Finance Charges', 'cr' => 'Bank Ledger'];
        case 'Reversal':          return ['dr' => 'Suspense', 'cr' => 'Suspense'];
        default:                  return ['dr' => 'Suspense', 'cr' => 'Bank Ledger'];
    }
}

require_once 'routes/bank-recon/reconAutoClassification.php';

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
if (defined('BR_HELPERS_ONLY') && BR_HELPERS_ONLY) {
    return;
}

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

    $bankRows   = array_values(array_filter(array_map('parseBankRow',   readUploadedReconFile($_FILES['bank_file']['tmp_name'],   $_FILES['bank_file']['name']))));
    $ledgerRows = array_values(array_filter(array_map('parseLedgerRow', readUploadedReconFile($_FILES['ledger_file']['tmp_name'], $_FILES['ledger_file']['name']))));

    if (!$bankRows)   brFail('No valid transactions found in the bank file. Expected columns: Date, Description, Debit, Credit, Balance. Dates can be ISO, dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, month-name dates, or Excel serial dates.');
    if (!$ledgerRows) brFail('No valid transactions found in the ledger file. Expected columns: Date, Description, Debit, Credit, Ledger. Dates can be ISO, dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, month-name dates, or Excel serial dates.');

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
        $bal  = (float)($r['balance'] ?? 0);
        $hash = hash('sha256', "$reconId|bank|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
        $ins->bind_param('isssdsds', $reconId, $r['date'], $r['description'], $r['reference'], $r['amount'], $r['direction'], $bal, $hash);
        $ins->execute();
    }
    $ins->close();

    // Insert ledger lines (now with balance)
    $ins2 = $conn->prepare("INSERT IGNORE INTO bank_recon_ledger_lines (recon_id, txn_date, description, reference, ledger_name, amount, direction, running_balance, line_hash) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($ledgerRows as $r) {
        $bal  = (float)($r['balance'] ?? 0);
        $hash = hash('sha256', "$reconId|ledger|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
        $ins2->bind_param('issssdsds', $reconId, $r['date'], $r['description'], $r['reference'], $r['ledger_name'], $r['amount'], $r['direction'], $bal, $hash);
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
            // Same stored direction means proper accounting pair:
            // Bank OUT (Debit) ↔ Ledger OUT (Credit), Bank IN (Credit) ↔ Ledger IN (Debit).
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
            $mIns->bind_param('isiiidis', $reconId, $mg, $b['id'], $best['id'], $conf, $aD, $dD, $by);
            $mIns->execute();
            $usedLedger[$best['id']] = true;
            $autoCount++;
        }
    }
    $mIns->close();

    // Auto-categorise obvious bank-side exceptions such as Bank Charges,
    // Stamp Duty, WHT Remittance and Bank Interest. These are posted straight
    // into the correct reconciliation bucket, e.g. Bank OUT → They Debit We
    // Don't Credit and Bank IN → They Credit We Don't Debit.
    $autoClassified = brAutoApplyClassifications($conn, $reconId, 'bank');

    $summary = brAutoRecomputeSummary($conn, $reconId);
    $conn->commit();

    http_response_code(201);
    echo json_encode([
        'status'  => 'Success',
        'message' => "Reconciliation created — $autoCount of " . count($bankLines) . " bank lines auto-matched; $autoClassified bank-side item(s) auto-categorised.",
        'data'    => ['id' => $reconId, 'recon_number' => $reconNo, 'bank_count' => count($bankLines), 'ledger_count' => count($ledgerLines), 'auto_matched' => $autoCount, 'auto_classified' => $autoClassified, 'summary' => $summary],
    ]);

} catch (Exception $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    error_log('BR create error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}