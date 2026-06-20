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

    // User administration is restricted to the Admin (super-admin) role
    if ($loggedInUserIntegrity !== 'Admin') {
        throw new Exception("Only an Admin can access this resource", 403);
    }

    // Validate pagination
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

    // Sorting setup
    $allowedSortFields = ['id', 'fname', 'lname', 'email', 'integrity'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'id';

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
        ? 'ASC'
        : 'DESC';

    // Base query
    $baseQuery = "FROM admin_table a LEFT JOIN staff_table s ON s.staff_id = a.staff_id WHERE 1=1";
    $params = [];
    $types  = "";

    // Search filter
    if ($search) {
        $baseQuery .= " AND (
            a.fname LIKE ? 
            OR a.lname LIKE ? 
            OR a.email LIKE ? 
            OR a.integrity LIKE ?
            OR s.staff_name LIKE ?
        )";
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
    $total = (int) $countResult->fetch_assoc()['total'];
    $countStmt->close();

    /**
     * Fetch paginated data
     */
    $dataQuery = "
        SELECT a.id, a.fname, a.lname, a.email, a.integrity, a.staff_id,
               a.must_change_password, s.staff_name AS linked_staff_name,
               a.created_by, a.updated_by
        $baseQuery
        ORDER BY a.$sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Append limit & offset
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    foreach ($data as &$row) {
        $row['must_change_password'] = (bool) ((int) ($row['must_change_password'] ?? 0));
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Users fetched successfully",
        "data" => $data,
        "meta" => [
            "total" => $total,
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
        "message" => publicErrorMessage($e)
    ]);
}
