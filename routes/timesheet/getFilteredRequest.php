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
     * Validate pagination
     */
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit  = (int) $_GET['limit'];
    $page   = (int) $_GET['page'];
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    /**
     * Sorting setup for Timesheet Table
     */
    $allowedSortFields = [
        "id",
        "staff_name",
        "staff_id",
        "date",
        "clients_name",
        "project",
        "task",
        "total_hours",
        "created_at"
    ];

    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    /**
     * Base query for Timesheets
     */
    $baseQuery = "FROM timesheet_table WHERE 1=1";
    $params = [];
    $types  = "";

    /**
     * Search filter for Timesheets
     */
    if ($search) {
        $baseQuery .= " AND (
            staff_name LIKE ? OR 
            staff_id LIKE ? OR 
            clients_name LIKE ? OR 
            clients_id LIKE ? OR 
            project LIKE ? OR 
            task LIKE ? OR
            CAST(total_hours AS CHAR) LIKE ?
        )";
        
        $likeSearch = "%" . $search . "%";

        // Add 7 parameters for the 7 search conditions
        $params = array_fill(0, 7, $likeSearch);
        $types .= "sssssss";
    }

    /**
     * Count total records
     */
    $countQuery = "SELECT COUNT(*) AS total $baseQuery";

    $countStmt = $conn->prepare($countQuery);

    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];

    $countStmt->close();

    /**
     * Fetch paginated timesheets
     */
    $dataQuery = "
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
        $baseQuery
        ORDER BY $sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);

    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $dataStmt->error, 500);
    }

    // Append limit and offset types to the existing types string
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);

    $dataStmt->execute();

    $result = $dataStmt->get_result();
    $timesheets = $result->fetch_all(MYSQLI_ASSOC);

    $dataStmt->close();

    /**
     * Note: Removed the 'Line Items' logic as the timesheet_table appears to be a flat structure
     * without a separate child table in this context.
     */

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Timesheets fetched successfully",
        "data" => $timesheets,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $search
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