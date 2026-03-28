<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    /**
     * Validate id input
     */
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id = (int) $_GET['id'];

    /**
     * 1. Fetch Timesheet Entry
     */
    $stmtTimesheet = $conn->prepare("
        SELECT 
            id,
            staff_name,
            staff_id,
            date,
            clients_name,
            clients_id,
            project,
            task,
            start_time,
            finish_time,
            total_hours,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM timesheet_table 
        WHERE id = ?
    ");

    if (!$stmtTimesheet) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtTimesheet->bind_param("i", $id);
    $stmtTimesheet->execute();
    $resultTimesheet = $stmtTimesheet->get_result();
    $timesheetData = $resultTimesheet->fetch_assoc();
    $stmtTimesheet->close();

    if (!$timesheetData) {
        throw new Exception("Timesheet entry with ID {$id} not found.", 404);
    }

    $clients_id = $timesheetData['clients_id'] ?? null;
    $staff_id = $timesheetData['staff_id'] ?? null;

    /**
     * 2. Fetch Company Data (profile_table)
     * Retained for consistency with the invoice view structure
     */
    $companyData = null;

    $stmtCompany = $conn->prepare("
        SELECT 
            id,
            office_address,
            email,
            tel,
            account_name,
            account_number,
            bank_name,
            tin,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM profile_table
        LIMIT 1
    ");

    if (!$stmtCompany) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtCompany->execute();
    $resultCompany = $stmtCompany->get_result();
    $companyData = $resultCompany->fetch_assoc();
    $stmtCompany->close();

    /**
     * 3. Fetch Clients Data
     */
    $clientsData = null;

    if ($clients_id) {
        $stmtClient = $conn->prepare("
            SELECT 
                id,
                clients_id,
                clients_name,
                clients_email,
                clients_address,
                clients_number,
                created_at,
                created_by,
                updated_at,
                updated_by
            FROM clients_table
            WHERE clients_id = ?
        ");

        if (!$stmtClient) {
            throw new Exception("Database error: " . $conn->error, 500);
        }

        // Using 's' for clients_id as it can be string or int depending on schema
        $stmtClient->bind_param("s", $clients_id);
        $stmtClient->execute();
        $resultClient = $stmtClient->get_result();
        $clientsData = $resultClient->fetch_assoc();
        $stmtClient->close();
    }

    /**
     * 4. Fetch Staff Data
     */
    $staffData = null;

    if ($staff_id) {
        $stmtStaff = $conn->prepare("
            SELECT 
                id,
                staff_id,
                staff_name,
                staff_email,
                created_at,
                created_by,
                updated_at,
                updated_by
            FROM staff_table
            WHERE staff_id = ?
        ");

        if (!$stmtStaff) {
            throw new Exception("Database error: " . $conn->error, 500);
        }

        $stmtStaff->bind_param("s", $staff_id);
        $stmtStaff->execute();
        $resultStaff = $stmtStaff->get_result();
        $staffData = $resultStaff->fetch_assoc();
        $stmtStaff->close();
    }

    /**
     * 5. Combine Data
     */
    $responseData = $timesheetData;
    $responseData['company_data'] = $companyData;
    $responseData['clients_data'] = $clientsData;
    $responseData['staff_data'] = $staffData;

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Timesheet fetched successfully",
        "data" => $responseData
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}