<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';
header('Content-Type: application/json');

function brFail(string $m, int $c = 400): void { throw new Exception($m, $c); }
function brBody(): array {
    $raw = json_decode(file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}
function brClean(?string $v): string { return trim((string)$v); }
function brEsc(mysqli $conn, string $v): string { return $conn->real_escape_string($v); }
function brQ(?string $v): string { return $v === null ? 'NULL' : "'" . $v . "'"; }

function brRecomputeSummary(mysqli $conn, int $id): array {
    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$r) brFail('Reconciliation not found.', 404);

    $classes = [
        "We Debit They Don't Credit" => 0.0,
        "They Debit We Don't Credit" => 0.0,
        "We Credit They Don't Debit" => 0.0,
        "They Credit We Don't Debit" => 0.0,
    ];

    foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $table) {
        $sql = "SELECT recon_classification, COALESCE(SUM(amount),0) amount
                FROM $table
                WHERE recon_id=$id AND match_status IN ('Classified','Bank-Only') AND recon_classification IS NOT NULL
                GROUP BY recon_classification";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            if (array_key_exists($row['recon_classification'], $classes)) {
                $classes[$row['recon_classification']] += (float)$row['amount'];
            }
        }
    }

    $weDebitTheyDontCredit  = $classes["We Debit They Don't Credit"];
    $theyDebitWeDontCredit = $classes["They Debit We Don't Credit"];
    $weCreditTheyDontDebit = $classes["We Credit They Don't Debit"];
    $theyCreditWeDontDebit = $classes["They Credit We Don't Debit"];

    $adjustedLedger = (float)$r['ledger_closing'] - $theyDebitWeDontCredit + $theyCreditWeDontDebit;
    $adjustedBank   = (float)$r['bank_closing'] + $weDebitTheyDontCredit - $weCreditTheyDontDebit;
    $diff = round($adjustedBank - $adjustedLedger, 2);
    $status = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';

    $stmt = $conn->prepare("UPDATE bank_recons SET adjusted_bank_balance=?, adjusted_ledger_balance=?, unreconciled_difference=?, status=? WHERE id=?");
    if (!$stmt) brFail('Failed to prepare summary update: ' . $conn->error, 500);
    $adjBank = round($adjustedBank, 2);
    $adjLedger = round($adjustedLedger, 2);
    $stmt->bind_param('dddsi', $adjBank, $adjLedger, $diff, $status, $id);
    $stmt->execute();
    $stmt->close();

    return [
        'weDebitTheyDontCredit' => round($weDebitTheyDontCredit, 2),
        'theyDebitWeDontCredit' => round($theyDebitWeDontCredit, 2),
        'weCreditTheyDontDebit' => round($weCreditTheyDontDebit, 2),
        'theyCreditWeDontDebit' => round($theyCreditWeDontDebit, 2),
        'adjusted_bank_balance' => $adjBank,
        'adjusted_ledger_balance' => $adjLedger,
        'unreconciled_difference' => $diff,
        'status' => $status,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);
    $user = requireAdmin();
    $body = brBody();
    $reconId = (int)($body['recon_id'] ?? 0);
    $source = strtolower(brClean($body['source'] ?? 'bank'));
    $lineId = (int)($body['line_id'] ?? ($body['bank_line_id'] ?? ($body['ledger_line_id'] ?? 0)));
    $category = brClean($body['category'] ?? ($body['type'] ?? 'Other'));
    $classification = brClean($body['classification'] ?? ($body['recon_classification'] ?? ''));
    $drLedger = brClean($body['dr_ledger'] ?? '');
    $crLedger = brClean($body['cr_ledger'] ?? '');
    $note = brClean($body['note'] ?? '');

    if (!$reconId || !$lineId) brFail('recon_id and line_id are required.');
    if (!in_array($source, ['bank','ledger'])) brFail('source must be bank or ledger.');
    if ($category === '') brFail('Category is required.');

    brReconEnsureClassificationMetadataSchema($conn);

    $validClasses = [
        "We Debit They Don't Credit",
        "They Debit We Don't Credit",
        "We Credit They Don't Debit",
        "They Credit We Don't Debit",
        "Prior Period Item",           // Pass-through — does NOT affect adjusted balances
    ];
    if (!in_array($classification, $validClasses, true)) brFail('Valid reconciliation classification is required.');

    $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $row = $conn->query("SELECT * FROM $table WHERE id=$lineId AND recon_id=$reconId LIMIT 1")->fetch_assoc();
    if (!$row) brFail(ucfirst($source) . ' line not found in this reconciliation.', 404);
    if ($row['match_status'] === 'Matched') brFail('Cannot classify a matched line. Unmatch it first.');

    $categoryE = brEsc($conn, $category);
    $classE = brEsc($conn, $classification);
    $drE = brEsc($conn, $drLedger);
    $crE = brEsc($conn, $crLedger);
    $noteE = brEsc($conn, $note);

    if ($source === 'bank') {
        $sql = "UPDATE bank_recon_bank_lines
                SET match_status='Classified',
                    bank_only_type='$categoryE',
                    category_name='$categoryE',
                    recon_classification='$classE',
                    suggested_dr_ledger=" . brQ($drE) . ",
                    suggested_cr_ledger=" . brQ($crE) . ",
                    journal_note=" . brQ($noteE) . ",
                    classification_origin='manual',
                    classification_rule_id=NULL,
                    classification_locked=1
                WHERE id=$lineId AND recon_id=$reconId";
    } else {
        $sql = "UPDATE bank_recon_ledger_lines
                SET match_status='Classified',
                    category_name='$categoryE',
                    recon_classification='$classE',
                    suggested_dr_ledger=" . brQ($drE) . ",
                    suggested_cr_ledger=" . brQ($crE) . ",
                    journal_note=" . brQ($noteE) . ",
                    classification_origin='manual',
                    classification_rule_id=NULL,
                    classification_locked=1
                WHERE id=$lineId AND recon_id=$reconId";
    }

    if (!$conn->query($sql)) brFail('Failed to classify line: ' . $conn->error, 500);
    $learned = function_exists('brReconLearnFromLine') ? (brReconLearnFromLine($conn, $reconId, $source, $lineId, $user['email'] ?? $user['username'] ?? 'system') ? 1 : 0) : 0;
    $summary = function_exists('brReconRecomputeSummary') ? brReconRecomputeSummary($conn, $reconId) : brRecomputeSummary($conn, $reconId);

    echo json_encode([
        'status' => 'Success',
        'message' => 'Line classified successfully.',
        'data' => ['summary' => $summary, 'learned_patterns' => $learned],
    ]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
