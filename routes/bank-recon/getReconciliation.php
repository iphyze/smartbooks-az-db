<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';
header('Content-Type: application/json');

function brFail($m, $c = 400)
{
    throw new Exception($m, $c);
}
function lineMap($row)
{
    $row = brReconLineMapWithOutstanding($row);
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
            $sum += (float)($line['outstanding_amount'] ?? $line['amount']);
        }
    }
    return round($sum, 2);
}
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') brFail('Route not found', 404);
    $user = requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) brFail('id is required');

    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$r) brFail('Reconciliation not found', 404);

    brReconEnsureSmartSchema($conn);

    $bank = array_map('lineMap', $conn->query("SELECT * FROM bank_recon_bank_lines WHERE recon_id=$id ORDER BY txn_date,id")->fetch_all(MYSQLI_ASSOC));
    $ledger = array_map('lineMap', $conn->query("SELECT * FROM bank_recon_ledger_lines WHERE recon_id=$id ORDER BY txn_date,id")->fetch_all(MYSQLI_ASSOC));
    $matches = $conn->query("SELECT * FROM bank_recon_matches WHERE recon_id=$id ORDER BY matched_at,id")->fetch_all(MYSQLI_ASSOC);

    $bTotal = count($bank);
    $lTotal = count($ledger);
    $bMatched = count(array_filter($bank, fn($x) => $x['match_status'] === 'Matched'));
    $lMatched = count(array_filter($ledger, fn($x) => $x['match_status'] === 'Matched'));
    $bPartial = count(array_filter($bank, fn($x) => !empty($x['is_partially_matched'])));
    $lPartial = count(array_filter($ledger, fn($x) => !empty($x['is_partially_matched'])));
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
    $rawDiff = round($adjustedLedger - $adjustedBank, 2);
    $roundingTolerance = 0.01;
    $diff = abs($rawDiff) <= $roundingTolerance ? 0.00 : $rawDiff;
    $noMovementPeriod = ($bTotal === 0 && $lTotal === 0);
    $hasBankTransactions = $bTotal > 0;
    $hasLedgerTransactions = $lTotal > 0;
    $matchRate = $bTotal ? round(($bMatched / $bTotal) * 100) : ($noMovementPeriod && abs($diff) <= $roundingTolerance ? 100 : 0);

    $r['adjusted_bank_balance'] = $adjustedBank;
    $r['adjusted_ledger_balance'] = $adjustedLedger;
    $r['unreconciled_difference'] = $diff;
    $r['status'] = abs($diff) <= $roundingTolerance ? 'Balanced' : 'Unbalanced';

    // Keep the list/header record aligned with changes made in the workspace, including bulk actions.
    $summaryStmt = $conn->prepare('UPDATE bank_recons SET adjusted_bank_balance = ?, adjusted_ledger_balance = ?, unreconciled_difference = ?, status = ? WHERE id = ?');
    if (!$summaryStmt) brFail('Failed to prepare reconciliation summary update: ' . $conn->error, 500);
    $summaryStmt->bind_param('dddsi', $adjustedBank, $adjustedLedger, $diff, $r['status'], $id);
    $summaryStmt->execute();
    $summaryStmt->close();

    $summary = compact(
        'bTotal',
        'lTotal',
        'bMatched',
        'lMatched',
        'bPartial',
        'lPartial',
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
    );
    $summary['noMovementPeriod'] = $noMovementPeriod;
    $summary['hasBankTransactions'] = $hasBankTransactions;
    $summary['hasLedgerTransactions'] = $hasLedgerTransactions;

    $differenceExplanation = function_exists('brReconBuildDifferenceExplanation')
        ? brReconBuildDifferenceExplanation($conn, $id, $r, $bank, $ledger, $summary)
        : null;
    $uploadProfiles = function_exists('brReconFetchUploadProfilesForRecon')
        ? brReconFetchUploadProfilesForRecon($conn, $id)
        : [];
    $learnedPatterns = function_exists('brReconFetchLearnedPatternsForRecon')
        ? brReconFetchLearnedPatternsForRecon($conn, $id)
        : [];

    echo json_encode([
        'status' => 'Success',
        'data' => [
            'reconciliation' => $r,
            'bank_lines' => $bank,
            'ledger_lines' => $ledger,
            'matches' => $matches,
            'summary' => $summary,
            'no_movement_period' => $noMovementPeriod,
            'difference_explanation' => $differenceExplanation,
            'upload_profiles' => $uploadProfiles,
            'learned_patterns' => $learnedPatterns,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
