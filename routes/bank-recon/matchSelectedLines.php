<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';

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

function normalizeBool($value): bool {
    if (is_bool($value)) return $value;
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'y', 'on', 'partial'], true);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);

    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';

    $body = readBody();

    $reconId = (int)($body['recon_id'] ?? 0);
    $bankIds = normalizeIds($body['bank_line_ids'] ?? []);
    $ledgerIds = normalizeIds($body['ledger_line_ids'] ?? []);
    $allowPartial = normalizeBool($body['allow_partial'] ?? false);
    $note = trim((string)($body['match_note'] ?? ''));

    if (!$reconId || !$bankIds || !$ledgerIds) {
        brFail('recon_id, bank_line_ids and ledger_line_ids are required.');
    }

    brReconEnsureMatchingSchema($conn);

    $stmt = $conn->prepare('SELECT id, COALESCE(tolerance_amount, 0) tolerance FROM bank_recons WHERE id = ? LIMIT 1');
    if (!$stmt) brFail('Failed to prepare reconciliation lookup: ' . $conn->error, 500);
    $stmt->bind_param('i', $reconId);
    $stmt->execute();
    $recon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$recon) brFail('Reconciliation not found', 404);
    $tolerance = (float)$recon['tolerance'];

    $bankLines = brReconFetchSelectedLines($conn, 'bank_recon_bank_lines', $reconId, $bankIds);
    $ledgerLines = brReconFetchSelectedLines($conn, 'bank_recon_ledger_lines', $reconId, $ledgerIds);

    if (count($bankLines) !== count($bankIds)) brFail('One or more selected bank lines were not found.', 404);
    if (count($ledgerLines) !== count($ledgerIds)) brFail('One or more selected ledger lines were not found.', 404);

    $plan = brReconBuildAllocations($bankLines, $ledgerLines, $allowPartial, $tolerance);
    $group = ($plan['is_partial'] ? 'PM-' : 'MAN-') . date('YmdHis') . '-' . random_int(100, 999);
    $groupE = $conn->real_escape_string($group);
    $byE = $conn->real_escape_string($by);
    $noteE = $conn->real_escape_string($note);

    $conn->begin_transaction();

    $mIns = $conn->prepare("INSERT INTO bank_recon_matches
        (recon_id, match_group, bank_line_id, ledger_line_id, bank_allocated_amount, ledger_allocated_amount, is_partial, match_note, match_type, confidence, amount_difference, day_difference, matched_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Manual', ?, 0, ?, ?)");
    if (!$mIns) brFail('Failed to prepare match insert: ' . $conn->error, 500);

    $lineDeltas = [
        'bank' => [],
        'ledger' => [],
    ];

    foreach ($plan['allocations'] as $allocation) {
        $bankLineId = (int)$allocation['bank_line_id'];
        $ledgerLineId = (int)$allocation['ledger_line_id'];
        $amount = (float)$allocation['amount'];
        $dayDiff = (int)$allocation['day_difference'];
        $confidence = max(60, 100 - ($dayDiff * 5) - ($plan['is_partial'] ? 10 : 0));
        $isPartial = $plan['is_partial'] ? 1 : 0;

        $mIns->bind_param('isiiddisiis', $reconId, $group, $bankLineId, $ledgerLineId, $amount, $amount, $isPartial, $note, $confidence, $dayDiff, $by);
        $mIns->execute();

        $lineDeltas['bank'][$bankLineId] = ($lineDeltas['bank'][$bankLineId] ?? 0) + $amount;
        $lineDeltas['ledger'][$ledgerLineId] = ($lineDeltas['ledger'][$ledgerLineId] ?? 0) + $amount;
    }
    $mIns->close();

    foreach ($lineDeltas['bank'] as $lineId => $delta) {
        brReconApplyMatchedDelta($conn, 'bank_recon_bank_lines', (int)$lineId, (float)$delta, $tolerance, $group);
    }
    foreach ($lineDeltas['ledger'] as $lineId => $delta) {
        brReconApplyMatchedDelta($conn, 'bank_recon_ledger_lines', (int)$lineId, (float)$delta, $tolerance, $group);
    }

    $summary = brReconRecomputeSummary($conn, $reconId);
    $conn->commit();

    echo json_encode([
        'status' => 'Success',
        'message' => $plan['is_partial'] ? 'Partial/many-to-many match allocated successfully.' : 'Selected lines matched successfully.',
        'data' => [
            'match_group' => $group,
            'bank_total' => $plan['bank_total'],
            'ledger_total' => $plan['ledger_total'],
            'matched_total' => $plan['matched_total'],
            'difference' => $plan['difference'],
            'is_partial' => $plan['is_partial'],
            'allocations' => $plan['allocations'],
            'summary' => $summary,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) @mysqli_rollback($conn);
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
