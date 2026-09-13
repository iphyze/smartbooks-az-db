<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/cost_center_access_helpers.php';
header('Content-Type: application/json');

function brFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

// POST /bank-recon/delete
// Body (JSON or FormData): recon_id

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brFail('Route not found', 404);
    $user = authenticateUser();
    requirePermission($conn, $user, 'bank_reconciliation.delete', 'You do not have permission to perform this bank reconciliation action.');
    $raw  = json_decode(file_get_contents('php://input'), true);
    $body = is_array($raw) ? $raw : $_POST;
    $id   = (int)($body['recon_id'] ?? 0);
    if (!$id) brFail('recon_id is required.');

    $recon = requireBankReconCostCenterAccess($conn, $user, $id, true);

    // Cascading FK constraints on bank_recon_bank_lines, bank_recon_ledger_lines,
    // and bank_recon_matches will delete child rows automatically.
    if (!$conn->query("DELETE FROM bank_recons WHERE id=$id")) {
        brFail('Failed to delete reconciliation: ' . $conn->error, 500);
    }

    echo json_encode([
        'status'  => 'Success',
        'message' => 'Reconciliation ' . $recon['recon_number'] . ' deleted successfully.',
    ]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
