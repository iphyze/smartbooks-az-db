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
     * Validate Inputs
     */
    if (!isset($_GET['datefrom']) || empty(trim($_GET['datefrom']))) {
        throw new Exception("Missing required parameter: 'datefrom'.", 400);
    }
    if (!isset($_GET['dateto']) || empty(trim($_GET['dateto']))) {
        throw new Exception("Missing required parameter: 'dateto'.", 400);
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $staff    = isset($_GET['staff']) ? trim($_GET['staff']) : 'All Staff';

    /**
     * Build Base Conditions
     */
    $mainConditions = "WHERE date BETWEEN ? AND ?";
    $mainParams = [$datefrom, $dateto];
    $mainTypes = "ss";

    // Specific staff filter
    if ($staff !== "All Staff") {
        $mainConditions .= " AND staff_name = ?";
        $mainParams[] = $staff;
        $mainTypes .= "s";
    }

    /**
     * 1. Count Total Distinct Staff
     */
    $countQuery = "SELECT COUNT(DISTINCT staff_name) AS total FROM timesheet_table $mainConditions";
    
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    
    $countStmt->bind_param($mainTypes, ...$mainParams);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    /**
     * 2. Fetch All Distinct Staff List
     */
    $staffListQuery = "SELECT DISTINCT staff_name, staff_id FROM timesheet_table $mainConditions ORDER BY staff_name ASC";
    
    $staffListStmt = $conn->prepare($staffListQuery);
    if (!$staffListStmt) throw new Exception("Failed to prepare staff list query: " . $conn->error, 500);
    
    $staffListStmt->bind_param($mainTypes, ...$mainParams);
    $staffListStmt->execute();
    $staffListResult = $staffListStmt->get_result();
    $staffList = $staffListResult->fetch_all(MYSQLI_ASSOC);
    $staffListStmt->close();

    /**
     * 3. Fetch Entries and Totals for each Staff
     */
    $reportData = [];
    $grandTotalHours = 0;

    // Prepare statement for fetching entries
    $entryQuery = "
        SELECT 
            id,
            date,
            clients_name,
            clients_id,
            project,
            task,
            start_time,
            finish_time,
            total_hours
        FROM timesheet_table 
        WHERE date BETWEEN ? AND ? AND staff_name = ?
    ";

    $entryStmt = $conn->prepare($entryQuery);
    if (!$entryStmt) throw new Exception("Failed to prepare entry query: " . $conn->error, 500);

    foreach ($staffList as $staffMember) {
        $currentStaffName = $staffMember['staff_name'];
        
        // Bind: datefrom (s), dateto (s), staff_name (s)
        $entryStmt->bind_param("sss", $datefrom, $dateto, $currentStaffName);
        $entryStmt->execute();
        $entryResult = $entryStmt->get_result();
        
        $entries = [];
        $staffTotalHours = 0;
        
        while ($row = $entryResult->fetch_assoc()) {
            $entries[] = $row;
            $staffTotalHours += (float) $row['total_hours'];
        }

        if (!empty($entries)) {
            $reportData[] = [
                "staff_name" => $currentStaffName,
                "staff_id" => $staffMember['staff_id'],
                "total_hours" => $staffTotalHours,
                "entries" => $entries
            ];
        }

        $grandTotalHours += $staffTotalHours;
    }
    
    $entryStmt->close();

    /**
     * 4. Fetch Grand Totals
     */
    $totalsQuery = "
        SELECT SUM(total_hours) as grand_total 
        FROM timesheet_table 
        $mainConditions
    ";

    $totalsStmt = $conn->prepare($totalsQuery);
    if (!$totalsStmt) throw new Exception("Failed to prepare totals query: " . $conn->error, 500);
    
    $totalsStmt->bind_param($mainTypes, ...$mainParams);
    $totalsStmt->execute();
    $grandTotalResult = $totalsStmt->get_result()->fetch_assoc();
    $totalsStmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Timesheet report fetched successfully",
        "data" => $reportData,
        "summary" => [
            "grand_total_hours" => (float) ($grandTotalResult['grand_total'] ?? 0),
            "staff_count" => count($reportData)
        ],
        "meta" => [
            "total_staff_found" => (int) $total,
            "datefrom" => $datefrom,
            "dateto" => $dateto,
            "staff_filter" => $staff
        ]
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}