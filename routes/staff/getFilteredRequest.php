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
     * Sorting setup
     */
    $allowedSortFields = [
        "id",
        "staff_id",
        "staff_name",
        "staff_email",
        "job_title",
        "created_at"
    ];

    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    /**
     * Base query
     */
    $baseQuery = "FROM staff_table WHERE 1=1";
    $params = [];
    $types  = "";

    /**
     * Search filter
     * Searching across staff_name, staff_email, staff_address, staff_tel, and staff_id
     */
    if ($search) {
        $baseQuery .= " AND (staff_name LIKE ? OR staff_email LIKE ? OR staff_address LIKE ? OR staff_tel LIKE ? OR CAST(staff_id AS CHAR) LIKE ?)";
        $likeSearch = "%" . $search . "%";

        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;

        $types .= "sssss";
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
     * Fetch paginated data
     */
    $dataQuery = "
        SELECT
            id,
            staff_name,
            staff_id,
            staff_tel,
            staff_email,
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
        $baseQuery
        ORDER BY $sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);

    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);

    $dataStmt->execute();

    $result = $dataStmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    $dataStmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Staff fetched successfully",
        "data" => $data,
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