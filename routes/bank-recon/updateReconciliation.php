<?php

declare(strict_types=1);
/**
 * POST /bank-recon/update
 *
 * Accepts multipart/form-data.
 * Required:  recon_id
 * Optional:  any header field (company_name, bank_name, …, notes)
 *            bank_file   — new XLSX/CSV; appends only genuinely new bank lines
 *            ledger_file — new XLSX/CSV; appends only genuinely new ledger lines
 *
 * Important: edit/re-upload is intentionally append-only. Existing lines,
 * matches, match groups, categories and manual classifications are never
 * deleted or reprocessed. This lets users upload an updated month-to-date
 * extract and continue from where they stopped.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';




function brFail(string $msg, int $code = 400): void {
    throw new Exception($msg, $code);
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


function brUpdateFetchExistingLooseKeys(mysqli $conn, int $reconId, string $source): array {
    $source = strtolower(trim($source));
    $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $res = $conn->query("SELECT txn_date, amount, direction, description FROM {$table} WHERE recon_id={$reconId} ORDER BY id");
    $keys = [];
    if (!$res) return $keys;
    while ($line = $res->fetch_assoc()) {
        if (function_exists('brAutoLineLooseKey')) {
            $key = brAutoLineLooseKey((string)$line['txn_date'], $line['amount'] ?? 0, (string)$line['direction'], $line['description'] ?? '');
        } else {
            $key = implode('|', [(string)$line['txn_date'], strtoupper((string)$line['direction']), number_format(abs((float)$line['amount']), 2, '.', ''), strtolower(trim((string)$line['description']))]);
        }
        $keys[$key] = ($keys[$key] ?? 0) + 1;
    }
    return $keys;
}

function brUpdateParsedLooseKey(array $line): string {
    if (function_exists('brAutoLineLooseKey')) {
        return brAutoLineLooseKey(
            (string)($line['date'] ?? ''),
            $line['amount'] ?? 0,
            (string)($line['direction'] ?? ''),
            $line['description'] ?? ''
        );
    }
    return implode('|', [
        (string)($line['date'] ?? ''),
        strtoupper((string)($line['direction'] ?? '')),
        number_format(abs((float)($line['amount'] ?? 0)), 2, '.', ''),
        strtolower(trim((string)($line['description'] ?? ''))),
    ]);
}

function brUpdateNextAutoMatchSequence(mysqli $conn, int $reconId): int {
    $maxSeq = 0;
    $res = $conn->query("SELECT match_group FROM bank_recon_matches WHERE recon_id={$reconId} ORDER BY id DESC LIMIT 100");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (preg_match('/AM-(\d+)-/', (string)$row['match_group'], $m)) {
                $maxSeq = max($maxSeq, (int)$m[1]);
            }
        }
    }
    return $maxSeq + 1;
}

function brUpdateAutoMatchInsertedLines(mysqli $conn, int $reconId, string $source, array $insertedIds, int $tolDays, float $tolAmt, string $by, int &$matchSeq): int {
    $ids = array_values(array_filter(array_map('intval', $insertedIds), static fn($id) => $id > 0));
    if (!$ids) return 0;

    $source = strtolower(trim($source));
    $newTable = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $otherTable = $source === 'bank' ? 'bank_recon_ledger_lines' : 'bank_recon_bank_lines';
    $idList = implode(',', $ids);

    $newLines = $conn->query("SELECT * FROM {$newTable} WHERE recon_id={$reconId} AND id IN ({$idList}) AND match_status='Unmatched' ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);
    if (!$newLines) return 0;

    $otherLines = $conn->query("SELECT * FROM {$otherTable} WHERE recon_id={$reconId} AND match_status='Unmatched' ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);
    if (!$otherLines) return 0;

    $mIns = $conn->prepare("INSERT INTO bank_recon_matches
        (recon_id, match_group, bank_line_id, ledger_line_id, bank_allocated_amount, ledger_allocated_amount, is_partial, match_type, confidence, amount_difference, day_difference, matched_by)
        VALUES (?,?,?,?,?,?,0,'Auto',?,?,?,?)");
    if (!$mIns) return 0;

    $usedOther = [];
    $autoMatched = 0;
    foreach ($newLines as $newLine) {
        $best = null;
        $bestScore = -1;

        foreach ($otherLines as $other) {
            if (isset($usedOther[$other['id']])) continue;
            if (($other['direction'] ?? '') !== ($newLine['direction'] ?? '')) continue;

            $amtDiff = round(abs((float)$newLine['amount'] - (float)$other['amount']), 2);
            if ($amtDiff > max($tolAmt, 0.01)) continue;

            $dayDiff = (int)(abs(strtotime((string)$newLine['txn_date']) - strtotime((string)$other['txn_date'])) / 86400);
            if ($dayDiff > $tolDays) continue;

            $score = 50
                + ($amtDiff < 0.02 ? 20 : 0)
                + max(0, 25 - $dayDiff * 5)
                + (int)round(textSim((string)$newLine['description'], (string)$other['description']) * 0.15);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $other;
            }
        }

        if ($bestScore < 65 || !$best) continue;

        $mg = 'AM-' . str_pad((string)$matchSeq++, 4, '0', STR_PAD_LEFT) . '-' . $reconId;
        $mgE = $conn->real_escape_string($mg);
        $aD = round(abs((float)$newLine['amount'] - (float)$best['amount']), 2);
        $dD = (int)(abs(strtotime((string)$newLine['txn_date']) - strtotime((string)$best['txn_date'])) / 86400);
        $conf = min(100, $bestScore);

        if ($source === 'bank') {
            $bankId = (int)$newLine['id'];
            $ledgerId = (int)$best['id'];
        } else {
            $bankId = (int)$best['id'];
            $ledgerId = (int)$newLine['id'];
        }

        $conn->query("UPDATE bank_recon_bank_lines SET match_status='Matched', match_group='{$mgE}', auto_matched=1, matched_amount=ABS(amount) WHERE id={$bankId} AND recon_id={$reconId} AND match_status='Unmatched'");
        $bankUpdated = $conn->affected_rows > 0;
        $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Matched', match_group='{$mgE}', auto_matched=1, matched_amount=ABS(amount) WHERE id={$ledgerId} AND recon_id={$reconId} AND match_status='Unmatched'");
        $ledgerUpdated = $conn->affected_rows > 0;

        if (!$bankUpdated || !$ledgerUpdated) {
            // Another pass may have matched one side. Roll the other side back to
            // unmatched and skip saving a broken match link.
            if ($bankUpdated) {
                $conn->query("UPDATE bank_recon_bank_lines SET match_status='Unmatched', match_group=NULL, auto_matched=0, matched_amount=0 WHERE id={$bankId} AND recon_id={$reconId} AND match_group='{$mgE}'");
            }
            if ($ledgerUpdated) {
                $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Unmatched', match_group=NULL, auto_matched=0, matched_amount=0 WHERE id={$ledgerId} AND recon_id={$reconId} AND match_group='{$mgE}'");
            }
            continue;
        }

        $byE = $conn->real_escape_string($by);
        $bankAmount = (float)($source === 'bank' ? $newLine['amount'] : $best['amount']);
        $ledgerAmount = (float)($source === 'bank' ? $best['amount'] : $newLine['amount']);
        $mIns->bind_param('isiiddidis', $reconId, $mg, $bankId, $ledgerId, $bankAmount, $ledgerAmount, $conf, $aD, $dD, $byE);
        $mIns->execute();

        $usedOther[$best['id']] = true;
        $autoMatched++;
    }

    $mIns->close();
    return $autoMatched;
}

header('Content-Type: application/json');

function updateJson(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function updateFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') updateFail('Route not found', 404);
    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';

    // Accept both JSON and multipart
    $body = [];
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
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

    if (function_exists('brReconEnsureSmartSchema')) brReconEnsureSmartSchema($conn);

    $conn->begin_transaction();

    // Edit re-upload is append-only. Do not delete old lines, clear matches,
    // reset matched statuses, or re-apply classifications to existing rows.
    // Existing work must remain exactly as the user left it.
    $insertedBankIds = [];
    $insertedLedgerIds = [];
    $parsedBankRows = 0;
    $parsedLedgerRows = 0;
    $skippedBankRows = 0;
    $skippedLedgerRows = 0;
    $autoMatched = 0;
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

    // ── Append only genuinely new bank lines if supplied ──────────────
    if ($hasBankFile) {
        $bankMeta = readUploadedReconFileWithMeta($_FILES['bank_file']['tmp_name'], $_FILES['bank_file']['name']);
        validateReconUploadMeta($bankMeta, 'bank', 'new bank file');
        $bankRawRows = $bankMeta['rows'];
        $bankRows = array_values(array_filter(array_map('parseBankRow', $bankRawRows)));
        $parsedBankRows = count($bankRows);
        if (function_exists('brReconRememberUploadProfileFromHeaders')) {
            brReconRememberUploadProfileFromHeaders($conn, $id, 'bank', $bankMeta['original_headers'] ?: $bankMeta['headers'], $_FILES['bank_file']['name'], $by);
        } elseif (function_exists('brReconRememberUploadProfile')) {
            brReconRememberUploadProfile($conn, $id, 'bank', $bankRawRows, $_FILES['bank_file']['name'], $by);
        }

        $existingLoose = brUpdateFetchExistingLooseKeys($conn, $id, 'bank');
        $ins = $conn->prepare("INSERT IGNORE INTO bank_recon_bank_lines
            (recon_id, txn_date, description, reference, amount, direction, running_balance, line_hash)
            VALUES (?,?,?,?,?,?,?,?)");
        if (!$ins) updateFail('Prepare bank insert failed: ' . $conn->error, 500);

        foreach ($bankRows as $r) {
            $looseKey = brUpdateParsedLooseKey($r);
            if (($existingLoose[$looseKey] ?? 0) > 0) {
                $existingLoose[$looseKey]--;
                $skippedBankRows++;
                continue;
            }

            $bal  = (float)($r['balance'] ?? 0);
            $hash = hash('sha256', "$id|bank|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
            $ins->bind_param('isssdsds', $id, $r['date'], $r['description'], $r['reference'], $r['amount'], $r['direction'], $bal, $hash);
            $ins->execute();
            if ($ins->affected_rows > 0) {
                $insertedBankIds[] = (int)$conn->insert_id;
            } else {
                $skippedBankRows++;
            }
        }
        $ins->close();
    }

    // ── Append only genuinely new ledger lines if supplied ────────────
    if ($hasLedgerFile) {
        $ledgerMeta = readUploadedReconFileWithMeta($_FILES['ledger_file']['tmp_name'], $_FILES['ledger_file']['name']);
        validateReconUploadMeta($ledgerMeta, 'ledger', 'new ledger file');
        $ledgerRawRows = $ledgerMeta['rows'];
        $ledgerRows = array_values(array_filter(array_map('parseLedgerRow', $ledgerRawRows)));
        $parsedLedgerRows = count($ledgerRows);
        if (function_exists('brReconRememberUploadProfileFromHeaders')) {
            brReconRememberUploadProfileFromHeaders($conn, $id, 'ledger', $ledgerMeta['original_headers'] ?: $ledgerMeta['headers'], $_FILES['ledger_file']['name'], $by);
        } elseif (function_exists('brReconRememberUploadProfile')) {
            brReconRememberUploadProfile($conn, $id, 'ledger', $ledgerRawRows, $_FILES['ledger_file']['name'], $by);
        }

        $existingLoose = brUpdateFetchExistingLooseKeys($conn, $id, 'ledger');
        $ins2 = $conn->prepare("INSERT IGNORE INTO bank_recon_ledger_lines
            (recon_id, txn_date, description, reference, ledger_name, amount, direction, running_balance, line_hash)
            VALUES (?,?,?,?,?,?,?,?,?)");
        if (!$ins2) updateFail('Prepare ledger insert failed: ' . $conn->error, 500);

        foreach ($ledgerRows as $r) {
            $looseKey = brUpdateParsedLooseKey($r);
            if (($existingLoose[$looseKey] ?? 0) > 0) {
                $existingLoose[$looseKey]--;
                $skippedLedgerRows++;
                continue;
            }

            $bal  = (float)($r['balance'] ?? 0);
            $hash = hash('sha256', "$id|ledger|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
            $ins2->bind_param('issssdsds', $id, $r['date'], $r['description'], $r['reference'], $r['ledger_name'], $r['amount'], $r['direction'], $bal, $hash);
            $ins2->execute();
            if ($ins2->affected_rows > 0) {
                $insertedLedgerIds[] = (int)$conn->insert_id;
            } else {
                $skippedLedgerRows++;
            }
        }
        $ins2->close();
    }

    // ── Only process newly inserted rows; existing rows stay untouched ──
    if ($insertedBankIds || $insertedLedgerIds) {
        $matchSeq = brUpdateNextAutoMatchSequence($conn, $id);

        if ($insertedBankIds) {
            $autoMatched += brUpdateAutoMatchInsertedLines($conn, $id, 'bank', $insertedBankIds, $tolDays, $tolAmt, $by, $matchSeq);
        }
        if ($insertedLedgerIds) {
            $autoMatched += brUpdateAutoMatchInsertedLines($conn, $id, 'ledger', $insertedLedgerIds, $tolDays, $tolAmt, $by, $matchSeq);
        }

        // Auto-categorise only the newly inserted lines that remain unmatched.
        // Previously reviewed classifications are never overwritten here.
        if ($insertedBankIds) {
            $autoClassified += brAutoApplyClassifications($conn, $id, 'bank', $insertedBankIds);
        }
        if ($insertedLedgerIds) {
            $autoClassified += brAutoApplyClassifications($conn, $id, 'ledger', $insertedLedgerIds);
        }

        // When users post previously classified reconciling items to the ledger
        // and re-upload the updated ledger, match the new posting against the
        // retained category schedules by category total. Example: many bank
        // charge rows totalling 21,737.50 can auto-match one new ledger posting
        // for the same 21,737.50 without disturbing existing matches.
        if (function_exists('brReconAutoMatchInsertedAgainstCategoryTotals')) {
            if ($insertedLedgerIds) {
                $autoMatched += brReconAutoMatchInsertedAgainstCategoryTotals($conn, $id, 'ledger', $insertedLedgerIds, $tolDays, $tolAmt, $by, $matchSeq);
            }
            if ($insertedBankIds) {
                $autoMatched += brReconAutoMatchInsertedAgainstCategoryTotals($conn, $id, 'bank', $insertedBankIds, $tolDays, $tolAmt, $by, $matchSeq);
            }
        }
    }

    $summary = brAutoRecomputeSummary($conn, $id);
    $conn->commit();

    $fileMsg = '';
    if ($hasBankFile || $hasLedgerFile) {
        $fileMsg = sprintf(
            ' Uploaded files were merged safely: %d new bank line(s), %d new ledger line(s), %d duplicate/previous bank row(s) skipped, %d duplicate/previous ledger row(s) skipped. Heading-only uploads are accepted as no-movement files. Existing matches and classifications were preserved.',
            count($insertedBankIds),
            count($insertedLedgerIds),
            $skippedBankRows,
            $skippedLedgerRows
        );
    }

    $updatedRecon = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    updateJson([
        'status'  => 'Success',
        'success' => true,
        'message' => 'Reconciliation updated successfully.' . $fileMsg,
        'id' => $id,
        'recon_id' => $id,
        'reconciliation_id' => $id,
        'data'    => array_merge([
            'id' => $id,
            'recon_id' => $id,
            'reconciliation_id' => $id,
            'inserted_bank_lines' => count($insertedBankIds),
            'inserted_ledger_lines' => count($insertedLedgerIds),
            'skipped_bank_rows' => $skippedBankRows,
            'skipped_ledger_rows' => $skippedLedgerRows,
            'parsed_bank_rows' => $parsedBankRows,
            'parsed_ledger_rows' => $parsedLedgerRows,
            'auto_matched' => $autoMatched,
            'auto_classified' => $autoClassified,
        ], $updatedRecon ?: []),
    ]);

} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    updateJson(['status' => 'Failed', 'success' => false, 'message' => $e->getMessage()], $code);
}
