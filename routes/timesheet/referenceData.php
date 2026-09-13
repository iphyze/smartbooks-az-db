<?php
declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authorization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $context = strtolower(trim((string) ($_GET['context'] ?? 'timesheet')));

    if ($context === 'user_admin') {
        if ($type !== 'staff') {
            throw new RuntimeException('User administration may only request staff reference data.', 400);
        }
        requireAnyPermission(
            $conn,
            $user,
            ['user.create', 'user.edit'],
            'You do not have permission to load staff reference data.'
        );
    } else {
        if ($type === 'staff') {
            requireAnyPermission(
                $conn,
                $user,
                ['timesheet.view', 'timesheet.create', 'timesheet.edit'],
                'You do not have permission to load staff reference data.'
            );
        } elseif (in_array($type, ['clients', 'projects'], true)) {
            requireAnyPermission(
                $conn,
                $user,
                ['timesheet.create', 'timesheet.edit'],
                'You do not have permission to load timesheet reference data.'
            );
        } else {
            throw new RuntimeException('Invalid reference-data type.', 400);
        }

        // Timesheets are not cost-centre partitioned yet. Keep the existing
        // All Cost Centres requirement and legacy Timesheet-role ownership scope.
        timesheetStaffScope($conn, $user);
    }

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
