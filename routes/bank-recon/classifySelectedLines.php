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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);

    $user = requireAdmin();
    $body = readBody();

    $reconId = (int)($body['recon_id'] ?? 0);
    $source = strtolower(trim($body['source'] ?? ''));
    $lineIds = normalizeIds($body['line_ids'] ?? []);
    $category = trim($body['category'] ?? '');
    $classification = trim($body['classification'] ?? '');
    $drLedger = trim($body['dr_ledger'] ?? '');
    $crLedger = trim($body['cr_ledger'] ?? '');
    $note = trim($body['note'] ?? '');

    if (!$reconId || !$source || !$lineIds || !$category || !$classification) {
        brFail('recon_id, source, line_ids, category and classification are required.');
    }

    if (!in_array($source, ['bank', 'ledger'])) brFail('source must be bank or ledger.');

    brReconEnsureClassificationMetadataSchema($conn);
    $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $ph = implode(',', array_fill(0, count($lineIds), '?'));

    $validClasses = [
        "We Debit They Don't Credit",
        "They Debit We Don't Credit",
        "We Credit They Don't Debit",
        "They Credit We Don't Debit",
        "Prior Period Item",
    ];
    if (!in_array($classification, $validClasses, true)) {
        brFail('Valid reconciliation classification is required.');
    }

    if ($source === 'bank') {
        $types = 'sssssssi' . str_repeat('i', count($lineIds));
        $params = array_merge(['Classified', $category, $category, $classification, $drLedger, $crLedger, $note, $reconId], $lineIds);
        $stmt = $conn->prepare("UPDATE {$table}
            SET match_status = ?,
                bank_only_type = ?,
                category_name = ?,
                recon_classification = ?,
                suggested_dr_ledger = ?,
                suggested_cr_ledger = ?,
                journal_note = ?,
                classification_origin = 'manual',
                classification_rule_id = NULL,
                classification_locked = 1
            WHERE recon_id = ? AND id IN ($ph) AND match_status <> 'Matched'");
    } else {
        $types = 'ssssssi' . str_repeat('i', count($lineIds));
        $params = array_merge(['Classified', $category, $classification, $drLedger, $crLedger, $note, $reconId], $lineIds);
        $stmt = $conn->prepare("UPDATE {$table}
            SET match_status = ?,
                category_name = ?,
                recon_classification = ?,
                suggested_dr_ledger = ?,
                suggested_cr_ledger = ?,
                journal_note = ?,
                classification_origin = 'manual',
                classification_rule_id = NULL,
                classification_locked = 1
            WHERE recon_id = ? AND id IN ($ph) AND match_status <> 'Matched'");
    }

    if (!$stmt) brFail('Failed to prepare classification update: ' . $conn->error, 500);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    $learned = function_exists('brReconLearnFromClassifiedLines')
        ? brReconLearnFromClassifiedLines($conn, $reconId, $source, $lineIds, $user['email'] ?? $user['username'] ?? 'system')
        : 0;
    $summary = function_exists('brReconRecomputeSummary') ? brReconRecomputeSummary($conn, $reconId) : null;

    echo json_encode([
        'status' => 'Success',
        'message' => 'Selected lines classified successfully',
        'data' => [
            'source' => $source,
            'affected_rows' => $affected,
            'category' => $category,
            'classification' => $classification,
            'learned_patterns' => $learned,
            'summary' => $summary,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
