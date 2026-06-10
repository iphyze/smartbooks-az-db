<?php
/**
 * POST /bank-recon/update
 *
 * Accepts multipart/form-data.
 * Required:  recon_id
 * Optional:  any header field (company_name, bank_name, …, notes)
 *            bank_file   — new XLSX/CSV; triggers full re-processing of bank lines
 *            ledger_file — new XLSX/CSV; triggers full re-processing of ledger lines
 * Uploaded statement dates may be ISO, dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, month-name dates, or Excel serial dates.
 *
 * When a file is supplied, existing lines for that side are replaced, but
 * already-classified/categorised lines are restored when the same transaction
 * appears in the re-uploaded file. Auto-matching is then re-run.
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';




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

header('Content-Type: application/json');

function updateFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') updateFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) updateFail('Unauthorized', 401);
    $by = $user['email'] ?? $user['username'] ?? 'system';

    // Accept both JSON and multipart
    $body = [];
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $raw = json_decode(file_get_contents('php://input'), true);
        $body = is_array($raw) ? $raw : [];
    } else {
        $body = $_POST;
    }

    $id = (int)($body['recon_id'] ?? 0);
    if (!$id) updateFail('recon_id is required.');

    $recon = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$recon) updateFail('Reconciliation not found.', 404);

    // ── Header fields (fall back to existing) ──────────────────────────
    $companyName   = trim((string)($body['company_name']   ?? $recon['company_name']));
    $bankName      = trim((string)($body['bank_name']      ?? $recon['bank_name']));
    $accountName   = trim((string)($body['account_name']   ?? $recon['account_name']));
    $accountNumber = trim((string)($body['account_number'] ?? $recon['account_number']));
    $currency      = strtoupper(trim((string)($body['currency'] ?? $recon['currency']))) ?: 'NGN';
    $periodFrom    = parseDateStr((string)($body['period_from'] ?? $recon['period_from'])) ?? $recon['period_from'];
    $periodTo      = parseDateStr((string)($body['period_to']   ?? $recon['period_to']))   ?? $recon['period_to'];
    $bankOpening   = array_key_exists('bank_opening',   $body) ? parseAmt((string)$body['bank_opening'])   : (float)$recon['bank_opening'];
    $bankClosing   = array_key_exists('bank_closing',   $body) ? parseAmt((string)$body['bank_closing'])   : (float)$recon['bank_closing'];
    $ledgerOpening = array_key_exists('ledger_opening', $body) ? parseAmt((string)$body['ledger_opening']) : (float)$recon['ledger_opening'];
    $ledgerClosing = array_key_exists('ledger_closing', $body) ? parseAmt((string)$body['ledger_closing']) : (float)$recon['ledger_closing'];
    $tolDays       = array_key_exists('tolerance_days',   $body) ? max(0, min(30, (int)$body['tolerance_days']))   : (int)$recon['tolerance_days'];
    $tolAmt        = array_key_exists('tolerance_amount', $body) ? parseAmt((string)$body['tolerance_amount'])     : (float)$recon['tolerance_amount'];
    $notes         = trim((string)($body['notes'] ?? $recon['notes']));

    if (!$companyName) updateFail('Company / Client Name is required.');
    if ($periodFrom > $periodTo) updateFail('Period From must be on or before Period To.');

    // ── Determine if files were supplied ───────────────────────────────
    $hasBankFile   = isset($_FILES['bank_file'])   && $_FILES['bank_file']['error']   === UPLOAD_ERR_OK;
    $hasLedgerFile = isset($_FILES['ledger_file']) && $_FILES['ledger_file']['error'] !== UPLOAD_ERR_NO_FILE && $_FILES['ledger_file']['error'] === UPLOAD_ERR_OK;

    $bankFileName   = $hasBankFile   ? $_FILES['bank_file']['name']   : $recon['bank_file_name'];
    $ledgerFileName = $hasLedgerFile ? $_FILES['ledger_file']['name'] : $recon['ledger_file_name'];

    $conn->begin_transaction();

    // Preserve manual/previous classifications before any file-side replacement.
    // Re-uploading a corrected bank/ledger file should not wipe Bank Charge,
    // Bank Interest or other classifications already reviewed by the user.
    $preservedBankClassifications = $hasBankFile ? brAutoCaptureClassifications($conn, $id, 'bank') : [];
    $preservedLedgerClassifications = $hasLedgerFile ? brAutoCaptureClassifications($conn, $id, 'ledger') : [];

    if ($hasBankFile || $hasLedgerFile) {
        // Any statement replacement invalidates old match links. Existing
        // classifications remain protected/restored, while matched timing items
        // are released for fresh auto-matching against the new upload.
        $conn->query("DELETE FROM bank_recon_matches WHERE recon_id=$id");
        $conn->query("UPDATE bank_recon_bank_lines SET match_status='Unmatched', match_group=NULL, auto_matched=0 WHERE recon_id=$id AND match_status='Matched'");
        $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Unmatched', match_group=NULL, auto_matched=0 WHERE recon_id=$id AND match_status='Matched'");
    }

    $restoredBankClassifications = 0;
    $restoredLedgerClassifications = 0;
    $autoClassified = 0;

    // ── Update header ──────────────────────────────────────────────────
    $stmt = $conn->prepare("UPDATE bank_recons SET
        company_name=?, bank_name=?, account_name=?, account_number=?, currency=?,
        period_from=?, period_to=?,
        bank_opening=?, bank_closing=?, ledger_opening=?, ledger_closing=?,
        tolerance_days=?, tolerance_amount=?,
        bank_file_name=?, ledger_file_name=?,
        notes=?, updated_by=?
        WHERE id=?");
    if (!$stmt) updateFail('Prepare failed: ' . $conn->error, 500);
    $stmt->bind_param('sssssssddddidssssi',
        $companyName, $bankName, $accountName, $accountNumber, $currency,
        $periodFrom, $periodTo,
        $bankOpening, $bankClosing, $ledgerOpening, $ledgerClosing,
        $tolDays, $tolAmt,
        $bankFileName, $ledgerFileName,
        $notes, $by, $id
    );
    if (!$stmt->execute()) updateFail('Header update failed: ' . $stmt->error, 500);
    $stmt->close();

    // ── Re-process bank file if supplied ──────────────────────────────
    if ($hasBankFile) {
        $bankRows = array_values(array_filter(array_map('parseBankRow',
            readUploadedReconFile($_FILES['bank_file']['tmp_name'], $_FILES['bank_file']['name']))));
        if (!$bankRows) updateFail('No valid transactions found in the new bank file. Dates can be ISO, dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, month-name dates, or Excel serial dates.', 422);

        // Replace bank lines after preserving any existing classifications
        $conn->query("DELETE FROM bank_recon_bank_lines WHERE recon_id=$id");

        $ins = $conn->prepare("INSERT IGNORE INTO bank_recon_bank_lines
            (recon_id, txn_date, description, reference, amount, direction, running_balance, line_hash)
            VALUES (?,?,?,?,?,?,?,?)");
        foreach ($bankRows as $r) {
            $bal  = (float)($r['balance'] ?? 0);
            $hash = hash('sha256', "$id|bank|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
            $ins->bind_param('isssdsds', $id, $r['date'], $r['description'], $r['reference'], $r['amount'], $r['direction'], $bal, $hash);
            $ins->execute();
        }
        $ins->close();
        $restoredBankClassifications = brAutoRestoreClassifications($conn, $id, 'bank', $preservedBankClassifications);
    }

    // ── Re-process ledger file if supplied ────────────────────────────
    if ($hasLedgerFile) {
        $ledgerRows = array_values(array_filter(array_map('parseLedgerRow',
            readUploadedReconFile($_FILES['ledger_file']['tmp_name'], $_FILES['ledger_file']['name']))));
        if (!$ledgerRows) updateFail('No valid transactions found in the new ledger file. Dates can be ISO, dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, month-name dates, or Excel serial dates.', 422);

        // Replace ledger lines after preserving any existing classifications
        $conn->query("DELETE FROM bank_recon_ledger_lines WHERE recon_id=$id");

        $ins2 = $conn->prepare("INSERT IGNORE INTO bank_recon_ledger_lines
            (recon_id, txn_date, description, reference, ledger_name, amount, direction, running_balance, line_hash)
            VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($ledgerRows as $r) {
            $bal  = (float)($r['balance'] ?? 0);
            $hash = hash('sha256', "$id|ledger|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
            $ins2->bind_param('issssdsds', $id, $r['date'], $r['description'], $r['reference'], $r['ledger_name'], $r['amount'], $r['direction'], $bal, $hash);
            $ins2->execute();
        }
        $ins2->close();
        $restoredLedgerClassifications = brAutoRestoreClassifications($conn, $id, 'ledger', $preservedLedgerClassifications);
    }

    // ── Re-run auto-match if either file was replaced ─────────────────
    if ($hasBankFile || $hasLedgerFile) {
        $bankLines   = $conn->query("SELECT * FROM bank_recon_bank_lines   WHERE recon_id=$id AND match_status='Unmatched' ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);
        $ledgerLines = $conn->query("SELECT * FROM bank_recon_ledger_lines WHERE recon_id=$id AND match_status='Unmatched' ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);

        $usedLedger = [];
        $matchSeq   = 1;
        $mIns = $conn->prepare("INSERT INTO bank_recon_matches
            (recon_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, amount_difference, day_difference, matched_by)
            VALUES (?,?,?,?,'Auto',?,?,?,?)");

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
                $mg  = 'AM-' . str_pad($matchSeq++, 4, '0', STR_PAD_LEFT) . '-' . $id;
                $mgE = $conn->real_escape_string($mg);
                $aD  = round(abs((float)$b['amount'] - (float)$best['amount']), 2);
                $dD  = (int)(abs(strtotime($b['txn_date']) - strtotime($best['txn_date'])) / 86400);
                $conf= min(100, $bestScore);
                $conn->query("UPDATE bank_recon_bank_lines   SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=" . (int)$b['id']);
                $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=" . (int)$best['id']);
                $byE = $conn->real_escape_string($by);
                $mIns->bind_param('isiiidis', $id, $mg, $b['id'], $best['id'], $conf, $aD, $dD, $byE);
                $mIns->execute();
                $usedLedger[$best['id']] = true;
            }
        }
        $mIns->close();

        // Auto-categorise newly-uploaded bank-side exceptions, but only
        // after preserved user classifications and fresh auto-matches have been
        // applied. This prevents re-upload from overwriting earlier work.
        $autoClassified = brAutoApplyClassifications($conn, $id, 'bank');
    }

    $summary = brAutoRecomputeSummary($conn, $id);
    $conn->commit();

    $fileMsg = ($hasBankFile || $hasLedgerFile) ? ' Statements re-processed, preserved classifications restored, and auto-categorisation applied.' : '';
    echo json_encode([
        'status'  => 'Success',
        'message' => 'Reconciliation updated successfully.' . $fileMsg,
        'data'    => [
            'reconciliation' => $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc(),
            'summary' => $summary,
            'restored_bank_classifications' => $restoredBankClassifications,
            'restored_ledger_classifications' => $restoredLedgerClassifications,
            'auto_classified' => $autoClassified,
        ],
    ]);

} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}