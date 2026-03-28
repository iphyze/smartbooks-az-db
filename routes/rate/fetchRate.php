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
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     * Selecting ID and the currency code columns to display in the dropdown
     */
    $sql = "
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
            created_at
        FROM currency_table
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * Searching across currency codes (_cur) and rates (_rate)
     */
    if (!empty($search)) {
        $sql .= " WHERE (
            ngn_cur LIKE ? OR 
            usd_cur LIKE ? OR 
            gbp_cur LIKE ? OR 
            eur_cur LIKE ? OR 
            created_at LIKE ? OR 
            CAST(ngn_rate AS CHAR) LIKE ? OR 
            CAST(usd_rate AS CHAR) LIKE ? OR 
            CAST(gbp_rate AS CHAR) LIKE ? OR 
            CAST(eur_rate AS CHAR) LIKE ?
        )";
        
        $likeSearch = "%" . $search . "%";
        
        // Add 9 parameters for the 9 search conditions
        $params = array_fill(0, 9, $likeSearch);
        $types .= "sssssssss";
    }

    /**
     * Sorting and Limiting
     * Sort by created_at DESC to show recent rates first. Limit to 100.
     */
    $sql .= " ORDER BY created_at DESC LIMIT 100";

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