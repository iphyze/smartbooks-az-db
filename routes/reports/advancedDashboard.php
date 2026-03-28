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

    // ========================================================================
    // DATE RANGE VALIDATION & SETUP
    // ========================================================================
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
    $dateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : null;

    // Validate date formats if provided (YYYY-MM-DD)
    if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        throw new Exception("Invalid date_from format. Use YYYY-MM-DD.", 400);
    }
    if ($dateTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        throw new Exception("Invalid date_to format. Use YYYY-MM-DD.", 400);
    }

    // =========================================================
    // 1. RECEIVABLES — unpaid invoices grouped by currency
    //    Filters by invoice_date (when invoice was issued)
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
        WHERE status != 'Paid'";

    $receivablesParams = [];
    $receivablesTypes = "";

    if ($dateFrom && $dateTo) {
        $receivablesQuery .= " AND invoice_date BETWEEN ? AND ?";
        $receivablesParams = [$dateFrom, $dateTo];
        $receivablesTypes = "ss";
    } elseif ($dateFrom) {
        $receivablesQuery .= " AND invoice_date >= ?";
        $receivablesParams = [$dateFrom];
        $receivablesTypes = "s";
    } elseif ($dateTo) {
        $receivablesQuery .= " AND invoice_date <= ?";
        $receivablesParams = [$dateTo];
        $receivablesTypes = "s";
    }

    $receivablesQuery .= " GROUP BY currency";

    $receivablesStmt = $conn->prepare($receivablesQuery);
    if (!$receivablesStmt) {
        throw new Exception("Failed to prepare receivables query: " . $conn->error, 500);
    }
    if (!empty($receivablesParams)) {
        $receivablesStmt->bind_param($receivablesTypes, ...$receivablesParams);
    }
    $receivablesStmt->execute();
    $receivablesResult = $receivablesStmt->get_result();
    $receivables = $receivablesResult->fetch_all(MYSQLI_ASSOC);
    $receivablesStmt->close();

    // =========================================================
    // 2. INVOICE STATUS BREAKDOWN (all invoices in period)
    //    Filters by invoice_date
    // =========================================================
    $invoiceStatusQuery = "
        SELECT
            status,
            COUNT(*) AS count,
            SUM(invoice_amount) AS total_amount,
            currency
        FROM invoice_table
        WHERE 1=1";

    $invoiceStatusParams = [];
    $invoiceStatusTypes = "";

    if ($dateFrom && $dateTo) {
        $invoiceStatusQuery .= " AND invoice_date BETWEEN ? AND ?";
        $invoiceStatusParams = [$dateFrom, $dateTo];
        $invoiceStatusTypes = "ss";
    } elseif ($dateFrom) {
        $invoiceStatusQuery .= " AND invoice_date >= ?";
        $invoiceStatusParams = [$dateFrom];
        $invoiceStatusTypes = "s";
    } elseif ($dateTo) {
        $invoiceStatusQuery .= " AND invoice_date <= ?";
        $invoiceStatusParams = [$dateTo];
        $invoiceStatusTypes = "s";
    }

    $invoiceStatusQuery .= " GROUP BY status, currency ORDER BY currency, status";

    $invoiceStatusStmt = $conn->prepare($invoiceStatusQuery);
    if (!$invoiceStatusStmt) {
        throw new Exception("Failed to prepare invoice status query: " . $conn->error, 500);
    }
    if (!empty($invoiceStatusParams)) {
        $invoiceStatusStmt->bind_param($invoiceStatusTypes, ...$invoiceStatusParams);
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
    //    Filters by journal_date (activity within period)
    // =========================================================
    $revenueExpenseQuery = "
        SELECT
            journal_currency AS currency,
            SUM(CASE WHEN journal_type = 'Revenue' THEN credit ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN journal_type = 'Expense' THEN debit  ELSE 0 END) AS total_expenses,
            SUM(CASE WHEN journal_type = 'Revenue' THEN credit_ngn ELSE 0 END) AS total_revenue_ngn,
            SUM(CASE WHEN journal_type = 'Expense' THEN debit_ngn  ELSE 0 END) AS total_expenses_ngn
        FROM journal_table
        WHERE 1=1";

    $revenueExpenseParams = [];
    $revenueExpenseTypes = "";

    if ($dateFrom && $dateTo) {
        $revenueExpenseQuery .= " AND journal_date BETWEEN ? AND ?";
        $revenueExpenseParams = [$dateFrom, $dateTo];
        $revenueExpenseTypes = "ss";
    } elseif ($dateFrom) {
        $revenueExpenseQuery .= " AND journal_date >= ?";
        $revenueExpenseParams = [$dateFrom];
        $revenueExpenseTypes = "s";
    } elseif ($dateTo) {
        $revenueExpenseQuery .= " AND journal_date <= ?";
        $revenueExpenseParams = [$dateTo];
        $revenueExpenseTypes = "s";
    }

    $revenueExpenseQuery .= " GROUP BY journal_currency";

    $revenueExpenseStmt = $conn->prepare($revenueExpenseQuery);
    if (!$revenueExpenseStmt) {
        throw new Exception("Failed to prepare revenue/expense query: " . $conn->error, 500);
    }
    if (!empty($revenueExpenseParams)) {
        $revenueExpenseStmt->bind_param($revenueExpenseTypes, ...$revenueExpenseParams);
    }
    $revenueExpenseStmt->execute();
    $revenueExpenseResult = $revenueExpenseStmt->get_result();
    $revenueExpenses = $revenueExpenseResult->fetch_all(MYSQLI_ASSOC);
    $revenueExpenseStmt->close();

    // =========================================================
    // 4. MONTHLY REVENUE TREND (respects date range filter)
    //    If no date range, defaults to last 12 months
    // =========================================================
    $monthlyTrendQuery = "
        SELECT
            DATE_FORMAT(journal_date, '%Y-%m') AS month,
            journal_currency AS currency,
            SUM(CASE WHEN journal_type = 'Revenue' THEN credit ELSE 0 END) AS revenue,
            SUM(CASE WHEN journal_type = 'Expense' THEN debit  ELSE 0 END) AS expenses
        FROM journal_table
        WHERE 1=1";

    $monthlyTrendParams = [];
    $monthlyTrendTypes = "";

    // If date range provided, use it. Otherwise default to 12 months
    if ($dateFrom || $dateTo) {
        if ($dateFrom && $dateTo) {
            $monthlyTrendQuery .= " AND journal_date BETWEEN ? AND ?";
            $monthlyTrendParams = [$dateFrom, $dateTo];
            $monthlyTrendTypes = "ss";
        } elseif ($dateFrom) {
            $monthlyTrendQuery .= " AND journal_date >= ?";
            $monthlyTrendParams = [$dateFrom];
            $monthlyTrendTypes = "s";
        } elseif ($dateTo) {
            $monthlyTrendQuery .= " AND journal_date <= ?";
            $monthlyTrendParams = [$dateTo];
            $monthlyTrendTypes = "s";
        }
    } else {
        // Default: last 12 months
        $monthlyTrendQuery .= " AND journal_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
    }

    $monthlyTrendQuery .= " GROUP BY DATE_FORMAT(journal_date, '%Y-%m'), journal_currency
                           ORDER BY month ASC, journal_currency ASC";

    $monthlyTrendStmt = $conn->prepare($monthlyTrendQuery);
    if (!$monthlyTrendStmt) {
        throw new Exception("Failed to prepare monthly trend query: " . $conn->error, 500);
    }
    if (!empty($monthlyTrendParams)) {
        $monthlyTrendStmt->bind_param($monthlyTrendTypes, ...$monthlyTrendParams);
    }
    $monthlyTrendStmt->execute();
    $monthlyTrendResult = $monthlyTrendStmt->get_result();
    $monthlyTrend = $monthlyTrendResult->fetch_all(MYSQLI_ASSOC);
    $monthlyTrendStmt->close();

    // =========================================================
    // 5. BANK BALANCES — derived from journal line items
    //    NOW RESPECTS DATE FILTERING: shows cumulative balance
    //    up to the end date (or latest if no date specified)
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
          AND mjt.ledger_type  = 'Bank'";

    $bankBalancesParams = [];
    $bankBalancesTypes = "";

    if ($dateFrom && $dateTo) {
        $bankBalancesQuery .= " AND jt.journal_date <= ?";
        $bankBalancesParams = [$dateTo];
        $bankBalancesTypes = "s";
    } elseif ($dateTo) {
        $bankBalancesQuery .= " AND jt.journal_date <= ?";
        $bankBalancesParams = [$dateTo];
        $bankBalancesTypes = "s";
    }
    // Note: dateFrom on bank balances doesn't make sense as it's a balance sheet item
    // We use dateTo as the "as of" date

    $bankBalancesQuery .= " GROUP BY mjt.ledger_name, mjt.ledger_number, mjt.cost_center, jt.journal_currency
                           ORDER BY mjt.ledger_name ASC";

    $bankBalancesStmt = $conn->prepare($bankBalancesQuery);
    if (!$bankBalancesStmt) {
        throw new Exception("Failed to prepare bank balances query: " . $conn->error, 500);
    }
    if (!empty($bankBalancesParams)) {
        $bankBalancesStmt->bind_param($bankBalancesTypes, ...$bankBalancesParams);
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
    // 6. TOP CLIENTS BY INVOICE AMOUNT (in period)
    //    Filters by invoice_date
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
        WHERE 1=1";

    $topClientsParams = [];
    $topClientsTypes = "";

    if ($dateFrom && $dateTo) {
        $topClientsQuery .= " AND invoice_date BETWEEN ? AND ?";
        $topClientsParams = [$dateFrom, $dateTo];
        $topClientsTypes = "ss";
    } elseif ($dateFrom) {
        $topClientsQuery .= " AND invoice_date >= ?";
        $topClientsParams = [$dateFrom];
        $topClientsTypes = "s";
    } elseif ($dateTo) {
        $topClientsQuery .= " AND invoice_date <= ?";
        $topClientsParams = [$dateTo];
        $topClientsTypes = "s";
    }

    $topClientsQuery .= " GROUP BY clients_name, clients_id, currency
                         ORDER BY total_billed DESC
                         LIMIT 10";

    $topClientsStmt = $conn->prepare($topClientsQuery);
    if (!$topClientsStmt) {
        throw new Exception("Failed to prepare top clients query: " . $conn->error, 500);
    }
    if (!empty($topClientsParams)) {
        $topClientsStmt->bind_param($topClientsTypes, ...$topClientsParams);
    }
    $topClientsStmt->execute();
    $topClientsResult = $topClientsStmt->get_result();
    $topClients = $topClientsResult->fetch_all(MYSQLI_ASSOC);
    $topClientsStmt->close();

    // =========================================================
    // 7. JOURNAL SUMMARY — debits vs credits by type
    //    Filters by journal_date (activity within period)
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
        WHERE 1=1";

    $journalSummaryParams = [];
    $journalSummaryTypes = "";

    if ($dateFrom && $dateTo) {
        $journalSummaryQuery .= " AND journal_date BETWEEN ? AND ?";
        $journalSummaryParams = [$dateFrom, $dateTo];
        $journalSummaryTypes = "ss";
    } elseif ($dateFrom) {
        $journalSummaryQuery .= " AND journal_date >= ?";
        $journalSummaryParams = [$dateFrom];
        $journalSummaryTypes = "s";
    } elseif ($dateTo) {
        $journalSummaryQuery .= " AND journal_date <= ?";
        $journalSummaryParams = [$dateTo];
        $journalSummaryTypes = "s";
    }

    $journalSummaryQuery .= " GROUP BY journal_type, transaction_type, journal_currency
                              ORDER BY journal_type, transaction_type";

    $journalSummaryStmt = $conn->prepare($journalSummaryQuery);
    if (!$journalSummaryStmt) {
        throw new Exception("Failed to prepare journal summary query: " . $conn->error, 500);
    }
    if (!empty($journalSummaryParams)) {
        $journalSummaryStmt->bind_param($journalSummaryTypes, ...$journalSummaryParams);
    }
    $journalSummaryStmt->execute();
    $journalSummaryResult = $journalSummaryStmt->get_result();
    $journalSummary = $journalSummaryResult->fetch_all(MYSQLI_ASSOC);
    $journalSummaryStmt->close();

    // =========================================================
    // 8. OVERVIEW COUNTS — NOW WITH DATE FILTERING
    //    Counts records created within the period
    // =========================================================
    $overviewQueries = [
        'total_clients'  => ["table" => "clients_table", "field" => "created_at"],
        'total_invoices' => ["table" => "invoice_table", "field" => "invoice_date"],
        'total_journals' => ["table" => "journal_table", "field" => "journal_date"],
        'total_users'    => ["table" => "admin_table", "field" => "created_at"],
    ];

    $overview = [];
    foreach ($overviewQueries as $key => $config) {
        $q = "SELECT COUNT(*) AS cnt FROM " . $config['table'] . " WHERE 1=1";
        
        $overviewParams = [];
        $overviewTypes = "";

        if ($dateFrom && $dateTo) {
            $q .= " AND " . $config['field'] . " BETWEEN ? AND ?";
            $overviewParams = [$dateFrom, $dateTo];
            $overviewTypes = "ss";
        } elseif ($dateFrom) {
            $q .= " AND " . $config['field'] . " >= ?";
            $overviewParams = [$dateFrom];
            $overviewTypes = "s";
        } elseif ($dateTo) {
            $q .= " AND " . $config['field'] . " <= ?";
            $overviewParams = [$dateTo];
            $overviewTypes = "s";
        }

        $stmt = $conn->prepare($q);
        if (!$stmt) {
            $overview[$key] = 0;
            continue;
        }

        if (!empty($overviewParams)) {
            $stmt->bind_param($overviewTypes, ...$overviewParams);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $overview[$key] = (int) $result->fetch_assoc()['cnt'];
        $stmt->close();
    }

    // =========================================================
    // 9. LATEST EXCHANGE RATES
    //    If date range provided, get the rate closest to end date
    // =========================================================
    $ratesQuery = "SELECT ngn_rate, usd_rate, gbp_rate, eur_rate, created_at FROM currency_table";
    
    $ratesParams = [];
    $ratesTypes = "";

    if ($dateTo) {
        $ratesQuery .= " WHERE created_at <= ? ORDER BY created_at DESC LIMIT 1";
        $ratesParams = [$dateTo . " 23:59:59"];
        $ratesTypes = "s";
    } else {
        $ratesQuery .= " ORDER BY created_at DESC LIMIT 1";
    }

    $ratesStmt = $conn->prepare($ratesQuery);
    if ($ratesStmt) {
        if (!empty($ratesParams)) {
            $ratesStmt->bind_param($ratesTypes, ...$ratesParams);
        }
        $ratesStmt->execute();
        $ratesResult = $ratesStmt->get_result();
        $latestRates = $ratesResult->fetch_assoc();
        $ratesStmt->close();
    } else {
        $latestRates = null;
    }

    // =========================================================
    // 10. RECENT INVOICES (last 5 in period)
    //     Filters by invoice_date
    // =========================================================
    $recentInvoicesQuery = "
        SELECT invoice_number, clients_name, invoice_amount, currency, status, due_date, invoice_date
        FROM invoice_table
        WHERE 1=1";

    $recentInvoicesParams = [];
    $recentInvoicesTypes = "";

    if ($dateFrom && $dateTo) {
        $recentInvoicesQuery .= " AND invoice_date BETWEEN ? AND ?";
        $recentInvoicesParams = [$dateFrom, $dateTo];
        $recentInvoicesTypes = "ss";
    } elseif ($dateFrom) {
        $recentInvoicesQuery .= " AND invoice_date >= ?";
        $recentInvoicesParams = [$dateFrom];
        $recentInvoicesTypes = "s";
    } elseif ($dateTo) {
        $recentInvoicesQuery .= " AND invoice_date <= ?";
        $recentInvoicesParams = [$dateTo];
        $recentInvoicesTypes = "s";
    }

    $recentInvoicesQuery .= " ORDER BY invoice_date DESC, created_at DESC LIMIT 5";

    $recentInvoicesStmt = $conn->prepare($recentInvoicesQuery);
    if ($recentInvoicesStmt) {
        if (!empty($recentInvoicesParams)) {
            $recentInvoicesStmt->bind_param($recentInvoicesTypes, ...$recentInvoicesParams);
        }
        $recentInvoicesStmt->execute();
        $recentInvoicesResult = $recentInvoicesStmt->get_result();
        $recentInvoices = $recentInvoicesResult->fetch_all(MYSQLI_ASSOC);
        $recentInvoicesStmt->close();
    } else {
        $recentInvoices = [];
    }

    // =========================================================
    // Build response with consistent structure
    // =========================================================
    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Dashboard data fetched successfully",
        "meta" => [
            "date_from" => $dateFrom,
            "date_to"   => $dateTo,
            "generated_at" => date('Y-m-d H:i:s'),
            "note" => "All queries respect the date range filters provided"
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
                "total_ngn"   => round($totalBankNGN, 2),
                "total_usd"   => round($totalBankUSD, 2),
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
