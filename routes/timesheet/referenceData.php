<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole(
        $user,
        [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER, SMARTBOOKS_ROLE_TIMESHEET],
        'You are not authorised to access Timesheet reference data.'
    );

    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $search = trim((string) ($_GET['search'] ?? ''));
    $like = '%' . $search . '%';

    if ($type === 'staff') {
        if (isTimesheetOnlyUser($user)) {
            $staff = requireLinkedTimesheetStaff($conn, $user);
            jsonResponse(['status' => 'Success', 'data' => [$staff]]);
        }

        $stmt = $conn->prepare(
            'SELECT staff_id, staff_name, staff_email
             FROM staff_table
             WHERE staff_name LIKE ? OR staff_email LIKE ? OR CAST(staff_id AS CHAR) LIKE ?
             ORDER BY staff_name ASC LIMIT 100'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to load staff reference data.', 500);
        }
        $stmt->bind_param('sss', $like, $like, $like);
    } elseif ($type === 'clients') {
        $stmt = $conn->prepare(
            'SELECT clients_id, clients_name
             FROM clients_table
             WHERE clients_name LIKE ? OR CAST(clients_id AS CHAR) LIKE ?
             ORDER BY clients_name ASC LIMIT 100'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to load client reference data.', 500);
        }
        $stmt->bind_param('ss', $like, $like);
    } elseif ($type === 'projects') {
        $stmt = $conn->prepare(
            'SELECT id, project_name, project_code, code
             FROM project_table
             WHERE project_name LIKE ? OR code LIKE ?
             ORDER BY project_name ASC LIMIT 100'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to load project reference data.', 500);
        }
        $stmt->bind_param('ss', $like, $like);
    } else {
        throw new RuntimeException('Invalid reference-data type.', 400);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    jsonResponse(['status' => 'Success', 'data' => $rows]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Timesheet/ReferenceData] ' . $exception->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception)
    ], publicErrorStatus($exception));
}
