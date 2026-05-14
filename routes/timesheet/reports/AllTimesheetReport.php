<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

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

function buildTimesheetConditions($datefrom, $dateto, $staff, $search, &$params, &$types) {
    $conditions = "WHERE date BETWEEN ? AND ?";
    $params = [$datefrom, $dateto];
    $types = "ss";

    if ($staff !== '' && $staff !== 'All Staff') {
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
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    $datefrom = getRequiredQueryParam('datefrom');
    $dateto = getRequiredQueryParam('dateto');
    validateDateParam($datefrom, 'datefrom');
    validateDateParam($dateto, 'dateto');

    if (strtotime($datefrom) > strtotime($dateto)) {
        throw new Exception("Invalid date range: datefrom cannot be later than dateto.", 400);
    }

    $staff = isset($_GET['staff']) ? trim($_GET['staff']) : 'All Staff';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $params = [];
    $types = '';
    $conditions = buildTimesheetConditions($datefrom, $dateto, $staff, $search, $params, $types);

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

    $staffQuery = "
        SELECT
            staff_id,
            staff_name,
            COUNT(*) AS entry_count,
            COUNT(DISTINCT clients_id) AS client_count,
            COUNT(DISTINCT NULLIF(project, '')) AS project_count,
            COUNT(DISTINCT date) AS days_logged,
            COALESCE(SUM(CAST(NULLIF(total_hours, '') AS DECIMAL(12,2))), 0) AS total_hours,
            COALESCE(AVG(CAST(NULLIF(total_hours, '') AS DECIMAL(12,2))), 0) AS average_entry_hours,
            MIN(date) AS first_entry_date,
            MAX(date) AS last_entry_date
        FROM timesheet_table
        $conditions
        GROUP BY staff_id, staff_name
        ORDER BY staff_name ASC
    ";

    $staffStmt = $conn->prepare($staffQuery);
    if (!$staffStmt) {
        throw new Exception("Failed to prepare staff query: " . $conn->error, 500);
    }
    $staffStmt->bind_param($types, ...$params);
    $staffStmt->execute();
    $staffRows = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $staffStmt->close();

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
        ORDER BY staff_name ASC, date ASC, start_time ASC, id ASC
    ";

    $entryStmt = $conn->prepare($entryQuery);
    if (!$entryStmt) {
        throw new Exception("Failed to prepare entry query: " . $conn->error, 500);
    }
    $entryStmt->bind_param($types, ...$params);
    $entryStmt->execute();
    $entries = $entryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $entryStmt->close();

    $entriesByStaff = [];
    foreach ($entries as $entry) {
        $key = (string) $entry['staff_id'] . '|' . $entry['staff_name'];
        if (!isset($entriesByStaff[$key])) {
            $entriesByStaff[$key] = [];
        }
        $entry['total_hours'] = (float) $entry['total_hours'];
        $entriesByStaff[$key][] = $entry;
    }

    $reportData = [];
    foreach ($staffRows as $staffRow) {
        $key = (string) $staffRow['staff_id'] . '|' . $staffRow['staff_name'];
        $reportData[] = [
            'staff_id' => (int) $staffRow['staff_id'],
            'staff_name' => $staffRow['staff_name'],
            'entry_count' => (int) $staffRow['entry_count'],
            'client_count' => (int) $staffRow['client_count'],
            'project_count' => (int) $staffRow['project_count'],
            'days_logged' => (int) $staffRow['days_logged'],
            'total_hours' => (float) $staffRow['total_hours'],
            'average_entry_hours' => (float) $staffRow['average_entry_hours'],
            'first_entry_date' => $staffRow['first_entry_date'],
            'last_entry_date' => $staffRow['last_entry_date'],
            'entries' => $entriesByStaff[$key] ?? []
        ];
    }

    $grandTotalHours = (float) ($summary['grand_total_hours'] ?? 0);
    $daysLogged = (int) ($summary['days_logged'] ?? 0);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Timesheet report fetched successfully',
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
            'datefrom' => $datefrom,
            'dateto' => $dateto,
            'staff_filter' => $staff,
            'search' => $search,
            'route_type' => 'all',
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    error_log('Timesheet report error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage()
    ]);
}
