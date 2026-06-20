<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json');

function brFail($message, $code = 400) { throw new Exception($message, $code); }

function readBody() {
    $raw = json_decode(file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function normalizeIds($value) {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : explode(',', $value);
    }
    if (!is_array($value)) return [];
    $ids = [];
    foreach ($value as $v) {
        $id = (int)$v;
        if ($id > 0) $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function totalSelected(mysqli $conn, string $table, int $reconId, array $ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = 'i' . str_repeat('i', count($ids));
    $params = array_merge([$reconId], $ids);

    $stmt = $conn->prepare("SELECT COALESCE(SUM(ABS(amount)),0) total_amount FROM {$table} WHERE recon_id = ? AND id IN ($ph) AND match_status <> 'Matched'");
    if (!$stmt) brFail('Failed to prepare total query: ' . $conn->error, 500);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['total_amount'] ?? 0);
}

function directionTotals(mysqli $conn, string $table, int $reconId, array $ids): array {
    $totals = ['OUT' => 0.0, 'IN' => 0.0];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = 'i' . str_repeat('i', count($ids));
    $params = array_merge([$reconId], $ids);

    $stmt = $conn->prepare("SELECT direction, COALESCE(SUM(ABS(amount)),0) total_amount
        FROM {$table}
        WHERE recon_id = ? AND id IN ($ph) AND match_status <> 'Matched'
        GROUP BY direction");
    if (!$stmt) brFail('Failed to prepare direction-total query: ' . $conn->error, 500);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $direction = strtoupper((string)($row['direction'] ?? ''));
        if (array_key_exists($direction, $totals)) {
            $totals[$direction] = (float)($row['total_amount'] ?? 0);
        }
    }
    $stmt->close();

    return $totals;
}

function markMatched(mysqli $conn, string $table, int $reconId, array $ids, string $group) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = 'si' . str_repeat('i', count($ids));
    $params = array_merge([$group, $reconId], $ids);

    // Keep category/classification metadata after matching.
    // Excel category sheets use this retained metadata as attachment/reference support,
    // while reconciliation totals still exclude matched rows by match_status.
    $stmt = $conn->prepare("UPDATE {$table}
        SET match_status = 'Matched',
            match_group = ?
        WHERE recon_id = ? AND id IN ($ph)");

    if (!$stmt) brFail('Failed to prepare update query: ' . $conn->error, 500);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);

    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';

    $body = readBody();

    $reconId = (int)($body['recon_id'] ?? 0);
    $bankIds = normalizeIds($body['bank_line_ids'] ?? []);
    $ledgerIds = normalizeIds($body['ledger_line_ids'] ?? []);

    if (!$reconId || !$bankIds || !$ledgerIds) {
        brFail('recon_id, bank_line_ids and ledger_line_ids are required.');
    }

    $stmt = $conn->prepare("SELECT id, COALESCE(tolerance_amount, 0) tolerance FROM bank_recons WHERE id = ? LIMIT 1");
    if (!$stmt) brFail('Failed to prepare reconciliation lookup: ' . $conn->error, 500);
    $stmt->bind_param('i', $reconId);
    $stmt->execute();
    $recon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$recon) brFail('Reconciliation not found', 404);

    $bankTotal = totalSelected($conn, 'bank_recon_bank_lines', $reconId, $bankIds);
    $ledgerTotal = totalSelected($conn, 'bank_recon_ledger_lines', $reconId, $ledgerIds);
    $difference = round($bankTotal - $ledgerTotal, 2);

    if (abs($difference) > (float)$recon['tolerance']) {
        brFail('Selected bank and ledger totals do not balance. Difference: ' . number_format($difference, 2), 422);
    }

    // Validate the accounting-side pairing as well as the grand total:
    // OUT means Bank Debit and Ledger Credit; IN means Bank Credit and Ledger Debit.
    $bankByDirection = directionTotals($conn, 'bank_recon_bank_lines', $reconId, $bankIds);
    $ledgerByDirection = directionTotals($conn, 'bank_recon_ledger_lines', $reconId, $ledgerIds);
    $outDifference = round($bankByDirection['OUT'] - $ledgerByDirection['OUT'], 2);
    $inDifference = round($bankByDirection['IN'] - $ledgerByDirection['IN'], 2);
    $tolerance = (float)$recon['tolerance'];

    if (abs($outDifference) > $tolerance || abs($inDifference) > $tolerance) {
        brFail(
            'Selected lines must respect the accounting pairing: Bank Debit must match Ledger Credit, and Bank Credit must match Ledger Debit. ' .
            'Bank Debit vs Ledger Credit difference: ' . number_format($outDifference, 2) . '; ' .
            'Bank Credit vs Ledger Debit difference: ' . number_format($inDifference, 2) . '.',
            422
        );
    }

    $group = 'MAN-' . date('YmdHis') . '-' . random_int(100, 999);

    $conn->begin_transaction();

    markMatched($conn, 'bank_recon_bank_lines', $reconId, $bankIds, $group);
    markMatched($conn, 'bank_recon_ledger_lines', $reconId, $ledgerIds, $group);

    // Insert one match row per bank_line × ledger_line combination under the shared group.
    // The table has single bank_line_id / ledger_line_id columns (FK int, not JSON).
    $amtDiff = round(abs($bankTotal - $ledgerTotal), 2);
    $mIns = $conn->prepare("INSERT INTO bank_recon_matches
        (recon_id, match_group, bank_line_id, ledger_line_id, match_type, confidence, amount_difference, day_difference, matched_by)
        VALUES (?, ?, ?, ?, 'Manual', 75, ?, 0, ?)");

    if ($mIns) {
        foreach ($bankIds as $bid) {
            foreach ($ledgerIds as $lid) {
                $mIns->bind_param('isiids', $reconId, $group, $bid, $lid, $amtDiff, $by);
                $mIns->execute();
            }
        }
        $mIns->close();
    }

    $conn->commit();

    echo json_encode([
        'status' => 'Success',
        'message' => 'Selected lines matched successfully',
        'data' => [
            'match_group' => $group,
            'bank_total' => $bankTotal,
            'ledger_total' => $ledgerTotal,
            'difference' => $difference,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) @mysqli_rollback($conn);
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}