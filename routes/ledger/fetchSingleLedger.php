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
     * Validate ledger_number input
     */
    if (!isset($_GET['ledger_number']) || empty($_GET['ledger_number'])) {
        throw new Exception("Missing required parameter: 'ledger_number'.", 400);
    }

    $ledger_number = trim($_GET['ledger_number']);

    /**
     * 1. Fetch Base Ledger Record
     */
    $stmt = $conn->prepare("
        SELECT 
            id,
            ledger_name,
            ledger_number,
            ledger_class,
            ledger_class_code,
            ledger_sub_class,
            ledger_type,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM ledger_table 
        WHERE ledger_number = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $ledger_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $ledgerData = $result->fetch_assoc();
    $stmt->close();

    if (!$ledgerData) {
        throw new Exception("Ledger with number {$ledger_number} not found.", 404);
    }

    /**
     * 2. Fetch Associated Journal Entries from main_journal_table
     */
    $stmtJnl = $conn->prepare("
        SELECT 
            id, 
            journal_id, 
            journal_type, 
            transaction_type, 
            journal_date, 
            journal_currency, 
            journal_description, 
            debit, 
            credit, 
            rate_date, 
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
        WHERE ledger_number = ?
        ORDER BY journal_date DESC, id DESC
        LIMIT 100
    ");

    if (!$stmtJnl) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtJnl->bind_param("s", $ledger_number);
    $stmtJnl->execute();
    $resultJnl = $stmtJnl->get_result();

    $journalEntries = [];
    $summary = [];

    while ($row = $resultJnl->fetch_assoc()) {
        $journalEntries[] = $row;

        $currency = $row['journal_currency']; // Grouping key
        $debit = (float) $row['debit'];
        $credit = (float) $row['credit'];

        // Initialize currency bucket if it doesn't exist
        if (!isset($summary[$currency])) {
            $summary[$currency] = [
                'total_debit' => 0,
                'total_credit' => 0,
                'net_balance' => 0,
                'entry_count' => 0
            ];
        }

        // Accumulate totals
        $summary[$currency]['total_debit'] += $debit;
        $summary[$currency]['total_credit'] += $credit;
        $summary[$currency]['entry_count'] += 1;
    }

    $stmtJnl->close();

    // Calculate the net balance (Debit - Credit) for each currency
    foreach ($summary as &$currSummary) {
        $currSummary['net_balance'] = $currSummary['total_debit'] - $currSummary['total_credit'];
    }
    // Unset reference to prevent accidental overwrite later
    unset($currSummary); 

    /**
     * 3. Final Response
     */
    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Ledger details fetched successfully",
        "data" => [
            "ledger" => $ledgerData,
            "journal_entries" => $journalEntries,
            "summary" => $summary
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