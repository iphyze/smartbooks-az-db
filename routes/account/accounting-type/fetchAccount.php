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
     * Get search query (optional)
     * The frontend might send ?search=Assets
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     */
    $sql = "
        SELECT 
            id, 
            type, 
            category_id, 
            category, 
            sub_category,
            created_at, 
            created_by, 
            updated_at, 
            updated_by
        FROM account_table
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * If search exists, filter by type, category_id, category, and sub_category
     */
    if (!empty($search)) {
        $sql .= " WHERE (type LIKE ? OR CAST(category_id AS CHAR) LIKE ? OR category LIKE ? OR sub_category LIKE ?)";
        
        $likeSearch = "%" . $search . "%";
        
        // Push parameters 4 times (for the 4 placeholders above)
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        
        $types .= "ssss";
    }

    /**
     * Sorting and Limiting
     * Sort by category and sub-category for better dropdown grouping
     */
    $sql .= " ORDER BY category ASC, sub_category ASC LIMIT 100";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    // Bind parameters if search exists
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "data" => $data
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}