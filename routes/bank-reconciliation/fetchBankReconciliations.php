<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) throw new Exception('Unauthorized', 401);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $where = '1=1';
    $params = [];
    $types = '';
    if ($search !== '') {
        $where .= ' AND (reconciliation_number LIKE ? OR company_name LIKE ? OR bank_name LIKE ? OR account_number LIKE ? OR currency LIKE ? OR status LIKE ?)';
        $like = "%$search%";
        $params = [$like, $like, $like, $like, $like, $like];
        $types = 'ssssss';
    }
    $c = $conn->prepare("SELECT COUNT(*) total FROM bank_reconciliations WHERE $where");
    if ($types) $c->bind_param($types, ...$params);
    $c->execute();
    $total = (int)$c->get_result()->fetch_assoc()['total'];
    $c->close();
    $q = $conn->prepare("SELECT * FROM bank_reconciliations WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $types2 = $types . 'ii';
    $params2 = array_merge($params, [$limit, $offset]);
    $q->bind_param($types2, ...$params2);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    echo json_encode(['status' => 'Success', 'data' => $rows, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => ceil($total / $limit)]]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}
