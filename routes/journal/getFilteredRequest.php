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
     * Sorting setup for Journal Table
     */
    $allowedSortFields = [
        "id",
        "journal_id",
        "journal_date",
        "journal_type",
        "transaction_type",
        "journal_description",
        "debit",
        "credit",
        "created_at"
    ];

    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    /**
     * Base query for Headers (journal_table)
     */
    $baseQuery = "FROM journal_table WHERE 1=1";
    $params = [];
    $types  = "";

    /**
     * Search filter for Journals
     */
    if ($search) {
        $baseQuery .= " AND (
            CAST(journal_id AS CHAR) LIKE ? OR 
            journal_type LIKE ? OR 
            transaction_type LIKE ? OR 
            journal_description LIKE ? OR 
            cost_center LIKE ? OR 
            journal_currency LIKE ? OR
            CAST(debit AS CHAR) LIKE ? OR
            CAST(credit AS CHAR) LIKE ?
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
     * Fetch paginated headers (journal_table)
     */
    $dataQuery = "
        SELECT
            id,
            journal_id,
            journal_date,
            journal_type,
            transaction_type,
            journal_currency,
            journal_description,
            debit,
            credit,
            debit_ngn,
            credit_ngn,
            debit_others,
            credit_others,
            cost_center,
            rate_date,
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
    $journals = $result->fetch_all(MYSQLI_ASSOC);

    $dataStmt->close();

    /**
     * Fetch Line Items (main_journal_table) for the fetched journals
     */
    $responseData = [];

    if (!empty($journals)) {
        // Extract journal_ids from the fetched headers
        $journalIds = array_column($journals, 'journal_id');

        // Create placeholders for IN clause (e.g., ?, ?, ?)
        $placeholders = implode(',', array_fill(0, count($journalIds), '?'));
        
        // Query for line items
        $itemsQuery = "
            SELECT 
                id,
                journal_id,
                journal_date,
                journal_currency,
                journal_description,
                debit,
                credit,
                rate,
                debit_ngn,
                credit_ngn,
                ngn_rate,
                usd_rate,
                eur_rate,
                gbp_rate,
                cost_center,
                ledger_name,
                ledger_number,
                ledger_class,
                ledger_class_code,
                ledger_sub_class,
                ledger_type,
                created_at
            FROM main_journal_table 
            WHERE journal_id IN ($placeholders)
        ";

        $itemsStmt = $conn->prepare($itemsQuery);
        
        if (!$itemsStmt) {
            throw new Exception("Failed to prepare items query: " . $conn->error, 500);
        }

        // Bind parameters for IN clause (integers for journal_id)
        $itemTypes = str_repeat('i', count($journalIds));
        $itemsStmt->bind_param($itemTypes, ...$journalIds);
        
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        
        // Group items by journal_id
        $groupedItems = [];
        while ($item = $itemsResult->fetch_assoc()) {
            $jId = $item['journal_id'];
            unset($item['journal_id']); // Optional: remove redundant key from item object
            $groupedItems[$jId][] = $item;
        }
        
        $itemsStmt->close();

        // Merge items into the journal headers
        foreach ($journals as $journal) {
            $jId = $journal['journal_id'];
            $journal['items'] = $groupedItems[$jId] ?? []; // Add items array
            $responseData[] = $journal;
        }
    }

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Journals fetched successfully",
        "data" => $responseData,
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