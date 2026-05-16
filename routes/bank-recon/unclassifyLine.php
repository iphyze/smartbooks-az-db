<?php
/**
 * POST /bank-recon/unclassify-line
 *
 * Removes a line from its classification/category and returns it to
 * "Unmatched" status so it can be rematched after new lines are appended.
 *
 * Works for both individual lines and bulk (line_ids array).
 * Preserves auto_matched flag — just clears classification fields.
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function ucFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

function recomputeSummaryUC(mysqli $conn, int $id): void {
    $r = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    $classes = ["We Debit They Don't Credit" => 0.0, "They Debit We Don't Credit" => 0.0,
                "We Credit They Don't Debit" => 0.0, "They Credit We Don't Debit" => 0.0];
    foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $tbl) {
        $res = $conn->query("SELECT recon_classification, COALESCE(SUM(amount),0) amt
            FROM $tbl WHERE recon_id=$id AND match_status IN ('Classified','Bank-Only')
            AND recon_classification IS NOT NULL GROUP BY recon_classification");
        while ($row = $res->fetch_assoc())
            if (array_key_exists($row['recon_classification'], $classes))
                $classes[$row['recon_classification']] += (float)$row['amt'];
    }
    $adjLedger = round((float)$r['ledger_closing'] - $classes["They Debit We Don't Credit"] + $classes["They Credit We Don't Debit"], 2);
    $adjBank   = round((float)$r['bank_closing']   + $classes["We Debit They Don't Credit"] - $classes["We Credit They Don't Debit"], 2);
    $diff      = round($adjBank - $adjLedger, 2);
    $status    = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';
    $conn->query(sprintf("UPDATE bank_recons SET adjusted_bank_balance=%.2f, adjusted_ledger_balance=%.2f, unreconciled_difference=%.2f, status='%s' WHERE id=%d",
        $adjBank, $adjLedger, $diff, $conn->real_escape_string($status), $id));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ucFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) ucFail('Unauthorized', 401);

    $raw  = json_decode(file_get_contents('php://input'), true);
    $body = is_array($raw) ? $raw : $_POST;

    $reconId = (int)($body['recon_id'] ?? 0);
    $source  = strtolower(trim($body['source'] ?? ''));
    // Accept single line_id or array of line_ids
    $lineIds = [];
    if (isset($body['line_ids']) && is_array($body['line_ids'])) {
        $lineIds = array_map('intval', $body['line_ids']);
    } elseif (isset($body['line_id'])) {
        $lineIds = [(int)$body['line_id']];
    }

    if (!$reconId)                              ucFail('recon_id is required.');
    if (!in_array($source, ['bank', 'ledger'])) ucFail('source must be bank or ledger.');
    if (!$lineIds)                              ucFail('line_id or line_ids is required.');

    $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
    $ph    = implode(',', $lineIds);

    // Verify all lines belong to this recon and are classified/bank-only
    $rows = $conn->query(
        "SELECT id, match_status FROM $table WHERE recon_id=$reconId AND id IN ($ph)"
    )->fetch_all(MYSQLI_ASSOC);

    if (count($rows) !== count($lineIds)) ucFail('One or more lines not found for this reconciliation.', 404);

    foreach ($rows as $row) {
        if (!in_array($row['match_status'], ['Classified', 'Bank-Only']))
            ucFail("Line {$row['id']} is not classified — it cannot be unclassified (status: {$row['match_status']}).", 422);
    }

    // ── Clear classification fields and return to Unmatched ────────────
    $conn->query(
        "UPDATE $table SET
            match_status        = 'Unmatched',
            recon_classification = NULL,
            category_name       = NULL,
            bank_only_type      = NULL,
            suggested_dr_ledger = NULL,
            suggested_cr_ledger = NULL,
            journal_note        = NULL
         WHERE recon_id = $reconId AND id IN ($ph)
           AND match_status IN ('Classified', 'Bank-Only')"
    );

    $affected = $conn->affected_rows;
    recomputeSummaryUC($conn, $reconId);

    echo json_encode([
        'status'  => 'Success',
        'message' => "$affected line(s) removed from classification and returned to Unmatched.",
        'data'    => ['unclassified' => $affected],
    ]);

} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
