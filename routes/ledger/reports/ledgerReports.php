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
     * Validate Inputs
     */
    $requiredParams = ['functionalCurrency', 'datefrom', 'dateto', 'fromledger', 'toledger'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim($_GET[$param]))) {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $functionalCurrency = trim($_GET['functionalCurrency']);
    $datefrom           = trim($_GET['datefrom']);
    $dateto             = trim($_GET['dateto']);
    $fromledger         = trim($_GET['fromledger']);
    $toledger           = trim($_GET['toledger']);

    // Determine columns
    if ($functionalCurrency === "Yes") {
        $debitCol  = "debit_ngn";
        $creditCol = "credit_ngn";
        $reportTitle = "Account Statement - Functional Currency";
    } else {
        $debitCol  = "debit";
        $creditCol = "credit";
        $reportTitle = "Account Statement";
    }

    /**
     * 1. Fetch Distinct Ledgers
     */
    $ledgersQuery = "
        SELECT DISTINCT ledger_name, ledger_number, journal_currency 
        FROM main_journal_table 
        WHERE ledger_number BETWEEN ? AND ? 
        ORDER BY ledger_number ASC
    ";

    $ledgersStmt = $conn->prepare($ledgersQuery);
    if (!$ledgersStmt) {
        throw new Exception("Failed to prepare ledgers query: " . $conn->error, 500);
    }

    $ledgersStmt->bind_param("ss", $fromledger, $toledger);
    $ledgersStmt->execute();
    $ledgers = $ledgersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ledgersStmt->close();

    $reportData = [];

    /**
     * 2. Loop through each ledger
     */
    foreach ($ledgers as $ledger) {
        $ledgerNumber = $ledger['ledger_number'];
        $ledgerName   = $ledger['ledger_name'];
        $ledgerCurrency = $ledger['journal_currency'];

        // A. Calculate Previous Balance (Before datefrom)
        $prevQuery = "
            SELECT 
                SUM($debitCol) as total_debit, 
                SUM($creditCol) as total_credit 
            FROM main_journal_table 
            WHERE ledger_number = ? 
            AND journal_date < ? 
            AND journal_currency = ?
        ";

        $prevStmt = $conn->prepare($prevQuery);
        $prevStmt->bind_param("sss", $ledgerNumber, $datefrom, $ledgerCurrency);
        $prevStmt->execute();
        $prevResult = $prevStmt->get_result()->fetch_assoc();
        $prevStmt->close();

        $previousDebit  = $prevResult['total_debit'] ?? 0;
        $previousCredit = $prevResult['total_credit'] ?? 0;
        $previousBalance = $previousDebit - $previousCredit;

        // B. Fetch Transactions
        $transQuery = "
            SELECT DISTINCT 
                journal_id, 
                journal_date, 
                journal_type, 
                journal_description, 
                $debitCol as debit, 
                $creditCol as credit
            FROM main_journal_table 
            WHERE journal_date BETWEEN ? AND ? 
            AND ledger_number = ? 
            AND journal_currency = ? 
            ORDER BY journal_date ASC
        ";

        $transStmt = $conn->prepare($transQuery);
        $transStmt->bind_param("ssss", $datefrom, $dateto, $ledgerNumber, $ledgerCurrency);
        $transStmt->execute();
        $transactions = $transStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $transStmt->close();

        // C. Calculate Totals
        $periodTotalDebit  = 0;
        $periodTotalCredit = 0;
        $runningBalance    = $previousBalance;
        
        $processedTransactions = [];

        foreach ($transactions as $trans) {
            $debitAmount  = $trans['debit'] ?? 0;
            $creditAmount = $trans['credit'] ?? 0;

            $periodTotalDebit  += $debitAmount;
            $periodTotalCredit += $creditAmount;
            $runningBalance    += ($debitAmount - $creditAmount);

            $processedTransactions[] = [
                "date"        => $trans['journal_date'],
                "type"        => $trans['journal_type'],
                "ref"         => $trans['journal_id'],
                "description" => $trans['journal_description'],
                "debit"       => $debitAmount,
                "credit"      => $creditAmount,
                "balance"     => $runningBalance
            ];
        }

        // D. Calculate Summary Logic
        // 1. Net Movement: The change during the period (Total Period Balance)
        $periodNetMovement = $periodTotalDebit - $periodTotalCredit;

        // 2. Closing Balance: The final position (Total Balance)
        $closingBalance = $previousBalance + $periodNetMovement;

        // Structure the Ledger Object
        $reportData[] = [
            "ledger_number"       => $ledgerNumber,
            "ledger_name"         => $ledgerName,
            "ledger_currency"     => $ledgerCurrency,
            
            // Summary Object for easy frontend rendering
            "summary" => [
                "previous_balance"    => $previousBalance,
                "period_total_debit"  => $periodTotalDebit,
                "period_total_credit" => $periodTotalCredit,
                "period_net_movement" => $periodNetMovement, // This goes to "Total Period" Balance Col
                "closing_balance"     => $closingBalance     // This goes to "Total" Balance Col
            ],
            
            "transactions"        => $processedTransactions
        ];
    }

    http_response_code(200);

    echo json_encode([
        "status"  => "Success",
        "message" => "Ledger statement fetched successfully",
        "title"   => $reportTitle,
        "data"    => $reportData,
        "meta"    => [
            "datefrom"  => $datefrom,
            "dateto"    => $dateto,
            "fromledger" => $fromledger,
            "toledger"  => $toledger,
            "functional_currency" => $functionalCurrency
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