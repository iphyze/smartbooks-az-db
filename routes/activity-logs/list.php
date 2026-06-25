<?php
declare(strict_types=1);

require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
}

$user = authenticateUser();
if (!in_array($user['integrity'] ?? '', ['Admin', 'Controller'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'You are not authorised to view activity logs.'], 403);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(100, max(5, (int) ($_GET['limit'] ?? 15)));
$offset = ($page - 1) * $limit;
$allowedSort = ['created_at', 'created_by', 'module', 'action_type'];
$sortBy = in_array($_GET['sortBy'] ?? '', $allowedSort, true) ? (string) $_GET['sortBy'] : 'created_at';
$sortOrder = strtoupper((string) ($_GET['sortOrder'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

$moduleExpression = activityLogModuleExpression('l');
$actionTypeExpression = activityLogActionTypeExpression('l');
$params = [];
$types = '';
$where = activityLogFilterSql($user, $_GET, $params, $types);

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM logs l WHERE {$where}");
if (!$countStmt) {
    throw new RuntimeException('Unable to prepare activity-log count.', 500);
}
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$sortExpression = match ($sortBy) {
    'module' => $moduleExpression,
    'action_type' => $actionTypeExpression,
    'created_by' => 'l.created_by',
    default => 'l.created_at',
};

$dataParams = $params;
$dataTypes = $types . 'ii';
$dataParams[] = $limit;
$dataParams[] = $offset;

$dataSql = "SELECT
        l.id, l.userId, l.action, l.created_by, l.created_at,
        {$moduleExpression} AS module,
        {$actionTypeExpression} AS action_type,
        l.entity_type, l.entity_id,
        COALESCE(NULLIF(l.description, ''), l.action) AS description,
        l.metadata_json, l.before_json, l.after_json,
        l.ip_address, l.user_agent, l.request_method, l.request_path,
        COALESCE(NULLIF(l.severity, ''), 'info') AS severity,
        NULLIF(TRIM(CONCAT(COALESCE(a.fname, ''), ' ', COALESCE(a.lname, ''))), '') AS actor_name,
        a.email AS actor_email,
        a.integrity AS actor_role
    FROM logs l
    LEFT JOIN admin_table a ON a.id = l.userId
    WHERE {$where}
    ORDER BY {$sortExpression} {$sortOrder}, l.id {$sortOrder}
    LIMIT ? OFFSET ?";

$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) {
    throw new RuntimeException('Unable to prepare activity-log query.', 500);
}
$dataStmt->bind_param($dataTypes, ...$dataParams);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();
$rows = array_map('normaliseActivityRow', $rows);

$scopeParams = [];
$scopeTypes = '';
$scopeWhere = activityLogFilterSql($user, [], $scopeParams, $scopeTypes);
$summarySql = "SELECT
        COUNT(*) AS total_all,
        SUM(CASE WHEN l.created_at >= CURDATE() THEN 1 ELSE 0 END) AS today_count,
        COUNT(DISTINCT CASE WHEN l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN l.userId END) AS active_users,
        SUM(CASE WHEN COALESCE(NULLIF(l.severity, ''), 'info') = 'critical' THEN 1 ELSE 0 END) AS critical_count
    FROM logs l WHERE {$scopeWhere}";
$summaryStmt = $conn->prepare($summarySql);
if ($scopeParams) {
    $summaryStmt->bind_param($scopeTypes, ...$scopeParams);
}
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$modulesSql = "SELECT DISTINCT {$moduleExpression} AS value FROM logs l WHERE {$scopeWhere} ORDER BY value";
$moduleStmt = $conn->prepare($modulesSql);
if ($scopeParams) {
    $moduleStmt->bind_param($scopeTypes, ...$scopeParams);
}
$moduleStmt->execute();
$modules = array_values(array_filter(array_column($moduleStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'value')));
$moduleStmt->close();

$actionsSql = "SELECT DISTINCT {$actionTypeExpression} AS value FROM logs l WHERE {$scopeWhere} ORDER BY value";
$actionStmt = $conn->prepare($actionsSql);
if ($scopeParams) {
    $actionStmt->bind_param($scopeTypes, ...$scopeParams);
}
$actionStmt->execute();
$actionTypes = array_values(array_filter(array_column($actionStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'value')));
$actionStmt->close();

$userScopeCondition = ($user['integrity'] ?? '') === 'Controller'
    ? 'WHERE ' . activityLogControllerScope($moduleExpression)
    : '';
$usersSql = "SELECT DISTINCT l.userId AS id,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(a.fname, ''), ' ', COALESCE(a.lname, ''))), ''), l.created_by) AS label
    FROM logs l LEFT JOIN admin_table a ON a.id = l.userId {$userScopeCondition}
    ORDER BY label";
$userResult = $conn->query($usersSql);
$userRows = $userResult instanceof mysqli_result ? $userResult->fetch_all(MYSQLI_ASSOC) : [];
foreach ($userRows as &$option) {
    $option['id'] = (int) $option['id'];
}
unset($option);

jsonResponse([
    'status' => 'Success',
    'message' => 'Activity logs fetched successfully.',
    'data' => $rows,
    'meta' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => max(1, (int) ceil($total / $limit)),
        'sortBy' => $sortBy,
        'sortOrder' => $sortOrder,
    ],
    'summary' => [
        'total_all' => (int) ($summary['total_all'] ?? 0),
        'today_count' => (int) ($summary['today_count'] ?? 0),
        'active_users' => (int) ($summary['active_users'] ?? 0),
        'critical_count' => (int) ($summary['critical_count'] ?? 0),
    ],
    'filter_options' => [
        'modules' => $modules,
        'action_types' => $actionTypes,
        'users' => $userRows,
    ],
]);
