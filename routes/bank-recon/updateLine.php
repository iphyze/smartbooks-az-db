<?php
/**
 * POST /bank-recon/update-line
 *
 * Corrects an erroneous transaction on either the bank or ledger side.
 * Editable fields: amount, direction, txn_date, description, reference,
 *                  ledger_name (ledger only), running_balance.
 *
 * Matched lines can still be edited (amount correction may be needed),
 * but a warning is returned so the user knows re-matching may be required.
 *
 * After any edit, the reconciliation summary is recomputed.
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function lnFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

function parseAmt(string $raw): float {
    $v = str_replace([',', '₦', '$', '£', '€', ' '], '', trim($raw));
    return round((float)ltrim($v, '+-'), 2);
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

function recomputeSummary(mysqli $conn, int $reconId): array {
    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$reconId LIMIT 1")->fetch_assoc();
    if (!$r) return [];

    // Collect classification totals for the four balance-affecting classes
    $classes = [
        "We Debit They Don't Credit" => 0.0,
        "They Debit We Don't Credit" => 0.0,
        "We Credit They Don't Debit" => 0.0,
        "They Credit We Don't Debit" => 0.0,
    ];
    foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $tbl) {
        $res = $conn->query("SELECT recon_classification, COALESCE(SUM(amount),0) amt
            FROM $tbl WHERE recon_id=$reconId
            AND match_status IN ('Classified','Bank-Only') AND recon_classification IS NOT NULL
            GROUP BY recon_classification");
        while ($row = $res->fetch_assoc()) {
            if (array_key_exists($row['recon_classification'], $classes))
                $classes[$row['recon_classification']] += (float)$row['amt'];
        }
    }

    $adjLedger = round((float)$r['ledger_closing']
        - $classes["They Debit We Don't Credit"]
        + $classes["They Credit We Don't Debit"], 2);
    $adjBank   = round((float)$r['bank_closing']
        + $classes["We Debit They Don't Credit"]
        - $classes["We Credit They Don't Debit"], 2);
    $diff      = round($adjBank - $adjLedger, 2);
    $status    = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';

    $stmt = $conn->prepare(
        "UPDATE bank_recons SET adjusted_bank_balance=?, adjusted_ledger_balance=?, unreconciled_difference=?, status=? WHERE id=?"
    );
    $stmt->bind_param('dddsi', $adjBank, $adjLedger, $diff, $status, $reconId);
    $stmt->execute();
    $stmt->close();

    return compact('adjBank', 'adjLedger', 'diff', 'status');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') lnFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) lnFail('Unauthorized', 401);

    $raw  = json_decode(file_get_contents('php://input'), true);
    $body = is_array($raw) ? $raw : $_POST;

    $lineId  = (int)($body['line_id']  ?? 0);
    $reconId = (int)($body['recon_id'] ?? 0);
    $source  = strtolower(trim($body['source'] ?? ''));

    if (!$lineId || !$reconId)       lnFail('line_id and recon_id are required.');
    if (!in_array($source, ['bank', 'ledger'])) lnFail('source must be bank or ledger.');

    $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $line  = $conn->query("SELECT * FROM $table WHERE id=$lineId AND recon_id=$reconId LIMIT 1")->fetch_assoc();
    if (!$line) lnFail(ucfirst($source) . ' line not found.', 404);

    // ── Build update fields — only include what was supplied ──────────
    $sets  = [];
    $types = '';
    $params = [];

    if (isset($body['amount']) && $body['amount'] !== '') {
        $amt = parseAmt((string)$body['amount']);
        if ($amt <= 0) lnFail('Amount must be greater than zero.');
        $sets[] = 'amount=?';
        $types .= 'd'; $params[] = $amt;
    }

    if (isset($body['direction']) && in_array($body['direction'], ['IN', 'OUT'], true)) {
        $sets[] = 'direction=?';
        $types .= 's'; $params[] = $body['direction'];
    }

    if (isset($body['txn_date']) && $body['txn_date'] !== '') {
        $d = parseDateStr((string)$body['txn_date']);
        if (!$d) lnFail('Invalid date format.');
        $sets[] = 'txn_date=?';
        $types .= 's'; $params[] = $d;
    }

    if (isset($body['description']) && trim($body['description']) !== '') {
        $sets[] = 'description=?';
        $types .= 's'; $params[] = trim($body['description']);
    }

    if (isset($body['reference'])) {
        $sets[] = 'reference=?';
        $types .= 's'; $params[] = trim($body['reference']);
    }

    if ($source === 'ledger' && isset($body['ledger_name'])) {
        $sets[] = 'ledger_name=?';
        $types .= 's'; $params[] = trim($body['ledger_name']);
    }

    if (isset($body['running_balance']) && $body['running_balance'] !== '') {
        $sets[] = 'running_balance=?';
        $types .= 'd'; $params[] = parseAmt((string)$body['running_balance']);
    }

    if (!$sets) lnFail('No fields to update were provided.');

    // ── Rebuild line_hash so it won't collide if amount/direction changed ──
    $newAmt    = isset($body['amount']) && $body['amount'] !== '' ? parseAmt((string)$body['amount']) : (float)$line['amount'];
    $newDir    = isset($body['direction']) && in_array($body['direction'], ['IN','OUT'], true) ? $body['direction'] : $line['direction'];
    $newDate   = isset($body['txn_date']) && $body['txn_date'] !== '' ? (parseDateStr((string)$body['txn_date']) ?: $line['txn_date']) : $line['txn_date'];
    $newDesc   = isset($body['description']) && trim($body['description']) !== '' ? trim($body['description']) : $line['description'];
    $newBal    = isset($body['running_balance']) && $body['running_balance'] !== '' ? parseAmt((string)$body['running_balance']) : (float)($line['running_balance'] ?? 0);
    $newHash   = hash('sha256', "$reconId|$source|$newDate|$newAmt|$newDir|$newBal|" . substr($newDesc, 0, 60));

    $sets[]  = 'line_hash=?';
    $types  .= 's'; $params[] = $newHash;

    // ── Execute ────────────────────────────────────────────────────────
    $types  .= 'i'; $params[] = $lineId;
    $sql     = "UPDATE $table SET " . implode(', ', $sets) . " WHERE id=?";
    $stmt    = $conn->prepare($sql);
    if (!$stmt) lnFail('Prepare failed: ' . $conn->error, 500);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) lnFail('Update failed: ' . $stmt->error, 500);
    $stmt->close();

    // ── Reload the updated line ────────────────────────────────────────
    $updated = $conn->query("SELECT * FROM $table WHERE id=$lineId LIMIT 1")->fetch_assoc();
    $updated['amount'] = abs((float)$updated['amount']);

    // ── Recompute summary ──────────────────────────────────────────────
    $summary = recomputeSummary($conn, $reconId);

    $wasMatched = $line['match_status'] === 'Matched';
    echo json_encode([
        'status'  => 'Success',
        'message' => 'Line updated successfully.' . ($wasMatched ? ' Note: this line was matched — please review the match.' : ''),
        'data'    => ['line' => $updated, 'summary' => $summary],
        'warning' => $wasMatched,
    ]);

} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}