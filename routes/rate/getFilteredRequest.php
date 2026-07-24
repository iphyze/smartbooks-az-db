<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $integrity = (string) ($userData['integrity'] ?? '');
    if (!in_array($integrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can access currency rates.', 403);
    }

    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;
    $search = trim((string) ($_GET['search'] ?? ''));

    $allowedSortFields = [
        'id', 'effective_date', 'ngn_rate', 'usd_rate', 'gbp_rate', 'eur_rate',
        'rate_source', 'recorded_at', 'created_by',
    ];
    $requestedSort = (string) ($_GET['sortBy'] ?? 'effective_date');
    $sortBy = in_array($requestedSort, $allowedSortFields, true)
        ? $requestedSort
        : ($requestedSort === 'created_at' ? 'effective_date' : 'effective_date');
    $sortOrder = strtoupper((string) ($_GET['sortOrder'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $where = ' WHERE 1=1';
    $params = [];
    $types = '';
    if ($search !== '') {
        $where .= ' AND (effective_date LIKE ? OR rate_source LIKE ? OR source_reference LIKE ?
                     OR recorded_by LIKE ? OR created_by LIKE ?
                     OR ngn_cur LIKE ? OR usd_cur LIKE ? OR gbp_cur LIKE ? OR eur_cur LIKE ?
                     OR CAST(ngn_rate AS CHAR) LIKE ? OR CAST(usd_rate AS CHAR) LIKE ?
                     OR CAST(gbp_rate AS CHAR) LIKE ? OR CAST(eur_rate AS CHAR) LIKE ?)';
        $like = "%{$search}%";
        $params = array_fill(0, 13, $like);
        $types = str_repeat('s', 13);
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM currency_table {$where}");
    if (!$countStmt) {
        throw new RuntimeException('Unable to load rates. Apply the historical closing-rate migration first.', 503);
    }
    if ($params) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $dataSql = "SELECT id, effective_date, ngn_cur, ngn_rate, usd_cur, usd_rate,
                       gbp_cur, gbp_rate, eur_cur, eur_rate, rate_source, source_reference,
                       recorded_at, recorded_by, created_at, created_by, updated_at, updated_by
                FROM currency_table {$where}
                ORDER BY {$sortBy} {$sortOrder}, id {$sortOrder}
                LIMIT ? OFFSET ?";
    $dataStmt = $conn->prepare($dataSql);
    if (!$dataStmt) {
        throw new RuntimeException('Unable to load rates. Apply the historical closing-rate migration first.', 503);
    }
    $dataTypes = $types . 'ii';
    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $data = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Currency rates fetched successfully.',
        'data' => $data,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'search' => $search,
        ],
    ], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    error_log('Filtered Rates Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($error)]);
}
