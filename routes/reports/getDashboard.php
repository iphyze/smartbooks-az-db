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

    // Optional date range filters
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
    $dateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : null;

    // Validate date formats if provided
    $dateFilterSQL   = "";
    $dateFilterTypes = "";
    $dateFilterParams = [];

    if ($dateFrom && $dateTo) {
        $dateFilterSQL    = " AND DATE(created_at) BETWEEN ? AND ?";
        $dateFilterTypes .= "ss";
        $dateFilterParams[] = $dateFrom;
        $dateFilterParams[] = $dateTo;
    } elseif ($dateFrom) {
        $dateFilterSQL    = " AND DATE(created_at) >= ?";
        $dateFilterTypes .= "s";
        $dateFilterParams[] = $dateFrom;
    } elseif ($dateTo) {
        $dateFilterSQL    = " AND DATE(created_at) <= ?";
        $dateFilterTypes .= "s";
        $dateFilterParams[] = $dateTo;
    }

    // =========================================================
    // 1. RECEIVABLES — unpaid invoices grouped by currency
    // =========================================================
    $receivablesQuery = "
        SELECT
            currency,
            SUM(invoice_amount) AS total_receivables,
            SUM(CASE WHEN due_date >= CURDATE() THEN invoice_amount ELSE 0 END) AS current_receivables,
            SUM(CASE WHEN due_date <  CURDATE() THEN invoice_amount ELSE 0 END) AS overdue_receivables,
            COUNT(*) AS total_invoices,
            SUM(CASE WHEN status = 'Paid'    THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN status = 'Unpaid'  THEN 1 ELSE 0 END) AS unpaid_count,
            SUM(CASE WHEN status = 'Partial' THEN 1 ELSE 0 END) AS partial_count
        FROM invoice_table
        WHERE status != 'Paid'
        $dateFilterSQL
        GROUP BY currency
    ";

    $receivablesStmt = $conn->prepare($receivablesQuery);
    if (!$receivablesStmt) {
        throw new Exception("Failed to prepare receivables query: " . $conn->error, 500);
    }
    if (!empty($dateFilterParams)) {
        $receivablesStmt->bind_param($dateFilterTypes, ...$dateFilterParams);
    }
    $receivablesStmt->execute();
    $receivablesResult = $receivablesStmt->get_result();
    $receivables = $receivablesResult->fetch_all(MYSQLI_ASSOC);
    $receivablesStmt->close();

    // =========================================================
    // 2. INVOICE STATUS BREAKDOWN (all invoices)
    // =========================================================
    $invoiceStatusQuery = "
        SELECT
            status,
            COUNT(*) AS count,
            SUM(invoice_amount) AS total_amount,
            currency
        FROM invoice_table
        WHERE 1=1 $dateFilterSQL
        GROUP BY status, currency
        ORDER BY currency, status
    ";

    $invoiceStatusStmt = $conn->prepare($invoiceStatusQuery);
    if (!$invoiceStatusStmt) {
        throw new Exception("Failed to prepare invoice status query: " . $conn->error, 500);
    }
    if (!empty($dateFilterParams)) {
        $invoiceStatusStmt->bind_param($dateFilterTypes, ...$dateFilterParams);
    }
    $invoiceStatusStmt->execute();
    $invoiceStatusResult = $invoiceStatusStmt->get_result();
    $invoiceStatusRaw = $invoiceStatusResult->fetch_all(MYSQLI_ASSOC);
    $invoiceStatusStmt->close();

    // Group by currency
    $invoiceStatus = [];
    foreach ($invoiceStatusRaw as $row) {
        $invoiceStatus[$row['currency']][] = [
            'status' => $row['status'],
            'count'  => (int) $row['count'],
            'total_amount' => (float) $row['total_amount']
        ];
    }

    // =========================================================
    // 3. REVENUE & EXPENSES from journal_table
    //    Revenue  = journal_type 'Revenue'  → credit side
    //    Expenses = journal_type 'Expense'  → debit side
    // =========================================================
    $revenueExpenseQuery = "
        SELECT
            journal_currency AS currency,
            SUM(CASE WHEN journal_type = 'Revenue' THEN credit ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN journal_type = 'Expense' THEN debit  ELSE 0 END) AS total_expenses,
            SUM(CASE WHEN journal_type = 'Revenue' THEN credit_ngn ELSE 0 END) AS total_revenue_ngn,
            SUM(CASE WHEN journal_type = 'Expense' THEN debit_ngn  ELSE 0 END) AS total_expenses_ngn
        FROM journal_table
        WHERE 1=1 $dateFilterSQL
        GROUP BY journal_currency
    ";

    $revenueExpenseStmt = $conn->prepare($revenueExpenseQuery);
    if (!$revenueExpenseStmt) {
        throw new Exception("Failed to prepare revenue/expense query: " . $conn->error, 500);
    }
    if (!empty($dateFilterParams)) {
        $revenueExpenseStmt->bind_param($dateFilterTypes, ...$dateFilterParams);
    }
    $revenueExpenseStmt->execute();
    $revenueExpenseResult = $revenueExpenseStmt->get_result();
    $revenueExpenses = $revenueExpenseResult->fetch_all(MYSQLI_ASSOC);
    $revenueExpenseStmt->close();

    // =========================================================
    // 4. MONTHLY REVENUE TREND (last 12 months) from journals
    // =========================================================
    $monthlyTrendQuery = "
        SELECT
            DATE_FORMAT(journal_date, '%Y-%m') AS month,
            journal_currency AS currency,
            SUM(CASE WHEN journal_type = 'Revenue' THEN credit ELSE 0 END) AS revenue,
            SUM(CASE WHEN journal_type = 'Expense' THEN debit  ELSE 0 END) AS expenses
        FROM journal_table
        WHERE journal_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(journal_date, '%Y-%m'), journal_currency
        ORDER BY month ASC, journal_currency ASC
    ";

    $monthlyTrendStmt = $conn->prepare($monthlyTrendQuery);
    if (!$monthlyTrendStmt) {
        throw new Exception("Failed to prepare monthly trend query: " . $conn->error, 500);
    }
    $monthlyTrendStmt->execute();
    $monthlyTrendResult = $monthlyTrendStmt->get_result();
    $monthlyTrend = $monthlyTrendResult->fetch_all(MYSQLI_ASSOC);
    $monthlyTrendStmt->close();

    // =========================================================
    // 5. BANK BALANCES — derived from journal line items
    //    Balance = SUM(credit_ngn) - SUM(debit_ngn) grouped by
    //    ledger_name (bank account), for ledger_class = 'Asset'
    // =========================================================
    $bankBalancesQuery = "
        SELECT
            mjt.ledger_name,
            mjt.ledger_number,
            mjt.cost_center,
            jt.journal_currency AS currency,
            SUM(mjt.credit_ngn - mjt.debit_ngn) AS balance_ngn,
            SUM(
                CASE
                    WHEN jt.journal_currency = 'USD' THEN (mjt.credit - mjt.debit)
                    ELSE 0
                END
            ) AS balance_usd
        FROM main_journal_table mjt
        JOIN journal_table jt ON jt.journal_id = mjt.journal_id
        WHERE mjt.ledger_class = 'Asset'
          AND mjt.ledger_type  = 'Bank'
        GROUP BY mjt.ledger_name, mjt.ledger_number, mjt.cost_center, jt.journal_currency
        ORDER BY mjt.ledger_name ASC
    ";

    $bankBalancesStmt = $conn->prepare($bankBalancesQuery);
    if (!$bankBalancesStmt) {
        throw new Exception("Failed to prepare bank balances query: " . $conn->error, 500);
    }
    $bankBalancesStmt->execute();
    $bankBalancesResult = $bankBalancesStmt->get_result();
    $bankBalances = $bankBalancesResult->fetch_all(MYSQLI_ASSOC);
    $bankBalancesStmt->close();

    // Aggregate NGN and USD totals
    $totalBankNGN = 0;
    $totalBankUSD = 0;
    foreach ($bankBalances as $b) {
        $totalBankNGN += (float) $b['balance_ngn'];
        $totalBankUSD += (float) $b['balance_usd'];
    }

    // =========================================================
    // 6. TOP CLIENTS BY INVOICE AMOUNT
    // =========================================================
    $topClientsQuery = "
        SELECT
            clients_name,
            clients_id,
            currency,
            COUNT(*)              AS invoice_count,
            SUM(invoice_amount)   AS total_billed,
            SUM(CASE WHEN status = 'Paid' THEN invoice_amount ELSE 0 END) AS total_paid,
            SUM(CASE WHEN status != 'Paid' THEN invoice_amount ELSE 0 END) AS total_outstanding
        FROM invoice_table
        WHERE 1=1 $dateFilterSQL
        GROUP BY clients_name, clients_id, currency
        ORDER BY total_billed DESC
        LIMIT 10
    ";

    $topClientsStmt = $conn->prepare($topClientsQuery);
    if (!$topClientsStmt) {
        throw new Exception("Failed to prepare top clients query: " . $conn->error, 500);
    }
    if (!empty($dateFilterParams)) {
        $topClientsStmt->bind_param($dateFilterTypes, ...$dateFilterParams);
    }
    $topClientsStmt->execute();
    $topClientsResult = $topClientsStmt->get_result();
    $topClients = $topClientsResult->fetch_all(MYSQLI_ASSOC);
    $topClientsStmt->close();

    // =========================================================
    // 7. JOURNAL SUMMARY — debits vs credits by type
    // =========================================================
    $journalSummaryQuery = "
        SELECT
            journal_type,
            transaction_type,
            journal_currency AS currency,
            COUNT(*)     AS entry_count,
            SUM(debit)   AS total_debit,
            SUM(credit)  AS total_credit,
            SUM(debit_ngn)  AS total_debit_ngn,
            SUM(credit_ngn) AS total_credit_ngn
        FROM journal_table
        WHERE 1=1 $dateFilterSQL
        GROUP BY journal_type, transaction_type, journal_currency
        ORDER BY journal_type, transaction_type
    ";

    $journalSummaryStmt = $conn->prepare($journalSummaryQuery);
    if (!$journalSummaryStmt) {
        throw new Exception("Failed to prepare journal summary query: " . $conn->error, 500);
    }
    if (!empty($dateFilterParams)) {
        $journalSummaryStmt->bind_param($dateFilterTypes, ...$dateFilterParams);
    }
    $journalSummaryStmt->execute();
    $journalSummaryResult = $journalSummaryStmt->get_result();
    $journalSummary = $journalSummaryResult->fetch_all(MYSQLI_ASSOC);
    $journalSummaryStmt->close();

    // =========================================================
    // 8. OVERVIEW COUNTS
    // =========================================================
    $overviewQueries = [
        'total_clients'  => "SELECT COUNT(*) AS cnt FROM clients_table",
        'total_invoices' => "SELECT COUNT(*) AS cnt FROM invoice_table",
        'total_journals' => "SELECT COUNT(*) AS cnt FROM journal_table",
        'total_users'    => "SELECT COUNT(*) AS cnt FROM admin_table",
    ];

    $overview = [];
    foreach ($overviewQueries as $key => $q) {
        $stmt = $conn->query($q);
        $overview[$key] = $stmt ? (int) $stmt->fetch_assoc()['cnt'] : 0;
    }

    // Latest exchange rates
    $ratesResult = $conn->query("SELECT ngn_rate, usd_rate, gbp_rate, eur_rate, created_at FROM currency_table ORDER BY created_at DESC LIMIT 1");
    $latestRates = $ratesResult ? $ratesResult->fetch_assoc() : null;

    // =========================================================
    // 9. RECENT INVOICES (last 5)
    // =========================================================
    $recentInvoicesResult = $conn->query("
        SELECT invoice_number, clients_name, invoice_amount, currency, status, due_date, invoice_date
        FROM invoice_table
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentInvoices = $recentInvoicesResult ? $recentInvoicesResult->fetch_all(MYSQLI_ASSOC) : [];

    // =========================================================
    // Build response
    // =========================================================
    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Dashboard data fetched successfully",
        "filters" => [
            "date_from" => $dateFrom,
            "date_to"   => $dateTo
        ],
        "data" => [
            "overview"        => $overview,
            "latest_rates"    => $latestRates,
            "receivables"     => $receivables,
            "invoice_status"  => $invoiceStatus,
            "revenue_expenses"=> $revenueExpenses,
            "monthly_trend"   => $monthlyTrend,
            "bank_balances"   => [
                "accounts"    => $bankBalances,
                "total_ngn"   => $totalBankNGN,
                "total_usd"   => $totalBankUSD,
            ],
            "top_clients"     => $topClients,
            "journal_summary" => $journalSummary,
            "recent_invoices" => $recentInvoices,
        ]
    ]);

} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}