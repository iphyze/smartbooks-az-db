<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authorization.php';

header('Content-Type: application/json');

function getRequiredQueryParam($key) {
    if (!isset($_GET[$key]) || trim($_GET[$key]) === '') {
        throw new Exception("Missing required parameter: '{$key}'.", 400);
    }
    return trim($_GET[$key]);
}

function validateDateParam($value, $label) {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new Exception("Invalid {$label}. Expected format: YYYY-MM-DD.", 400);
    }
}

function buildTimesheetConditions($datefrom, $dateto, $staff, $search, ?array $staffScope, &$params, &$types) {
    $conditions = "WHERE date BETWEEN ? AND ?";
    $params = [$datefrom, $dateto];
    $types = "ss";

    // Timesheet-only users are always scoped to their explicitly linked staff account.
    if ($staffScope !== null) {
        $conditions .= " AND staff_id = ?";
        $params[] = (int) $staffScope['staff_id'];
        $types .= "i";
    } elseif ($staff !== '' && $staff !== 'All Staff') {
        $conditions .= " AND staff_name = ?";
        $params[] = $staff;
        $types .= "s";
    }

    if ($search !== '') {
        $conditions .= " AND (staff_name LIKE ? OR clients_name LIKE ? OR project LIKE ? OR task LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= "ssss";
    }

    return $conditions;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    requireRole(
        $userData,
        [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER, SMARTBOOKS_ROLE_TIMESHEET],
        'You are not authorised to access Timesheet reporting.'
    );
    $staffScope = timesheetStaffScope($conn, $userData);

    $datefrom = getRequiredQueryParam('datefrom');
    $dateto = getRequiredQueryParam('dateto');
    validateDateParam($datefrom, 'datefrom');
    validateDateParam($dateto, 'dateto');

    if (strtotime($datefrom) > strtotime($dateto)) {
        throw new Exception("Invalid date range: datefrom cannot be later than dateto.", 400);
    }

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid pagination: limit and page must be positive integers.", 400);
    }

    if ($limit > 100) {
        $limit = 100;
    }

    $staff = isset($_GET['staff']) ? trim($_GET['staff']) : 'All Staff';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    if ($staffScope !== null) {
        $staff = (string) $staffScope['staff_name'];
    }
    $offset = ($page - 1) * $limit;

    $params = [];
    $types = '';
    $conditions = buildTimesheetConditions($datefrom, $dateto, $staff, $search, $staffScope, $params, $types);

    $countQuery = "SELECT COUNT(*) AS total FROM timesheet_table $conditions";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $summaryQuery = "
        SELECT
            COUNT(*) AS entry_count,
            COUNT(DISTINCT staff_id) AS staff_count,
            COUNT(DISTINCT clients_id) AS client_count,
            COUNT(DISTINCT NULLIF(project, '')) AS project_count,
            COUNT(DISTINCT date) AS days_logged,
            COALESCE(SUM(CAST(NULLIF(total_hours, '') AS DECIMAL(12,2))), 0) AS grand_total_hours,
            COALESCE(AVG(CAST(NULLIF(total_hours, '') AS DECIMAL(12,2))), 0) AS average_entry_hours
        FROM timesheet_table
        $conditions
    ";
    $summaryStmt = $conn->prepare($summaryQuery);
    if (!$summaryStmt) {
        throw new Exception("Failed to prepare summary query: " . $conn->error, 500);
    }
    $summaryStmt->bind_param($types, ...$params);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    $entryQuery = "
        SELECT
            id,
            staff_id,
            staff_name,
            date,
            clients_id,
            clients_name,
            project,
            task,
            start_time,
            finish_time,
            CAST(NULLIF(total_hours, '') AS DECIMAL(12,2)) AS total_hours
        FROM timesheet_table
        $conditions
        ORDER BY date DESC, staff_name ASC, start_time ASC, id ASC
        LIMIT ? OFFSET ?
    ";
    $entryParams = array_merge($params, [$limit, $offset]);
    $entryTypes = $types . 'ii';
    $entryStmt = $conn->prepare($entryQuery);
    if (!$entryStmt) {
        throw new Exception("Failed to prepare entry query: " . $conn->error, 500);
    }
    $entryStmt->bind_param($entryTypes, ...$entryParams);
    $entryStmt->execute();
    $entries = $entryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $entryStmt->close();

    $grouped = [];
    foreach ($entries as $entry) {
        $staffId = (int) $entry['staff_id'];
        $staffName = $entry['staff_name'];
        $key = $staffId . '|' . $staffName;
        $hours = (float) $entry['total_hours'];

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'staff_id' => $staffId,
                'staff_name' => $staffName,
                'entry_count' => 0,
                'client_ids' => [],
                'projects' => [],
                'days' => [],
                'total_hours' => 0,
                'first_entry_date' => $entry['date'],
                'last_entry_date' => $entry['date'],
                'entries' => []
            ];
        }

        $entry['staff_id'] = $staffId;
        $entry['clients_id'] = (int) $entry['clients_id'];
        $entry['total_hours'] = $hours;

        $grouped[$key]['entry_count'] += 1;
        $grouped[$key]['client_ids'][$entry['clients_id']] = true;
        if (trim($entry['project']) !== '') {
            $grouped[$key]['projects'][$entry['project']] = true;
        }
        $grouped[$key]['days'][$entry['date']] = true;
        $grouped[$key]['total_hours'] += $hours;
        if (strtotime($entry['date']) < strtotime($grouped[$key]['first_entry_date'])) {
            $grouped[$key]['first_entry_date'] = $entry['date'];
        }
        if (strtotime($entry['date']) > strtotime($grouped[$key]['last_entry_date'])) {
            $grouped[$key]['last_entry_date'] = $entry['date'];
        }
        $grouped[$key]['entries'][] = $entry;
    }

    $reportData = [];
    foreach ($grouped as $group) {
        $entryCount = (int) $group['entry_count'];
        $reportData[] = [
            'staff_id' => $group['staff_id'],
            'staff_name' => $group['staff_name'],
            'entry_count' => $entryCount,
            'client_count' => count($group['client_ids']),
            'project_count' => count($group['projects']),
            'days_logged' => count($group['days']),
            'total_hours' => round((float) $group['total_hours'], 2),
            'average_entry_hours' => $entryCount > 0 ? round(((float) $group['total_hours']) / $entryCount, 2) : 0,
            'first_entry_date' => $group['first_entry_date'],
            'last_entry_date' => $group['last_entry_date'],
            'entries' => $group['entries']
        ];
    }

    $grandTotalHours = (float) ($summary['grand_total_hours'] ?? 0);
    $daysLogged = (int) ($summary['days_logged'] ?? 0);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Paginated timesheet report fetched successfully',
        'data' => $reportData,
        'summary' => [
            'grand_total_hours' => $grandTotalHours,
            'entry_count' => (int) ($summary['entry_count'] ?? 0),
            'staff_count' => (int) ($summary['staff_count'] ?? 0),
            'client_count' => (int) ($summary['client_count'] ?? 0),
            'project_count' => (int) ($summary['project_count'] ?? 0),
            'days_logged' => $daysLogged,
            'average_entry_hours' => (float) ($summary['average_entry_hours'] ?? 0),
            'average_hours_per_day' => $daysLogged > 0 ? round($grandTotalHours / $daysLogged, 2) : 0
        ],
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            'datefrom' => $datefrom,
            'dateto' => $dateto,
            'staff_filter' => $staff,
            'search' => $search,
            'route_type' => 'paginated_entries',
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    error_log('Paginated timesheet report error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($e)
    ]);
}
