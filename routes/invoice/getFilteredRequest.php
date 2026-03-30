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
     * Sorting setup for Invoice Table
     */
    $allowedSortFields = [
        "id",
        "invoice_number",
        "invoice_date",
        "due_date",
        "clients_name",
        "invoice_amount",
        "status",
        "created_at"
    ];

    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    /**
     * Base query for Headers (invoice_table)
     */
    $baseQuery = "FROM invoice_table WHERE 1=1";
    $params = [];
    $types  = "";

    /**
     * Search filter for Invoices
     */
    if ($search) {
        $baseQuery .= " AND (
            invoice_number LIKE ? OR 
            clients_name LIKE ? OR 
            clients_id LIKE ? OR 
            project LIKE ? OR 
            status LIKE ? OR 
            currency LIKE ? OR
            bank_name LIKE ? OR
            CAST(invoice_amount AS CHAR) LIKE ?
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
     * Fetch paginated headers (invoice_table)
     */
    $dataQuery = "
        SELECT
            id,
            invoice_number,
            invoice_date,
            due_date,
            clients_name,
            clients_id,
            project,
            invoice_amount,
            currency,
            status,
            bank_name,
            account_name,
            account_number,
            account_currency,
            tin_number,
            paid,
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
    $invoices = $result->fetch_all(MYSQLI_ASSOC);

    $dataStmt->close();

    /**
     * Fetch Line Items (main_invoice_table) for the fetched invoices
     */
    $responseData = [];

    if (!empty($invoices)) {
        // Extract invoice numbers from the fetched headers
        $invoiceNumbers = array_column($invoices, 'invoice_number');

        // Create placeholders for IN clause (e.g., ?, ?, ?)
        $placeholders = implode(',', array_fill(0, count($invoiceNumbers), '?'));
        
        // Query for line items
        $itemsQuery = "
            SELECT 
                id,
                invoice_number,
                description,
                amount,
                discount_percent,
                vat_percent,
                wht_percent,
                discount,
                vat,
                wht,
                total,
                created_at
            FROM main_invoice_table 
            WHERE invoice_number IN ($placeholders)
        ";

        $itemsStmt = $conn->prepare($itemsQuery);
        
        if (!$itemsStmt) {
            throw new Exception("Failed to prepare items query: " . $conn->error, 500);
        }

        // Bind parameters for IN clause (all strings)
        $itemTypes = str_repeat('s', count($invoiceNumbers));
        $itemsStmt->bind_param($itemTypes, ...$invoiceNumbers);
        
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        
        // Group items by invoice_number
        $groupedItems = [];
        while ($item = $itemsResult->fetch_assoc()) {
            $invNum = $item['invoice_number'];
            unset($item['invoice_number']); // Optional: remove redundant key from item object
            $groupedItems[$invNum][] = $item;
        }
        
        $itemsStmt->close();

        // Merge items into the invoice headers
        foreach ($invoices as $invoice) {
            $invNum = $invoice['invoice_number'];
            $invoice['items'] = $groupedItems[$invNum] ?? []; // Add items array
            $responseData[] = $invoice;
        }
    }

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Invoices fetched successfully",
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