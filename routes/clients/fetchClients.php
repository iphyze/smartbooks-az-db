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
     * The frontend might send ?search=john
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     * We only select columns needed for the dropdown display
     */
    $sql = "
        SELECT 
            id, 
            clients_id, 
            clients_name, 
            clients_email
        FROM clients_table
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * If search is empty, this is skipped, and we just get the top 100.
     * If search exists, we filter by name, email, or ID.
     */
    if (!empty($search)) {
        $sql .= " WHERE (clients_name LIKE ? OR clients_email LIKE ? OR clients_number LIKE ? OR CAST(clients_id AS CHAR) LIKE ?)";
        
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
     * Always sort by name ASC and limit to 100 results for performance.
     */
    $sql .= " ORDER BY clients_name ASC LIMIT 100";

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