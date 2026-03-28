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
        "ngn_rate",
        "usd_rate",
        "gbp_rate",
        "eur_rate",
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
    $baseQuery = "FROM currency_table WHERE 1=1";
    $params = [];
    $types  = "";

    /**
     * Search filter
     * Searching across currency codes (_cur) and rates (_rate)
     */
    if ($search) {
        $baseQuery .= " AND (
            ngn_cur LIKE ? OR 
            usd_cur LIKE ? OR 
            gbp_cur LIKE ? OR 
            eur_cur LIKE ? OR 
            CAST(ngn_rate AS CHAR) LIKE ? OR 
            CAST(usd_rate AS CHAR) LIKE ? OR 
            CAST(gbp_rate AS CHAR) LIKE ? OR 
            CAST(eur_rate AS CHAR) LIKE ?
        )";
        
        $likeSearch = "%" . $search . "%";

        // Add 8 parameters for the 8 search conditions
        $params = array_fill(0, 8, $likeSearch);
        $types .= "ssssssss";
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
            ngn_cur,
            ngn_rate,
            usd_cur,
            usd_rate,
            gbp_cur,
            gbp_rate,
            eur_cur,
            eur_rate,
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

    // Append limit and offset types to the existing types string
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
        "message" => "Currency rates fetched successfully",
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