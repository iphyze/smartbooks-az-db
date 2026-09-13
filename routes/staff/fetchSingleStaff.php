<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/rbac_helpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    requireAnyPermission(
        $conn,
        $userData,
        ['staff.view', 'staff.edit'],
        'You do not have permission to access this staff record.'
    );

    if (!isset($_GET['staff_id']) || trim($_GET['staff_id']) === '') {
        throw new Exception("Missing required parameter: 'staff_id'.", 400);
    }

    $staff_id = trim($_GET['staff_id']);

    $stmt = $conn->prepare("
        SELECT
            id,
            staff_id,
            staff_name,
            staff_email,
            staff_tel,
            staff_address,
            date_of_birth,
            gender,
            job_title,
            date_of_joining,
            bank_name,
            bank_account_number,
            bank_account_name,
            pension_number,
            payee_id,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM staff_table
        WHERE staff_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data   = $result->fetch_assoc();
    $stmt->close();

    if (!$data) {
        throw new Exception("Staff member with ID '{$staff_id}' not found.", 404);
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Staff fetched successfully",
        "data"    => $data
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => publicErrorMessage($e)
    ]);
}