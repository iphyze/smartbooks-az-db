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
     * Validate accountId
     */
    if (!isset($_GET['accountId']) || empty($_GET['accountId'])) {
        throw new Exception("Missing required parameter: 'accountId'.", 400);
    }

    $accountId = (int) $_GET['accountId'];

    /**
     * 1. Fetch Account Type
     */
    $stmt = $conn->prepare("
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
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $accountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $accountData = $result->fetch_assoc();
    $stmt->close();

    if (!$accountData) {
        throw new Exception("Account type with ID {$accountId} not found.", 404);
    }

    // We will use category_id to link to the ledger table (as ledger_class_code)
    $classCode = $accountData['category_id'];

    /**
     * 2. Fetch Ledgers for this Account Type
     */
    $stmtLedger = $conn->prepare("
        SELECT 
            id,
            ledger_name,
            ledger_number,
            ledger_class,
            ledger_class_code,
            ledger_sub_class,
            ledger_type,
            created_at
        FROM ledger_table
        WHERE ledger_class_code = ?
        ORDER BY ledger_number ASC
    ");

    if (!$stmtLedger) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtLedger->bind_param("s", $classCode);
    $stmtLedger->execute();
    $ledgerResult = $stmtLedger->get_result();
    $ledgers = $ledgerResult->fetch_all(MYSQLI_ASSOC);
    $stmtLedger->close();

    /**
     * 3. Fetch Aggregated Totals for Ledgers (Highly Optimized)
     * Instead of fetching every transaction line, we ask MySQL to sum them up 
     * grouped by the ledger number and the currency.
     */
    $stmtTotals = $conn->prepare("
        SELECT 
            ledger_number,
            journal_currency AS currency,
            COALESCE(SUM(debit), 0) AS total_debit,
            COALESCE(SUM(credit), 0) AS total_credit
        FROM main_journal_table 
        WHERE ledger_class_code = ?
        GROUP BY ledger_number, journal_currency
    ");

    if (!$stmtTotals) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtTotals->bind_param("s", $classCode);
    $stmtTotals->execute();
    $totalsResult = $stmtTotals->get_result();

    // Map totals to a structured array: [ledger_number][currency] = totals
    $ledgerTotalsMap = [];
    while ($row = $totalsResult->fetch_assoc()) {
        $lNum = $row['ledger_number'];
        $curr = $row['currency'];
        
        $ledgerTotalsMap[$lNum][$curr] = [
            'total_debit'  => (float) $row['total_debit'],
            'total_credit' => (float) $row['total_credit'],
            'balance'      => (float) $row['total_debit'] - (float) $row['total_credit']
        ];
    }
    $stmtTotals->close();

    /**
     * 4. Merge Totals into Ledger Objects
     */
    $accountSummary = [];

    foreach ($ledgers as &$ledger) {
        $lNum = $ledger['ledger_number'];
        
        // If this ledger has transactions, attach the totals object. Otherwise null.
        $ledger['totals'] = $ledgerTotalsMap[$lNum] ?? null;

        // Accumulate for the overall Account Type Summary
        if ($ledger['totals']) {
            foreach ($ledger['totals'] as $curr => $vals) {
                if (!isset($accountSummary[$curr])) {
                    $accountSummary[$curr] = [
                        'total_debit' => 0,
                        'total_credit' => 0,
                        'balance' => 0
                    ];
                }
                $accountSummary[$curr]['total_debit'] += $vals['total_debit'];
                $accountSummary[$curr]['total_credit'] += $vals['total_credit'];
                $accountSummary[$curr]['balance'] += $vals['balance'];
            }
        }
    }
    
    // Remove reference to prevent accidental overwrite
    unset($ledger); 

    /**
     * 5. Final Response
     */
    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "data" => [
            "account" => $accountData,
            "ledgers" => $ledgers,
            "account_summary" => $accountSummary
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