<?php

declare(strict_types=1);
/**
 * POST /bank-recon/delete-line
 *
 * Deletes one or more bank/ledger reconciliation lines without deleting the
 * reconciliation itself. If any selected line is part of a match group, the
 * affected match group(s) are safely removed first so the opposite-side lines
 * return to their correct unmatched/classified/partial state before the line is
 * deleted. This prevents stale match rows and keeps recon totals reliable.
 *
 * Body: recon_id, source (bank|ledger), line_id OR line_ids[]/JSON/comma-list
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';

header('Content-Type: application/json');

function brDeleteFail(string $message, int $code = 400): void
{
    throw new Exception($message, $code);
}

function brDeleteReadBody(): array
{
    $raw = json_decode(file_get_contents('php://input'), true);
    if (is_array($raw)) return $raw;
    return $_POST;
}

function brDeleteNormalizeIds($value): array
{
    if ($value === null || $value === '') return [];
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : explode(',', $value);
    }
    if (!is_array($value)) $value = [$value];

    $ids = [];
    foreach ($value as $item) {
        $id = (int)$item;
        if ($id > 0) $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function brDeleteFetchMatchRows(mysqli $conn, int $reconId, string $matchGroup): array
{
    $mg = $conn->real_escape_string($matchGroup);
    $res = $conn->query("SELECT bank_line_id, ledger_line_id,
            COALESCE(NULLIF(bank_allocated_amount,0), amount_difference, 0) bank_amount,
            COALESCE(NULLIF(ledger_allocated_amount,0), amount_difference, 0) ledger_amount
        FROM bank_recon_matches
        WHERE recon_id={$reconId} AND match_group='{$mg}'");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function brDeleteUnmatchGroup(mysqli $conn, int $reconId, string $matchGroup, float $tolerance): array
{
    $rows = brDeleteFetchMatchRows($conn, $reconId, $matchGroup);
    if (!$rows) return ['bank_ids' => [], 'ledger_ids' => []];

    $bankDeltas = [];
    $ledgerDeltas = [];

    foreach ($rows as $row) {
        $bankId = (int)($row['bank_line_id'] ?? 0);
        $ledgerId = (int)($row['ledger_line_id'] ?? 0);
        $bankAmount = (float)($row['bank_amount'] ?? 0);
        $ledgerAmount = (float)($row['ledger_amount'] ?? 0);

        if ($bankAmount <= 0 && $bankId) {
            $fallback = $conn->query("SELECT ABS(amount) amount FROM bank_recon_bank_lines WHERE id={$bankId} AND recon_id={$reconId} LIMIT 1");
            $rowFallback = $fallback ? $fallback->fetch_assoc() : null;
            $bankAmount = (float)($rowFallback['amount'] ?? 0);
        }
        if ($ledgerAmount <= 0 && $ledgerId) {
            $fallback = $conn->query("SELECT ABS(amount) amount FROM bank_recon_ledger_lines WHERE id={$ledgerId} AND recon_id={$reconId} LIMIT 1");
            $rowFallback = $fallback ? $fallback->fetch_assoc() : null;
            $ledgerAmount = (float)($rowFallback['amount'] ?? 0);
        }
        if ($bankAmount <= 0) $bankAmount = $ledgerAmount;
        if ($ledgerAmount <= 0) $ledgerAmount = $bankAmount;

        if ($bankId) $bankDeltas[$bankId] = round(($bankDeltas[$bankId] ?? 0) + $bankAmount, 2);
        if ($ledgerId) $ledgerDeltas[$ledgerId] = round(($ledgerDeltas[$ledgerId] ?? 0) + $ledgerAmount, 2);
    }

    foreach ($bankDeltas as $lineId => $delta) {
        brReconApplyMatchedDelta($conn, 'bank_recon_bank_lines', (int)$lineId, -(float)$delta, $tolerance, null);
    }
    foreach ($ledgerDeltas as $lineId => $delta) {
        brReconApplyMatchedDelta($conn, 'bank_recon_ledger_lines', (int)$lineId, -(float)$delta, $tolerance, null);
    }

    $mg = $conn->real_escape_string($matchGroup);
    $conn->query("DELETE FROM bank_recon_matches WHERE recon_id={$reconId} AND match_group='{$mg}'");

    foreach (array_keys($bankDeltas) as $lineId) {
        brReconRefreshLineGroupAfterUnmatch($conn, 'bank_recon_bank_lines', $reconId, (int)$lineId, 'bank_line_id', $tolerance);
    }
    foreach (array_keys($ledgerDeltas) as $lineId) {
        brReconRefreshLineGroupAfterUnmatch($conn, 'bank_recon_ledger_lines', $reconId, (int)$lineId, 'ledger_line_id', $tolerance);
    }

    return ['bank_ids' => array_keys($bankDeltas), 'ledger_ids' => array_keys($ledgerDeltas)];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brDeleteFail('Route not found', 404);
    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';
    $body = brDeleteReadBody();

    $reconId = (int)($body['recon_id'] ?? 0);
    $source = strtolower(trim((string)($body['source'] ?? '')));
    $ids = brDeleteNormalizeIds($body['line_ids'] ?? ($body['line_id'] ?? null));

    if (!$reconId) brDeleteFail('recon_id is required.');
    if (!in_array($source, ['bank', 'ledger'], true)) brDeleteFail('source must be bank or ledger.');
    if (!$ids) brDeleteFail('At least one line is required.');

    brReconEnsureMatchingSchema($conn);

    $recon = $conn->query("SELECT id, COALESCE(tolerance_amount,0) tolerance_amount FROM bank_recons WHERE id={$reconId} LIMIT 1")->fetch_assoc();
    if (!$recon) brDeleteFail('Reconciliation not found.', 404);
    $tolerance = (float)($recon['tolerance_amount'] ?? 0);

    $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $idColumn = $source === 'bank' ? 'bank_line_id' : 'ledger_line_id';
    $idsSql = implode(',', array_map('intval', $ids));

    $res = $conn->query("SELECT id FROM {$table} WHERE recon_id={$reconId} AND id IN ({$idsSql})");
    $found = $res ? array_map('intval', array_column($res->fetch_all(MYSQLI_ASSOC), 'id')) : [];
    $missing = array_values(array_diff($ids, $found));
    if (!$found) brDeleteFail('No matching lines were found for deletion.', 404);
    if ($missing) brDeleteFail('Some selected lines were not found in this reconciliation: ' . implode(', ', $missing), 404);

    $groups = [];
    $groupRes = $conn->query("SELECT DISTINCT match_group FROM bank_recon_matches WHERE recon_id={$reconId} AND {$idColumn} IN ({$idsSql}) AND COALESCE(match_group,'') <> ''");
    if ($groupRes) {
        while ($row = $groupRes->fetch_assoc()) $groups[] = (string)$row['match_group'];
    }

    $conn->begin_transaction();

    $affectedBankIds = [];
    $affectedLedgerIds = [];
    foreach (array_values(array_unique($groups)) as $group) {
        $affected = brDeleteUnmatchGroup($conn, $reconId, $group, $tolerance);
        $affectedBankIds = array_merge($affectedBankIds, $affected['bank_ids']);
        $affectedLedgerIds = array_merge($affectedLedgerIds, $affected['ledger_ids']);
    }

    // Defensive cleanup in case old match rows did not carry a valid match_group.
    $conn->query("DELETE FROM bank_recon_matches WHERE recon_id={$reconId} AND {$idColumn} IN ({$idsSql})");

    $del = $conn->prepare("DELETE FROM {$table} WHERE recon_id=? AND id IN ({$idsSql})");
    if (!$del) brDeleteFail('Failed to prepare delete query: ' . $conn->error, 500);
    $del->bind_param('i', $reconId);
    if (!$del->execute()) brDeleteFail('Delete failed: ' . $del->error, 500);
    $deletedCount = $del->affected_rows;
    $del->close();

    $byEsc = $conn->real_escape_string((string)$by);
    $conn->query("UPDATE bank_recons SET updated_by='{$byEsc}' WHERE id={$reconId}");

    $summary = brReconRecomputeSummary($conn, $reconId);
    $conn->commit();

    echo json_encode([
        'status' => 'Success',
        'message' => $deletedCount === 1 ? ucfirst($source) . ' line deleted successfully.' : $deletedCount . ' ' . $source . ' lines deleted successfully.',
        'data' => [
            'deleted_count' => $deletedCount,
            'removed_match_groups' => count(array_unique($groups)),
            'affected_bank_lines' => array_values(array_unique(array_map('intval', $affectedBankIds))),
            'affected_ledger_lines' => array_values(array_unique(array_map('intval', $affectedLedgerIds))),
            'summary' => $summary,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
