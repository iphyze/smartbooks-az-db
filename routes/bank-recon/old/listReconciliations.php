<?php
/**
 * GET /bank-recon/list?page=1&limit=20&search=xxx
 *
 * Returns paginated list of reconciliations, newest first.
 * Search matches recon_number, company_name, bank_name, status.
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function brFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) brFail('Unauthorized', 401);

    $page   = max(1,   (int)($_GET['page']  ?? 1));
    $limit  = max(10, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');

    // ── Build WHERE clause ───────────────────────────────────────────────────
    $where  = '1=1';
    $params = [];
    $types  = '';

    if ($search !== '') {
        $where   .= ' AND (recon_number LIKE ? OR company_name LIKE ? OR bank_name LIKE ? OR status LIKE ?)';
        $like     = "%$search%";
        $params   = [$like, $like, $like, $like];
        $types    = 'ssss';
    }

    // ── Count ────────────────────────────────────────────────────────────────
    $cStmt = $conn->prepare("SELECT COUNT(*) c FROM bank_recons WHERE $where");
    if ($types) $cStmt->bind_param($types, ...$params);
    $cStmt->execute();
    $total = (int)$cStmt->get_result()->fetch_assoc()['c'];
    $cStmt->close();

    // ── Data ─────────────────────────────────────────────────────────────────
    $dStmt = $conn->prepare("SELECT * FROM bank_recons WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $allParams = array_merge($params, [$limit, $offset]);
    $allTypes  = $types . 'ii';
    $dStmt->bind_param($allTypes, ...$allParams);
    $dStmt->execute();
    $rows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dStmt->close();

    http_response_code(200);
    echo json_encode([
        'status'     => 'Success',
        'data'       => $rows,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
