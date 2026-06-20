<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);

$search = trim((string) ($_GET['search'] ?? ''));
$currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
$includeInactive = filter_var($_GET['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
$limit = max(1, min((int) ($_GET['limit'] ?? 100), 200));
$like = '%' . $search . '%';

$sql = 'SELECT id, service_code, service_name, description, currency, default_amount,
               discount_percent, vat_percent, wht_percent, is_active,
               created_at, created_by, updated_at, updated_by
        FROM invoice_service_catalogue
        WHERE (? = \'\' OR service_name LIKE ? OR service_code LIKE ? OR description LIKE ?)
          AND (? = \'\' OR currency = ?)
          AND (? = 1 OR is_active = 1)
        ORDER BY is_active DESC, service_name ASC
        LIMIT ?';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    throw new RuntimeException('Unable to load the service catalogue.', 500);
}
$includeInactiveInt = $includeInactive ? 1 : 0;
$stmt->bind_param('ssssssii', $search, $like, $like, $like, $currency, $currency, $includeInactiveInt, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$rows = array_map(static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['default_amount'] = (float) $row['default_amount'];
    $row['discount_percent'] = (float) $row['discount_percent'];
    $row['vat_percent'] = (float) $row['vat_percent'];
    $row['wht_percent'] = (float) $row['wht_percent'];
    $row['is_active'] = (bool) $row['is_active'];
    return $row;
}, $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Invoice services fetched successfully.',
    'data' => $rows,
]);
