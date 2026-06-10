<?php
/**
 * POST /bank-recon/add-line
 *
 * Manually adds a single transaction line to an existing reconciliation.
 * The line is immediately available for matching or classification.
 *
 * Required: recon_id, source (bank|ledger), txn_date, description, amount, direction
 * Optional: reference, ledger_name (ledger only), running_balance
 *
 * Optional balance update: if new_bank_closing or new_ledger_closing is supplied,
 * the reconciliation header is updated and the summary is recomputed.
 * This lets the user reflect a balance change caused by the new posting.
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function addFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

function parseSignedAmt(string $raw): float {
    $v = str_replace([',', '₦', '$', '£', '€', ' ', "\xc2\xa0"], '', trim($raw));
    if (preg_match('/^\((.+)\)$/', $v, $m)) $v = '-' . $m[1];
    return round((float)$v, 2);
}

/** Parse flexible user-entered dates into YYYY-MM-DD. Ambiguous numeric dates use day-first. */
function parseDateStr(string $raw): ?string {
    $v = trim(str_replace(["\xc2\xa0", "\xef\xbb\xbf"], ' ', $raw));
    if ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'n/a') return null;
    $v = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $v);
    $datePart = trim((preg_split('/\s+(?:\d{1,2}:\d{2}|00:00:00)/', $v)[0] ?? $v));

    if (preg_match('/^\d+(?:\.\d+)?$/', $datePart)) {
        $serial = (float)$datePart;
        if ($serial >= 1 && $serial <= 60000) {
            try {
                $ts = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($serial);
                if ($ts) return date('Y-m-d', $ts);
            } catch (Throwable $e) {}
        }
    }

    $makeDate = static function (int $year, int $month, int $day): ?string {
        if ($year < 100) $year += ($year >= 70 ? 1900 : 2000);
        if (!checkdate($month, $day, $year)) return null;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    };

    if (preg_match('/^(\d{4})[\-\/\.](\d{1,2})[\-\/\.](\d{1,2})(?:[T\s].*)?$/', $datePart, $m)) {
        return $makeDate((int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^\d{8}$/', $datePart)) {
        if (preg_match('/^(19|20)\d{6}$/', $datePart)) {
            $parsed = $makeDate((int)substr($datePart, 0, 4), (int)substr($datePart, 4, 2), (int)substr($datePart, 6, 2));
            if ($parsed) return $parsed;
        }
        $parsed = $makeDate((int)substr($datePart, 4, 4), (int)substr($datePart, 2, 2), (int)substr($datePart, 0, 2));
        if ($parsed) return $parsed;
    }
    if (preg_match('/^(\d{1,2})[\-\/\.](\d{1,2})[\-\/\.](\d{2,4})(?:\s.*)?$/', $datePart, $m)) {
        $first = (int)$m[1]; $second = (int)$m[2]; $year = (int)$m[3];
        if ($first > 12) return $makeDate($year, $second, $first);
        if ($second > 12) return $makeDate($year, $first, $second);
        return $makeDate($year, $second, $first) ?: $makeDate($year, $first, $second);
    }

    $normalisedNameDate = preg_replace('/[,]+/', ' ', preg_replace('/\s+/', ' ', $datePart));
    foreach (['!d M Y','!d F Y','!j M Y','!j F Y','!M d Y','!F d Y','!M j Y','!F j Y','!d-M-Y','!d-F-Y','!j-M-Y','!j-F-Y','!M-d-Y','!F-d-Y','!M-j-Y','!F-j-Y','!d M y','!d F y','!j M y','!j F y','!M d y','!F d y','!M j y','!F j y','!d-M-y','!d-F-y','!j-M-y','!j-F-y','!M-d-y','!F-d-y','!M-j-y','!F-j-y'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $normalisedNameDate);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt && (($errors === false) || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) return $dt->format('Y-m-d');
    }

    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function recomputeAfterAdd(mysqli $conn, int $reconId): array {
    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$reconId LIMIT 1")->fetch_assoc();
    $classes = [
        "We Debit They Don't Credit" => 0.0, "They Debit We Don't Credit" => 0.0,
        "We Credit They Don't Debit" => 0.0, "They Credit We Don't Debit" => 0.0,
    ];
    foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $tbl) {
        $res = $conn->query("SELECT recon_classification, COALESCE(SUM(amount),0) amt
            FROM $tbl WHERE recon_id=$reconId
            AND match_status IN ('Classified','Bank-Only') AND recon_classification IS NOT NULL
            GROUP BY recon_classification");
        while ($row = $res->fetch_assoc())
            if (array_key_exists($row['recon_classification'], $classes))
                $classes[$row['recon_classification']] += (float)$row['amt'];
    }
    $adjLedger = round((float)$r['ledger_closing'] - $classes["They Debit We Don't Credit"] + $classes["They Credit We Don't Debit"], 2);
    $adjBank   = round((float)$r['bank_closing']   + $classes["We Debit They Don't Credit"] - $classes["We Credit They Don't Debit"], 2);
    $diff      = round($adjBank - $adjLedger, 2);
    $status    = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';
    $conn->query(sprintf(
        "UPDATE bank_recons SET adjusted_bank_balance=%.2f, adjusted_ledger_balance=%.2f, unreconciled_difference=%.2f, status='%s' WHERE id=%d",
        $adjBank, $adjLedger, $diff, $conn->real_escape_string($status), $reconId
    ));
    return ['adjusted_bank_balance' => $adjBank, 'adjusted_ledger_balance' => $adjLedger, 'unreconciled_difference' => $diff, 'status' => $status];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') addFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) addFail('Unauthorized', 401);
    $by = $user['email'] ?? $user['username'] ?? 'system';

    $raw  = json_decode(file_get_contents('php://input'), true);
    $body = is_array($raw) ? $raw : $_POST;

    // ── Required fields ────────────────────────────────────────────────
    $reconId   = (int)($body['recon_id']    ?? 0);
    $source    = strtolower(trim($body['source']   ?? ''));
    $txnDate   = trim($body['txn_date']   ?? '');
    $desc      = trim($body['description'] ?? '');
    $amtRaw    = trim((string)($body['amount'] ?? ''));
    $direction = strtoupper(trim($body['direction'] ?? ''));

    if (!$reconId)                                  addFail('recon_id is required.');
    if (!in_array($source, ['bank', 'ledger']))     addFail('source must be bank or ledger.');
    if (!$txnDate)                                  addFail('txn_date is required.');
    if (!$desc)                                     addFail('description is required.');
    if ($amtRaw === '')                             addFail('amount is required.');
    if (!in_array($direction, ['IN', 'OUT']))        addFail('direction must be IN or OUT.');

    $amount = parseSignedAmt($amtRaw);
    if ($amount == 0)                               addFail('Amount cannot be zero.');

    $parsedTxnDate = parseDateStr($txnDate);
    if (!$parsedTxnDate)                            addFail('Invalid txn_date format.');
    $txnDate = $parsedTxnDate;

    $recon = $conn->query("SELECT * FROM bank_recons WHERE id=$reconId LIMIT 1")->fetch_assoc();
    if (!$recon) addFail('Reconciliation not found.', 404);

    // ── Optional fields ────────────────────────────────────────────────
    $reference      = trim($body['reference']       ?? '');
    $ledgerName     = trim($body['ledger_name']     ?? '');
    $runningBalance = isset($body['running_balance']) && $body['running_balance'] !== ''
        ? parseSignedAmt((string)$body['running_balance']) : 0.0;

    // ── Optional balance updates ───────────────────────────────────────
    $newBankClosing   = isset($body['new_bank_closing'])   && $body['new_bank_closing']   !== ''
        ? parseSignedAmt((string)$body['new_bank_closing'])   : null;
    $newLedgerClosing = isset($body['new_ledger_closing']) && $body['new_ledger_closing'] !== ''
        ? parseSignedAmt((string)$body['new_ledger_closing']) : null;

    $conn->begin_transaction();

    // ── Build line_hash ────────────────────────────────────────────────
    $amt    = abs($amount); // store absolute value; direction carries the sign meaning
    $hash   = hash('sha256', "$reconId|$source|$txnDate|$amt|$direction|$runningBalance|" . substr($desc, 0, 60));

    // ── Insert the new line ────────────────────────────────────────────
    $newLineId = null;
    if ($source === 'bank') {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO bank_recon_bank_lines
             (recon_id, txn_date, description, reference, amount, direction, running_balance, line_hash)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('isssdsds', $reconId, $txnDate, $desc, $reference, $amt, $direction, $runningBalance, $hash);
        $stmt->execute();
        if ($stmt->affected_rows === 0) addFail('This line already exists (duplicate hash). No changes made.', 409);
        $newLineId = $conn->insert_id;
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO bank_recon_ledger_lines
             (recon_id, txn_date, description, reference, ledger_name, amount, direction, running_balance, line_hash)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('issssdsds', $reconId, $txnDate, $desc, $reference, $ledgerName, $amt, $direction, $runningBalance, $hash);
        $stmt->execute();
        if ($stmt->affected_rows === 0) addFail('This line already exists (duplicate hash). No changes made.', 409);
        $newLineId = $conn->insert_id;
        $stmt->close();
    }

    // ── Update closing balances if supplied ────────────────────────────
    $balUpdates = [];
    if ($newBankClosing !== null) {
        $balUpdates[] = sprintf('bank_closing=%.2f', $newBankClosing);
    }
    if ($newLedgerClosing !== null) {
        $balUpdates[] = sprintf('ledger_closing=%.2f', $newLedgerClosing);
    }
    if ($balUpdates) {
        $byE = $conn->real_escape_string($by);
        $conn->query("UPDATE bank_recons SET " . implode(', ', $balUpdates) . ", updated_by='$byE' WHERE id=$reconId");
    }

    // ── Recompute summary ──────────────────────────────────────────────
    $summary = recomputeAfterAdd($conn, $reconId);

    // ── Return the inserted line ───────────────────────────────────────
    $table   = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $newLine = $conn->query("SELECT * FROM $table WHERE id=$newLineId LIMIT 1")->fetch_assoc();

    $conn->commit();

    echo json_encode([
        'status'  => 'Success',
        'message' => 'Line added successfully. It is now available for matching or classification.',
        'data'    => ['line' => $newLine, 'summary' => $summary],
    ]);

} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}
