<?php
/**
 * POST /bank-recon/append-lines
 *
 * Appends NEW transactions to an existing reconciliation from a file upload.
 * Uses INSERT IGNORE + line_hash deduplication — existing lines are untouched.
 * Only genuinely new lines are inserted and then auto-matched against
 * existing UNMATCHED lines on the opposite side.
 *
 * This is the recommended approach over "Add a single line manually" because:
 *  - Real ledger postings come from exports, not manual entry
 *  - Hash dedup means re-uploading the same file is safe (idempotent)
 *  - New lines are auto-matched immediately, saving manual work
 *  - Existing matches/classifications are completely preserved
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

// Reuse parsing helpers from createReconciliation
defined('BR_HELPERS_ONLY') || define('BR_HELPERS_ONLY', true);
require_once 'routes/bank-recon/createReconciliation.php';

header('Content-Type: application/json');

function appendFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') appendFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) appendFail('Unauthorized', 401);
    $by = $user['email'] ?? $user['username'] ?? 'system';

    $id     = (int)($_POST['recon_id'] ?? 0);
    $source = strtolower(trim($_POST['source'] ?? ''));

    if (!$id)                                  appendFail('recon_id is required.');
    if (!in_array($source, ['bank', 'ledger'])) appendFail('source must be "bank" or "ledger".');

    $fileKey = $source . '_file';
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK)
        appendFail(ucfirst($source) . ' file is required (CSV or XLSX).');

    $recon = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$recon) appendFail('Reconciliation not found.', 404);

    $tolDays = (int)$recon['tolerance_days'];
    $tolAmt  = (float)$recon['tolerance_amount'];

    // ── Parse the uploaded file ─────────────────────────────────────────
    $rawRows  = readUploadedReconFile($_FILES[$fileKey]['tmp_name'], $_FILES[$fileKey]['name']);
    $parseFn  = $source === 'bank' ? 'parseBankRow' : 'parseLedgerRow';
    $newRows  = array_values(array_filter(array_map($parseFn, $rawRows)));
    if (!$newRows) appendFail('No valid transactions found in the file.', 422);

    $conn->begin_transaction();

    $table       = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $insertedIds = [];

    if ($source === 'bank') {
        $ins = $conn->prepare(
            "INSERT IGNORE INTO bank_recon_bank_lines
             (recon_id, txn_date, description, reference, amount, direction, running_balance, line_hash)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        foreach ($newRows as $r) {
            $bal  = (float)($r['balance'] ?? 0);
            $hash = hash('sha256', "$id|bank|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
            $ins->bind_param('isssdsds', $id, $r['date'], $r['description'], $r['reference'], $r['amount'], $r['direction'], $bal, $hash);
            $ins->execute();
            if ($ins->affected_rows > 0) $insertedIds[] = $conn->insert_id;
        }
        $ins->close();
    } else {
        $ins2 = $conn->prepare(
            "INSERT IGNORE INTO bank_recon_ledger_lines
             (recon_id, txn_date, description, reference, ledger_name, amount, direction, running_balance, line_hash)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        foreach ($newRows as $r) {
            $bal  = (float)($r['balance'] ?? 0);
            $hash = hash('sha256', "$id|ledger|{$r['date']}|{$r['amount']}|{$r['direction']}|{$bal}|" . substr($r['description'], 0, 60));
            $ins2->bind_param('issssdsds', $id, $r['date'], $r['description'], $r['reference'], $r['ledger_name'], $r['amount'], $r['direction'], $bal, $hash);
            $ins2->execute();
            if ($ins2->affected_rows > 0) $insertedIds[] = $conn->insert_id;
        }
        $ins2->close();
    }

    $newCount = count($insertedIds);

    // ── Auto-match new lines against existing UNMATCHED lines on the other side ──
    $autoMatched = 0;
    if ($newCount > 0) {
        // Get the newly inserted lines
        if (!$insertedIds) goto skip_match;
        $idList    = implode(',', $insertedIds);
        $newLines  = $conn->query("SELECT * FROM $table WHERE id IN ($idList)")->fetch_all(MYSQLI_ASSOC);

        // Get existing unmatched lines on the OTHER side
        $otherTable = $source === 'bank' ? 'bank_recon_ledger_lines' : 'bank_recon_bank_lines';
        $otherLines = $conn->query(
            "SELECT * FROM $otherTable WHERE recon_id=$id AND match_status='Unmatched' ORDER BY txn_date, id"
        )->fetch_all(MYSQLI_ASSOC);

        // Determine next match sequence number
        $maxSeq = 0;
        $seqRes = $conn->query(
            "SELECT match_group FROM bank_recon_matches WHERE recon_id=$id ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();
        if ($seqRes) {
            preg_match('/AM-(\d+)-/', $seqRes['match_group'], $m);
            $maxSeq = isset($m[1]) ? (int)$m[1] : 0;
        }
        $matchSeq = $maxSeq + 1;

        $mIns = $conn->prepare(
            "INSERT INTO bank_recon_matches
             (recon_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, amount_difference, day_difference, matched_by)
             VALUES (?,?,?,?,'Auto',?,?,?,?)"
        );

        $usedOther = [];

        foreach ($newLines as $newLine) {
            $best = null; $bestScore = -1;

            foreach ($otherLines as $other) {
                if (isset($usedOther[$other['id']])) continue;
                if ($other['direction'] !== $newLine['direction']) continue;
                $amtDiff = round(abs((float)$newLine['amount'] - (float)$other['amount']), 2);
                if ($amtDiff > max($tolAmt, 0.01)) continue;
                $dayDiff = (int)(abs(strtotime($newLine['txn_date']) - strtotime($other['txn_date'])) / 86400);
                if ($dayDiff > $tolDays) continue;
                $score = 50
                    + ($amtDiff < 0.02 ? 20 : 0)
                    + max(0, 25 - $dayDiff * 5)
                    + (int)round(textSim($newLine['description'], $other['description']) * 0.15);
                if ($score > $bestScore) { $bestScore = $score; $best = $other; }
            }

            if ($bestScore >= 65 && $best) {
                $mg  = 'AM-' . str_pad($matchSeq++, 4, '0', STR_PAD_LEFT) . '-' . $id;
                $mgE = $conn->real_escape_string($mg);
                $aD  = round(abs((float)$newLine['amount'] - (float)$best['amount']), 2);
                $dD  = (int)(abs(strtotime($newLine['txn_date']) - strtotime($best['txn_date'])) / 86400);
                $conf = min(100, $bestScore);

                // Which is bank, which is ledger?
                if ($source === 'bank') {
                    $bankId   = (int)$newLine['id'];
                    $ledgerId = (int)$best['id'];
                    $conn->query("UPDATE bank_recon_bank_lines   SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=$bankId");
                    $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=$ledgerId");
                } else {
                    $bankId   = (int)$best['id'];
                    $ledgerId = (int)$newLine['id'];
                    $conn->query("UPDATE bank_recon_bank_lines   SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=$bankId");
                    $conn->query("UPDATE bank_recon_ledger_lines SET match_status='Matched', match_group='$mgE', auto_matched=1 WHERE id=$ledgerId");
                }

                $byE = $conn->real_escape_string($by);
                $mIns->bind_param('isiiidis', $id, $mg, $bankId, $ledgerId, $conf, $aD, $dD, $byE);
                $mIns->execute();
                $usedOther[$best['id']] = true;
                $autoMatched++;
            }
        }
        $mIns->close();

        // Auto-classify new bank-only lines
        if ($source === 'bank') {
            foreach ($newLines as $b) {
                if (!in_array($b['id'], array_keys($usedOther)) && $b['match_status'] === 'Unmatched') {
                    $freshLine = $conn->query("SELECT * FROM bank_recon_bank_lines WHERE id=" . (int)$b['id'])->fetch_assoc();
                    if ($freshLine && $freshLine['match_status'] === 'Unmatched') {
                        $type = detectBankOnlyType($freshLine['description'], $freshLine['direction']);
                        if ($type) {
                            $leds = suggestLedgers($type);
                            $tE   = $conn->real_escape_string($type);
                            $drE  = $conn->real_escape_string($leds['dr']);
                            $crE  = $conn->real_escape_string($leds['cr']);
                            $conn->query("UPDATE bank_recon_bank_lines SET match_status='Bank-Only', bank_only_type='$tE', suggested_dr_ledger='$drE', suggested_cr_ledger='$crE' WHERE id=" . (int)$b['id']);
                        }
                    }
                }
            }
        }
    }

    skip_match:
    $summary = recomputeSummary($conn, $id);
    $conn->commit();

    echo json_encode([
        'status'       => 'Success',
        'message'      => "$newCount new line(s) added from the {$source} file. $autoMatched auto-matched.",
        'data'         => [
            'inserted'     => $newCount,
            'auto_matched' => $autoMatched,
            'skipped'      => count($newRows) - $newCount,
            'summary'      => $summary,
        ],
    ]);

} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}
