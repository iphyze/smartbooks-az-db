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

    $search = trim((string) ($_GET['search'] ?? ''));
    $sql = 'SELECT id, effective_date, ngn_cur, ngn_rate, usd_cur, usd_rate,
                   gbp_cur, gbp_rate, eur_cur, eur_rate, rate_source, source_reference,
                   recorded_at, recorded_by, created_at, created_by, updated_at, updated_by
            FROM currency_table';
    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= ' WHERE effective_date LIKE ? OR rate_source LIKE ? OR source_reference LIKE ?
                  OR recorded_by LIKE ? OR created_by LIKE ?
                  OR ngn_cur LIKE ? OR usd_cur LIKE ? OR gbp_cur LIKE ? OR eur_cur LIKE ?
                  OR CAST(ngn_rate AS CHAR) LIKE ? OR CAST(usd_rate AS CHAR) LIKE ?
                  OR CAST(gbp_rate AS CHAR) LIKE ? OR CAST(eur_rate AS CHAR) LIKE ?';
        $like = "%{$search}%";
        $params = array_fill(0, 13, $like);
        $types = str_repeat('s', 13);
    }

    $sql .= ' ORDER BY effective_date DESC, id DESC LIMIT 250';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load rates. Apply the historical closing-rate migration first.', 503);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode(['status' => 'Success', 'data' => $data], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    error_log('Fetch Rate Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($error)]);
}
