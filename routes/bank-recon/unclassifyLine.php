<?php

declare(strict_types=1);
/**
 * POST /bank-recon/unclassify-line
 *
 * Removes bank or ledger lines from their reconciliation classification and
 * returns the outstanding balance to the matching pool. Bank-only metadata is
 * only cleared on bank lines because ledger lines do not have bank_only_type in
 * the AcctLab schema.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';

header('Content-Type: application/json');

function ucFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

function ucReadBody(): array
{
    $raw = json_decode(file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function ucNormalizeIds($value): array
{
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ucFail('Route not found', 404);

    requireAdmin();
    $body = ucReadBody();

    $reconId = (int)($body['recon_id'] ?? 0);
    $source  = strtolower(trim((string)($body['source'] ?? '')));
    $lineIds = ucNormalizeIds($body['line_ids'] ?? ($body['line_id'] ?? []));

    if (!$reconId) ucFail('recon_id is required.');
    if (!in_array($source, ['bank', 'ledger'], true)) ucFail('source must be bank or ledger.');
    if (!$lineIds) ucFail('line_id or line_ids is required.');

    brReconEnsureMatchingSchema($conn);
    brReconEnsureClassificationMetadataSchema($conn);

    $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $ph = implode(',', array_fill(0, count($lineIds), '?'));
    $types = 'i' . str_repeat('i', count($lineIds));
    $params = array_merge([$reconId], $lineIds);

    $stmt = $conn->prepare("SELECT id, match_status FROM {$table} WHERE recon_id=? AND id IN ({$ph})");
    if (!$stmt) ucFail('Failed to prepare line lookup: ' . $conn->error, 500);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($rows) !== count($lineIds)) ucFail('One or more lines not found for this reconciliation.', 404);

    foreach ($rows as $row) {
        if (!in_array((string)$row['match_status'], ['Classified', 'Bank-Only'], true)) {
            ucFail("Line {$row['id']} is not classified — it cannot be unclassified (status: {$row['match_status']}).", 422);
        }
    }

    if ($source === 'bank') {
        $sql = "UPDATE bank_recon_bank_lines SET
                    match_status='Unmatched',
                    recon_classification=NULL,
                    category_name=NULL,
                    bank_only_type=NULL,
                    suggested_dr_ledger=NULL,
                    suggested_cr_ledger=NULL,
                    journal_note=NULL,
                    classification_origin=NULL,
                    classification_rule_id=NULL,
                    classification_locked=0
                WHERE recon_id=? AND id IN ({$ph})
                  AND match_status IN ('Classified','Bank-Only')";
    } else {
        $sql = "UPDATE bank_recon_ledger_lines SET
                    match_status='Unmatched',
                    recon_classification=NULL,
                    category_name=NULL,
                    suggested_dr_ledger=NULL,
                    suggested_cr_ledger=NULL,
                    journal_note=NULL,
                    classification_origin=NULL,
                    classification_rule_id=NULL,
                    classification_locked=0
                WHERE recon_id=? AND id IN ({$ph})
                  AND match_status IN ('Classified','Bank-Only')";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) ucFail('Failed to prepare unclassify update: ' . $conn->error, 500);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    $summary = brReconRecomputeSummary($conn, $reconId);

    echo json_encode([
        'status'  => 'Success',
        'message' => "$affected line(s) removed from classification and returned to Unmatched.",
        'data'    => [
            'source' => $source,
            'unclassified' => $affected,
            'summary' => $summary,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
