<?php
declare(strict_types=1);

require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
}

$user = authenticateUser();
if (!in_array($user['integrity'] ?? '', ['Admin', 'Controller'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'You are not authorised to export activity logs.'], 403);
}

$moduleExpression = activityLogModuleExpression('l');
$actionTypeExpression = activityLogActionTypeExpression('l');
$params = [];
$types = '';
$where = activityLogFilterSql($user, $_GET, $params, $types);

$sql = "SELECT l.created_at,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(a.fname, ''), ' ', COALESCE(a.lname, ''))), ''), l.created_by) AS actor,
        a.email AS actor_email,
        a.integrity AS role,
        {$moduleExpression} AS module,
        {$actionTypeExpression} AS action_type,
        COALESCE(NULLIF(l.description, ''), l.action) AS description,
        l.entity_type, l.entity_id, l.ip_address, l.request_method, l.request_path,
        COALESCE(NULLIF(l.severity, ''), 'info') AS severity
    FROM logs l
    LEFT JOIN admin_table a ON a.id = l.userId
    WHERE {$where}
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 5000";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare activity-log export.', 500);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$filename = 'smartbooks_activity_logs_' . date('Y-m-d_H-i-s') . '.csv';
header_remove('Content-Type');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'wb');
fputcsv($output, ['Date & time', 'Actor', 'Email', 'Role', 'Module', 'Action type', 'Description', 'Entity type', 'Entity ID', 'IP address', 'Method', 'Request path', 'Severity']);
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['created_at'], $row['actor'], $row['actor_email'], $row['role'],
        $row['module'], $row['action_type'], $row['description'], $row['entity_type'],
        $row['entity_id'], $row['ip_address'], $row['request_method'], $row['request_path'],
        $row['severity'],
    ]);
}
fclose($output);
$stmt->close();
exit;
