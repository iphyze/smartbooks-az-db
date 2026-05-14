<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');

function brFail($m, $c = 400)
{
    throw new Exception($m, $c);
}
function lineMap($row)
{
    $row['amount'] = abs((float)($row['amount'] ?? 0));
    $row['txn_date'] = $row['txn_date'] ?? $row['transaction_date'] ?? null;
    if (!isset($row['category_name']) || $row['category_name'] === null) $row['category_name'] = $row['bank_only_type'] ?? '';
    if (!isset($row['recon_classification'])) $row['recon_classification'] = '';
    if (isset($row['match_status']) && $row['match_status'] === 'Bank-Only') $row['match_status'] = 'Classified';
    return $row;
}
function sumClass(array $bank, array $ledger, string $class): float
{
    $sum = 0.0;
    foreach (array_merge($bank, $ledger) as $line) {
        if (in_array($line['match_status'], ['Classified', 'Bank-Only'], true) && ($line['recon_classification'] ?? '') === $class) {
            $sum += (float)$line['amount'];
        }
    }
    return round($sum, 2);
}
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) brFail('Unauthorized', 401);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) brFail('id is required');

    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$r) brFail('Reconciliation not found', 404);

    $bank = array_map('lineMap', $conn->query("SELECT * FROM bank_recon_bank_lines WHERE recon_id=$id ORDER BY txn_date,id")->fetch_all(MYSQLI_ASSOC));
    $ledger = array_map('lineMap', $conn->query("SELECT * FROM bank_recon_ledger_lines WHERE recon_id=$id ORDER BY txn_date,id")->fetch_all(MYSQLI_ASSOC));
    $matches = $conn->query("SELECT * FROM bank_recon_matches WHERE recon_id=$id ORDER BY matched_at,id")->fetch_all(MYSQLI_ASSOC);

    $bTotal = count($bank);
    $lTotal = count($ledger);
    $bMatched = count(array_filter($bank, fn($x) => $x['match_status'] === 'Matched'));
    $lMatched = count(array_filter($ledger, fn($x) => $x['match_status'] === 'Matched'));
    $bClassified = count(array_filter($bank, fn($x) => $x['match_status'] === 'Classified'));
    $lClassified = count(array_filter($ledger, fn($x) => $x['match_status'] === 'Classified'));
    $bUnmatched = count(array_filter($bank, fn($x) => $x['match_status'] === 'Unmatched'));
    $lUnmatched = count(array_filter($ledger, fn($x) => $x['match_status'] === 'Unmatched'));

    $weDebitTheyDontCredit  = sumClass($bank, $ledger, "We Debit They Don't Credit");
    $theyDebitWeDontCredit = sumClass($bank, $ledger, "They Debit We Don't Credit");
    $weCreditTheyDontDebit = sumClass($bank, $ledger, "We Credit They Don't Debit");
    $theyCreditWeDontDebit = sumClass($bank, $ledger, "They Credit We Don't Debit");

    $adjustedLedger = round((float)$r['ledger_closing'] - $theyDebitWeDontCredit + $theyCreditWeDontDebit, 2);
    $adjustedBank = round((float)$r['bank_closing'] + $weDebitTheyDontCredit - $weCreditTheyDontDebit, 2);
    $diff = round($adjustedBank - $adjustedLedger, 2);
    $matchRate = $bTotal ? round(($bMatched / $bTotal) * 100) : 0;

    $r['adjusted_bank_balance'] = $adjustedBank;
    $r['adjusted_ledger_balance'] = $adjustedLedger;
    $r['unreconciled_difference'] = $diff;
    $r['status'] = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';

    echo json_encode([
        'status' => 'Success',
        'data' => [
            'reconciliation' => $r,
            'bank_lines' => $bank,
            'ledger_lines' => $ledger,
            'matches' => $matches,
            'summary' => compact(
                'bTotal',
                'lTotal',
                'bMatched',
                'lMatched',
                'bClassified',
                'lClassified',
                'bUnmatched',
                'lUnmatched',
                'weDebitTheyDontCredit',
                'theyDebitWeDontCredit',
                'weCreditTheyDontDebit',
                'theyCreditWeDontDebit',
                'adjustedLedger',
                'adjustedBank',
                'diff',
                'matchRate'
            ),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
