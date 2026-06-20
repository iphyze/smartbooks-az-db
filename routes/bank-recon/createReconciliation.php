<?php

declare(strict_types=1);
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

require_once __DIR__ . '/../../vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json');

function brJson(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function brFail(string $msg, int $code = 400): void {
    throw new Exception($msg, $code);
}

function brPrepare(mysqli $conn, string $sql): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) brFail('Failed to prepare query: ' . $conn->error, 500);
    return $stmt;
}

function brQuery(mysqli $conn, string $sql): mysqli_result {
    $result = $conn->query($sql);
    if ($result === false || $result === true) brFail('Database query failed: ' . $conn->error, 500);
    return $result;
}

function brExec(mysqli $conn, string $sql): void {
    if (!$conn->query($sql)) brFail('Database command failed: ' . $conn->error, 500);
}

function brFetchAll(mysqli $conn, string $sql): array {
    $result = brQuery($conn, $sql);
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows ?: [];
}

function brFetchOne(mysqli $conn, string $sql): ?array {
    $result = brQuery($conn, $sql);
    $row = $result->fetch_assoc();
    $result->free();
    return $row ?: null;
}

/** Strip currency symbols, commas, parentheses; return absolute float.
 * Handles uploaded exports where money appears as 3,560,000.00 or 3560000.
 */
function parseAmt(string $raw): float {
    $v = trim((string)$raw);
    if ($v === '' || strtolower($v) === 'null') return 0.0;
    $v = trim($v, "\"'");
    $v = str_replace(["\xc2\xa0", "\xE2\x80\xAF"], ' ', $v);
    $v = preg_replace('/\s+/u', '', $v);
    $negative = false;
    if (preg_match('/^\((.+)\)$/', $v, $m)) {
        $negative = true;
        $v = $m[1];
    }
    if (strpos($v, '-') !== false) $negative = true;
    $v = str_replace([',', '₦', 'NGN', 'N', '$', '£', '€', '+', '-'], '', $v);
    $v = preg_replace('/[^0-9.]/', '', $v);
    if (substr_count($v, '.') > 1) {
        $firstDot = strpos($v, '.');
        $v = substr($v, 0, $firstDot + 1) . str_replace('.', '', substr($v, $firstDot + 1));
    }
    $amount = round((float)$v, 2);
    return $negative ? -abs($amount) : $amount;
}

function normaliseReconYear(string $year): int {
    $year = trim($year);
    if (strlen($year) === 2) {
        $n = (int)$year;
        return $n >= 70 ? 1900 + $n : 2000 + $n;
    }
    return (int)$year;
}

function validReconDateParts(int $year, int $month, int $day): ?string {
    if ($year < 1900 || $year > 2200 || !checkdate($month, $day, $year)) return null;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** Parse common uploaded date values into YYYY-MM-DD.
 * Supports dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, mm-dd-yyyy, yyyy-mm-dd,
 * Excel serial dates and common textual date formats. If slash/dash dates are
 * ambiguous, the business default is dd/mm/yyyy.
 */
function parseDateStr(string $raw): ?string {
    $v = trim((string)$raw);
    if ($v === '') return null;
    $v = trim($v, "\"'");
    $v = preg_replace('/\s+/', ' ', $v);

    // Excel numeric date serial.
    if (is_numeric($v) && (float)$v > 25000 && (float)$v < 90000) {
        $ts = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    // ISO-like dates: yyyy-mm-dd, yyyy/mm/dd, yyyy.mm.dd.
    if (preg_match('/^(\d{4})[\/-\.](\d{1,2})[\/-\.](\d{1,2})$/', $v, $m)) {
        return validReconDateParts((int)$m[1], (int)$m[2], (int)$m[3]);
    }

    // dd/mm/yyyy, mm/dd/yyyy, dd-mm-yyyy, mm-dd-yyyy, with 2 or 4 digit years.
    if (preg_match('/^(\d{1,2})[\/-\.](\d{1,2})[\/-\.](\d{2,4})$/', $v, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        $year = normaliseReconYear($m[3]);

        // If one side is above 12 the format is obvious. When both are <= 12,
        // prefer Nigerian/accounting convention: dd/mm/yyyy.
        if ($a > 12) {
            return validReconDateParts($year, $b, $a);
        }
        if ($b > 12) {
            return validReconDateParts($year, $a, $b);
        }
        return validReconDateParts($year, $b, $a);
    }

    foreach (['!d M Y', '!d F Y', '!M d Y', '!F d Y', '!d-M-Y', '!d-M-y', '!M-d-Y', '!M-d-y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $v);
        $errors = DateTime::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
        if ($dt instanceof DateTime && !$hasErrors) return $dt->format('Y-m-d');
    }

    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Normalise a CSV/header cell */
function normHdr(string $h): string {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', ' ', $h)));
}

function isReconAmountHeader(string $h): bool {
    return (bool)preg_match('/^(debit|credit|dr|cr)$|amount|withdrawal|deposit|money out|money in|balance/', $h);
}

function isReconAmountStart(string $value): bool {
    $v = trim($value);
    return (bool)preg_match('/^\(?[-+]?\D*\d{1,3}$/', $v) || (bool)preg_match('/^\(?[-+]?\D*\d{1,3},/', $v);
}

function isReconAmountContinuation(string $value): bool {
    $v = trim($value);
    return (bool)preg_match('/^\d{3}(?:\.\d+)?\)?$/', $v);
}

/**
 * Some CSV exports contain unquoted thousand-separated amounts, e.g.
 * 3,560,000.00. fgetcsv splits those into multiple cells. This repair joins
 * numeric amount fragments back together for amount-like columns.
 */
function repairReconCsvCells(array $headers, array $cells): array {
    $cells = array_map(fn($c) => trim((string)$c), $cells);
    $hCount = count($headers);
    if (count($cells) <= $hCount) return $cells;

    $fixed = [];
    $ci = 0;
    $cCount = count($cells);
    for ($hi = 0; $hi < $hCount; $hi++) {
        $header = $headers[$hi] ?? '';
        $remainingHeaders = $hCount - $hi - 1;
        if ($ci >= $cCount) { $fixed[] = ''; continue; }

        if ($hi === $hCount - 1) {
            $fixed[] = implode(',', array_slice($cells, $ci));
            break;
        }

        $value = $cells[$ci++];
        if (isReconAmountHeader($header) && $value !== '' && isReconAmountStart($value)) {
            while ($ci < $cCount && ($cCount - $ci) > $remainingHeaders && isReconAmountContinuation($cells[$ci])) {
                $value .= ',' . $cells[$ci++];
            }
        }
        $fixed[] = $value;
    }
    return $fixed;
}

/**
 * Read any file (CSV or XLSX) into array of associative rows.
 * Returns rows keyed by normalised headers.
 */
function readUploadedReconFile(string $path, string $origName): array {
    $meta = readUploadedReconFileWithMeta($path, $origName);
    return $meta['rows'];
}

/**
 * Read any upload and keep its header metadata even when there are no
 * transaction rows. This lets heading-only bank/ledger files be treated as
 * valid no-movement uploads instead of parser failures.
 */
function readUploadedReconFileWithMeta(string $path, string $origName): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'xls') {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx')) {
            brFail('XLS/XLSX uploads require PhpSpreadsheet. Run composer require phpoffice/phpspreadsheet on the backend, or upload CSV files.', 500);
        }
        return readXlsxWithMeta($path, $ext);
    }
    return readCsvWithMeta($path);
}

function emptyReconUploadMeta(): array {
    return ['headers' => [], 'original_headers' => [], 'rows' => []];
}

function readCsvWithMeta(string $path): array {
    $meta = emptyReconUploadMeta();
    if (!($fh = fopen($path, 'r'))) return $meta;
    $bom = fread($fh, 3);
    if ($bom !== "\xef\xbb\xbf") rewind($fh);
    $headers = null;
    while (($cells = fgetcsv($fh, 0, ',')) !== false) {
        if ($cells === [null]) continue;
        if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) continue;
        if ($headers === null) {
            $meta['original_headers'] = array_map(fn($c) => trim((string)$c), $cells);
            $headers = array_map('normHdr', $cells);
            $meta['headers'] = $headers;
            continue;
        }
        $cells = repairReconCsvCells($headers, $cells);
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
        $meta['rows'][] = $row;
    }
    fclose($fh);
    return $meta;
}

function readCsv(string $path): array {
    $meta = readCsvWithMeta($path);
    return $meta['rows'];
}

function readXlsxWithMeta(string $path, string $ext): array {
    $reader = $ext === 'xls'
        ? new \PhpOffice\PhpSpreadsheet\Reader\Xls()
        : new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $reader->setReadDataOnly(true);
    $ss      = $reader->load($path);
    $ws      = $ss->getActiveSheet();
    $meta    = emptyReconUploadMeta();
    $headers = null;
    foreach ($ws->getRowIterator() as $row) {
        $cells = [];
        foreach ($row->getCellIterator() as $cell) {
            $v = $cell->getValue();
            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($v)) {
                $v = date('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($v));
            }
            $cells[] = $v !== null ? trim((string)$v) : '';
        }
        if (array_filter($cells, fn($c) => $c !== '') === []) continue;
        if ($headers === null) {
            $meta['original_headers'] = array_map(fn($c) => trim((string)$c), $cells);
            $headers = array_map('normHdr', $cells);
            $meta['headers'] = $headers;
            continue;
        }
        $row2 = [];
        foreach ($headers as $i => $h) $row2[$h] = $cells[$i] ?? '';
        $meta['rows'][] = $row2;
    }
    return $meta;
}

function readXlsx(string $path, string $ext): array {
    $meta = readXlsxWithMeta($path, $ext);
    return $meta['rows'];
}

function reconHeaderHasAny(array $headers, array $candidates): bool {
    $headers = array_values(array_filter(array_map('normHdr', $headers), fn($h) => $h !== ''));
    foreach ($headers as $header) {
        foreach ($candidates as $candidate) {
            $candidate = normHdr($candidate);
            if ($candidate !== '' && ($header === $candidate || strpos($header, $candidate) !== false)) return true;
        }
    }
    return false;
}

function validateReconUploadMeta(array $meta, string $source, string $label): void {
    $headers = $meta['headers'] ?? [];
    if (!reconHeaderHasAny($headers, ['date','transaction date','journal date','posting date','value date','txn date','create date','entry date','effective date'])) {
        brFail("No valid header row found in the {$label}. Expected a date column and normal reconciliation headings.");
    }
    if (!reconHeaderHasAny($headers, ['description','description payee memo','description/payee/memo','narration','details','remarks','particulars'])) {
        brFail("No valid description/narration column found in the {$label}.");
    }
    if ($source === 'ledger') {
        if (!reconHeaderHasAny($headers, ['debit','dr']) || !reconHeaderHasAny($headers, ['credit','cr'])) {
            brFail("No valid debit/credit columns found in the {$label}. Expected columns such as Date, Description, Debit, Credit, Ledger.");
        }
    } else {
        if (!reconHeaderHasAny($headers, ['debit','debit amount','withdrawal','dr','money out']) || !reconHeaderHasAny($headers, ['credit','credit amount','deposit','cr','money in'])) {
            brFail("No valid debit/credit columns found in the {$label}. Expected columns such as Date, Description, Debit, Credit, Balance.");
        }
    }
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
        'amount'    => abs($debit) > 0 ? abs($debit) : abs($credit),
        'direction' => abs($debit) > 0 ? 'OUT' : 'IN',
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
        'amount'      => abs($credit) > 0 ? abs($credit) : abs($debit),
        'direction'   => abs($credit) > 0 ? 'OUT' : 'IN',
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

require_once __DIR__ . '/reconMatchingHelpers.php';
require_once __DIR__ . '/reconAutoClassification.php';

function reconSum(mysqli $conn, int $id, string $sql): float {
    $row = brFetchOne($conn, $sql);
    return (float)($row['v'] ?? 0);
}

function recomputeSummary(mysqli $conn, int $id): array {
    $r = brFetchOne($conn, "SELECT * FROM bank_recons WHERE id=$id LIMIT 1");
    if (!$r) brFail('Reconciliation not found while recomputing summary.', 404);

    $ledgerUnmIn  = reconSum($conn, $id, "SELECT COALESCE(SUM(amount),0) v FROM bank_recon_ledger_lines WHERE recon_id=$id AND match_status='Unmatched' AND direction='IN'");
    $ledgerUnmOut = reconSum($conn, $id, "SELECT COALESCE(SUM(amount),0) v FROM bank_recon_ledger_lines WHERE recon_id=$id AND match_status='Unmatched' AND direction='OUT'");
    $bankOnlyIn   = reconSum($conn, $id, "SELECT COALESCE(SUM(amount),0) v FROM bank_recon_bank_lines   WHERE recon_id=$id AND match_status='Bank-Only' AND direction='IN'");
    $bankOnlyOut  = reconSum($conn, $id, "SELECT COALESCE(SUM(amount),0) v FROM bank_recon_bank_lines   WHERE recon_id=$id AND match_status='Bank-Only' AND direction='OUT'");

    $adjBank   = (float)$r['bank_closing'] + $ledgerUnmIn - $ledgerUnmOut + $bankOnlyIn - $bankOnlyOut;
    $adjLedger = (float)$r['ledger_closing'];
    $diff      = round($adjBank - $adjLedger, 2);
    $status    = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';

    brExec($conn, sprintf("UPDATE bank_recons SET adjusted_bank_balance=%.2f, adjusted_ledger_balance=%.2f, unreconciled_difference=%.2f, status='%s' WHERE id=%d",
        round($adjBank,2), $adjLedger, $diff, $conn->real_escape_string($status), $id));

    return ['adjusted_bank_balance'=>round($adjBank,2), 'adjusted_ledger_balance'=>$adjLedger, 'unreconciled_difference'=>$diff, 'status'=>$status];
}



/** Read JSON body only as a compatibility fallback for non-file metadata requests. */
function reconJsonBody(): array {
    static $json = null;
    if ($json !== null) return $json;
    $json = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) $json = $decoded;
    }
    return $json;
}

/** Get a submitted field while accepting legacy/reference aliases. */
function reconField(array $keys, string $default = ''): string {
    $json = reconJsonBody();
    foreach ($keys as $key) {
        if (isset($_POST[$key]) && trim((string)$_POST[$key]) !== '') return (string)$_POST[$key];
        if (isset($json[$key]) && trim((string)$json[$key]) !== '') return (string)$json[$key];
    }
    return $default;
}

// ═══════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════
// appendLines.php imports only the parsing/summary helpers from this route.
if (!defined('BR_HELPERS_ONLY')) {
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);
    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';

    $companyName   = trim(reconField(['company_name', 'company', 'companyName', 'client_name', 'clientName', 'recon_company']));
    $bankName      = trim(reconField(['bank_name', 'bankName', 'bank', 'recon_bank']));
    $accountName   = trim(reconField(['account_name', 'accountName', 'acct_name', 'acctName', 'recon_account_name']));
    $accountNumber = trim(reconField(['account_number', 'accountNumber', 'acct_no', 'acctNo', 'recon_account_number']));
    $currency      = strtoupper(trim(reconField(['currency'], 'NGN'))) ?: 'NGN';
    $periodFrom    = parseDateStr(reconField(['period_from', 'periodFrom', 'from_date', 'fromDate']));
    $periodTo      = parseDateStr(reconField(['period_to', 'periodTo', 'to_date', 'toDate']));
    $bankOpening   = parseAmt(reconField(['bank_opening', 'bankOpening'], '0'));
    $bankClosing   = parseAmt(reconField(['bank_closing', 'bankClosing'], '0'));
    $ledgerOpening = parseAmt(reconField(['ledger_opening', 'ledgerOpening'], '0'));
    $ledgerClosing = parseAmt(reconField(['ledger_closing', 'ledgerClosing'], '0'));
    $tolDays       = max(0, min(30, (int)reconField(['tolerance_days', 'toleranceDays', 'date_tolerance', 'dateTolerance'], '7')));
    $tolAmt        = parseAmt(reconField(['tolerance_amount', 'toleranceAmount', 'amount_tolerance', 'amountTolerance'], '0'));
    $notes         = trim(reconField(['notes', 'note'], ''));

    if (!$companyName) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'multipart/form-data') === false && empty($_POST)) {
            brFail('Company / Client Name is required. Ensure reconciliation uploads are sent as multipart/form-data, not JSON.');
        }
        brFail('Company / Client Name is required.');
    }
    if (!$periodFrom || !$periodTo) brFail('Period From and Period To are required.');
    if ($periodFrom > $periodTo) brFail('Period From must be on or before Period To.');
    if (!isset($_FILES['bank_file'])   || $_FILES['bank_file']['error']   !== UPLOAD_ERR_OK) brFail('Bank statement file is required (CSV or XLSX).');
    if (!isset($_FILES['ledger_file']) || $_FILES['ledger_file']['error'] !== UPLOAD_ERR_OK) brFail('Ledger statement file is required (CSV or XLSX).');

    $bankMeta   = readUploadedReconFileWithMeta($_FILES['bank_file']['tmp_name'],   $_FILES['bank_file']['name']);
    $ledgerMeta = readUploadedReconFileWithMeta($_FILES['ledger_file']['tmp_name'], $_FILES['ledger_file']['name']);
    validateReconUploadMeta($bankMeta, 'bank', 'bank file');
    validateReconUploadMeta($ledgerMeta, 'ledger', 'ledger file');

    $bankRows   = array_values(array_filter(array_map('parseBankRow',   $bankMeta['rows'])));
    $ledgerRows = array_values(array_filter(array_map('parseLedgerRow', $ledgerMeta['rows'])));

    if (function_exists('brReconEnsureSmartSchema')) brReconEnsureSmartSchema($conn);

    $conn->begin_transaction();

    $reconNo = 'BR-' . date('Ymd-His') . '-' . random_int(100, 999);

    $stmt = brPrepare($conn, "INSERT INTO bank_recons (recon_number, company_name, bank_name, account_name, account_number, currency, period_from, period_to, bank_opening, bank_closing, ledger_opening, ledger_closing, tolerance_days, tolerance_amount, bank_file_name, ledger_file_name, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
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

    if ($reconId <= 0) {
        $lookupNo = $conn->real_escape_string($reconNo);
        $found = brFetchOne($conn, "SELECT id FROM bank_recons WHERE recon_number='" . $lookupNo . "' LIMIT 1");
        if ($found) {
            $reconId = (int)$found['id'];
        }
    }
    if ($reconId <= 0) brFail('Reconciliation header was created, but the new reconciliation ID could not be resolved.', 500);

    if (function_exists('brReconRememberUploadProfileFromHeaders')) {
        brReconRememberUploadProfileFromHeaders($conn, $reconId, 'bank', $bankMeta['original_headers'] ?: $bankMeta['headers'], $_FILES['bank_file']['name'], $by);
        brReconRememberUploadProfileFromHeaders($conn, $reconId, 'ledger', $ledgerMeta['original_headers'] ?: $ledgerMeta['headers'], $_FILES['ledger_file']['name'], $by);
    } elseif (function_exists('brReconRememberUploadProfile')) {
        brReconRememberUploadProfile($conn, $reconId, 'bank', $bankMeta['rows'], $_FILES['bank_file']['name'], $by);
        brReconRememberUploadProfile($conn, $reconId, 'ledger', $ledgerMeta['rows'], $_FILES['ledger_file']['name'], $by);
    }

    // Insert bank lines
    $ins = brPrepare($conn, "INSERT IGNORE INTO bank_recon_bank_lines (recon_id, txn_date, description, reference, amount, direction, running_balance, line_hash) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($bankRows as $r) {
        $bal  = (float)($r['balance'] ?? 0);
        $hash = hash('sha256', "$reconId|bank|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
        $ins->bind_param('isssdsds', $reconId, $r['date'], $r['description'], $r['reference'], $r['amount'], $r['direction'], $bal, $hash);
        if (!$ins->execute()) brFail('DB error (bank line): ' . $ins->error, 500);
    }
    $ins->close();

    // Insert ledger lines (now with balance)
    $ins2 = brPrepare($conn, "INSERT IGNORE INTO bank_recon_ledger_lines (recon_id, txn_date, description, reference, ledger_name, amount, direction, running_balance, line_hash) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($ledgerRows as $r) {
        $bal  = (float)($r['balance'] ?? 0);
        $hash = hash('sha256', "$reconId|ledger|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
        $ins2->bind_param('issssdsds', $reconId, $r['date'], $r['description'], $r['reference'], $r['ledger_name'], $r['amount'], $r['direction'], $bal, $hash);
        if (!$ins2->execute()) brFail('DB error (ledger line): ' . $ins2->error, 500);
    }
    $ins2->close();

    $bankLines   = brFetchAll($conn, "SELECT * FROM bank_recon_bank_lines   WHERE recon_id=$reconId ORDER BY txn_date, id");
    $ledgerLines = brFetchAll($conn, "SELECT * FROM bank_recon_ledger_lines WHERE recon_id=$reconId ORDER BY txn_date, id");

    // Heading-only/no-movement uploads are valid. When a file contains only
    // recognised headers and no transaction rows, keep the reconciliation
    // header and let the balance comparison determine the status.

    // Auto-match
    $usedLedger = [];
    $matchSeq   = 1;
    $autoCount  = 0;
    $mIns = brPrepare($conn, "INSERT INTO bank_recon_matches (recon_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, amount_difference, day_difference, matched_by) VALUES (?,?,?,?,'Auto',?,?,?,?)");

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
            $mg  = 'AM-' . str_pad((string) $matchSeq++, 4, '0', STR_PAD_LEFT) . '-' . $reconId;
            $mgE = $conn->real_escape_string($mg);
            $aD  = round(abs((float)$b['amount'] - (float)$best['amount']), 2);
            $dD  = (int)(abs(strtotime($b['txn_date']) - strtotime($best['txn_date'])) / 86400);
            $conf= min(100, $bestScore);
            brExec($conn, "UPDATE bank_recon_bank_lines   SET match_status='Matched', match_group='$mgE', auto_matched=1, matched_amount=ABS(amount) WHERE id=" . (int)$b['id']);
            brExec($conn, "UPDATE bank_recon_ledger_lines SET match_status='Matched', match_group='$mgE', auto_matched=1, matched_amount=ABS(amount) WHERE id=" . (int)$best['id']);
            $mIns->bind_param('isiiidis', $reconId, $mg, $b['id'], $best['id'], $conf, $aD, $dD, $by);
            if (!$mIns->execute()) brFail('DB error (auto-match): ' . $mIns->error, 500);
            $usedLedger[$best['id']] = true;
            $autoCount++;
        }
    }
    $mIns->close();

    // Auto-categorise obvious bank-side exceptions such as Bank Charges,
    // VAT on Bank Charges, LC Commission and Bank Interest into the same
    // reconciliation classifications used by Smartbooks.
    $autoClassified = brAutoApplyClassifications($conn, $reconId, 'bank');

    // If the ledger already contains a summary posting for a classified
    // category, auto-match the posting against the retained category schedule.
    // This supports cases such as several bank-charge rows totalling one
    // ledger bank-charge posting, and works for any category/classification.
    $categoryAutoMatched = 0;
    if (function_exists('brReconAutoMatchInsertedAgainstCategoryTotals')) {
        $allLedgerIds = array_map('intval', array_column($ledgerLines, 'id'));
        $allBankIds = array_map('intval', array_column($bankLines, 'id'));
        $categoryAutoMatched += brReconAutoMatchInsertedAgainstCategoryTotals($conn, $reconId, 'ledger', $allLedgerIds, $tolDays, $tolAmt, $by, $matchSeq);
        $categoryAutoMatched += brReconAutoMatchInsertedAgainstCategoryTotals($conn, $reconId, 'bank', $allBankIds, $tolDays, $tolAmt, $by, $matchSeq);
        $autoCount += $categoryAutoMatched;
    }

    $summary = brAutoRecomputeSummary($conn, $reconId);
    $conn->commit();

    brJson([
        'status'  => 'Success',
        'success' => true,
        'message' => (count($bankLines) === 0 && count($ledgerLines) === 0)
            ? 'Reconciliation created as a no-movement period — no bank or ledger transactions were found. Balances will determine the status.'
            : "Reconciliation created — $autoCount of " . count($bankLines) . " bank lines auto-matched; $autoClassified bank-side item(s) auto-categorised.",
        // Keep multiple ID aliases so every frontend path can safely detect the
        // newly-created reconciliation and navigate to the workspace.
        'id'       => $reconId,
        'recon_id' => $reconId,
        'reconciliation_id' => $reconId,
        'created_recon_id' => $reconId,
        'insert_id' => $reconId,
        'bank_recon_id' => $reconId,
        'reconciliation' => ['id' => $reconId],
        'recon' => ['id' => $reconId],
        'data'    => [
            'id' => $reconId,
            'recon_id' => $reconId,
            'reconciliation_id' => $reconId,
            'created_recon_id' => $reconId,
            'insert_id' => $reconId,
            'bank_recon_id' => $reconId,
            'reconciliation' => ['id' => $reconId],
            'recon' => ['id' => $reconId],
            'recon_number' => $reconNo,
            'bank_count' => count($bankLines),
            'ledger_count' => count($ledgerLines),
            'auto_matched' => $autoCount,
            'auto_classified' => $autoClassified,
            'category_auto_matched' => $categoryAutoMatched ?? 0,
            'summary' => $summary,
        ],
    ], 201);

} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    error_log('BR create error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    brJson(['status' => 'Failed', 'success' => false, 'message' => $e->getMessage()], $code);
}
}
