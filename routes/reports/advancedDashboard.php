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
    // DATE RANGE VALIDATION & DEFAULTS
    // Default: start of current year → today
    // Restriction: max 1 year span (for clean accounting periods)
    // ========================================================================
    $today      = date('Y-m-d');
    $yearStart  = date('Y') . '-01-01';

    $dateFrom = isset($_GET['date_from']) && trim($_GET['date_from']) !== '' ? trim($_GET['date_from']) : $yearStart;
    $dateTo   = isset($_GET['date_to'])   && trim($_GET['date_to'])   !== '' ? trim($_GET['date_to'])   : $today;

    // Validate formats
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        throw new Exception("Invalid date_from format. Use YYYY-MM-DD.", 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        throw new Exception("Invalid date_to format. Use YYYY-MM-DD.", 400);
    }

    // Enforce max 1-year range
    $fromTs = strtotime($dateFrom);
    $toTs   = strtotime($dateTo);
    if ($toTs < $fromTs) {
        throw new Exception("date_to must be on or after date_from.", 400);
    }
    $diffDays = ($toTs - $fromTs) / 86400;
    if ($diffDays > 366) {
        throw new Exception("Date range cannot exceed one year (366 days).", 400);
    }

    // Helper: bind params to a prepared stmt and execute → fetch all
    function runQuery($conn, $sql, $types = '', $params = []) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error, 500);
        if ($types && count($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    function runQuerySingle($conn, $sql, $types = '', $params = []) {
        $rows = runQuery($conn, $sql, $types, $params);
        return $rows[0] ?? null;
    }

    // Common date-range snippet builders
    $rangeParams = [$dateFrom, $dateTo];
    $rangeTypes  = "ss";

    // =========================================================
    // 1. RECEIVABLES — unpaid invoices grouped by currency
    //    current_receivables = not yet due, overdue_receivables = past due
    // =========================================================
    $receivables = runQuery($conn,
        "SELECT
            currency,
            SUM(invoice_amount)  AS total_receivables,
            SUM(CASE WHEN due_date >= CURDATE() THEN invoice_amount ELSE 0 END) AS current_receivables,
            SUM(CASE WHEN due_date <  CURDATE() THEN invoice_amount ELSE 0 END) AS overdue_receivables,
            COUNT(*)             AS total_invoices,
            SUM(CASE WHEN status = 'Paid'    THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN status = 'Unpaid'  THEN 1 ELSE 0 END) AS unpaid_count,
            SUM(CASE WHEN status = 'Partial' THEN 1 ELSE 0 END) AS partial_count
        FROM invoice_table
        WHERE status != 'Paid'
          AND invoice_date BETWEEN ? AND ?
        GROUP BY currency",
        $rangeTypes, $rangeParams
    );

    // =========================================================
    // 2. INVOICE STATUS BREAKDOWN
    // =========================================================
    $invoiceStatusRaw = runQuery($conn,
        "SELECT status, COUNT(*) AS count, SUM(invoice_amount) AS total_amount, currency
         FROM invoice_table
         WHERE invoice_date BETWEEN ? AND ?
         GROUP BY status, currency
         ORDER BY currency, status",
        $rangeTypes, $rangeParams
    );
    $invoiceStatus = [];
    foreach ($invoiceStatusRaw as $row) {
        $invoiceStatus[$row['currency']][] = [
            'status'       => $row['status'],
            'count'        => (int) $row['count'],
            'total_amount' => (float) $row['total_amount'],
        ];
    }

    // =========================================================
    // 3. REVENUE & EXPENSES
    //    Revenue  = journal_type IN ('Sales') → credit side in journal_table
    //    Expenses = journal_type IN ('Expenses') → debit side in journal_table
    //    We use main_journal_table which has proper ledger_type classification.
    //    Revenue ledgers: ledger_type = 'Revenue'  (credit_ngn)
    //    Expense ledgers: ledger_class = 'Expense' (debit_ngn)
    // =========================================================
    $revenueExpenseRaw = runQuery($conn,
        "SELECT
            mjt.journal_currency                          AS currency,
            SUM(CASE WHEN mjt.ledger_type = 'Revenue'
                     THEN CAST(mjt.credit_ngn AS DECIMAL(20,4)) ELSE 0 END) AS total_revenue_ngn,
            SUM(CASE WHEN mjt.ledger_class = 'Expense'
                     THEN CAST(mjt.debit_ngn  AS DECIMAL(20,4)) ELSE 0 END) AS total_expenses_ngn,
            SUM(CASE WHEN mjt.ledger_type = 'Revenue'
                     THEN CAST(mjt.credit     AS DECIMAL(20,4)) ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN mjt.ledger_class = 'Expense'
                     THEN CAST(mjt.debit      AS DECIMAL(20,4)) ELSE 0 END) AS total_expenses
        FROM main_journal_table mjt
        WHERE mjt.journal_date BETWEEN ? AND ?
        GROUP BY mjt.journal_currency",
        $rangeTypes, $rangeParams
    );
    $revenueExpenses = $revenueExpenseRaw;

    // =========================================================
    // 4. MONTHLY REVENUE & EXPENSE TREND
    //    Uses main_journal_table for accurate ledger classification
    // =========================================================
    $monthlyTrend = runQuery($conn,
        "SELECT
            DATE_FORMAT(mjt.journal_date, '%Y-%m') AS month,
            mjt.journal_currency                    AS currency,
            SUM(CASE WHEN mjt.ledger_type = 'Revenue'
                     THEN CAST(mjt.credit AS DECIMAL(20,4)) ELSE 0 END) AS revenue,
            SUM(CASE WHEN mjt.ledger_class = 'Expense'
                     THEN CAST(mjt.debit  AS DECIMAL(20,4)) ELSE 0 END) AS expenses
        FROM main_journal_table mjt
        WHERE mjt.journal_date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(mjt.journal_date, '%Y-%m'), mjt.journal_currency
        ORDER BY month ASC, currency ASC",
        $rangeTypes, $rangeParams
    );

    // =========================================================
    // 5. BANK BALANCES — cumulative up to dateTo
    //    Derived from main_journal_table where ledger_type = 'Bank Accounts'
    //    Balance = SUM(credit_ngn) - SUM(debit_ngn)  (normal asset: debit increases)
    //    Actually bank asset increases with debit, so balance = debit_ngn - credit_ngn
    // =========================================================
    $bankBalancesRaw = runQuery($conn,
        "SELECT
            mjt.ledger_name,
            mjt.ledger_number,
            mjt.journal_currency                                            AS currency,
            SUM(CAST(mjt.debit_ngn  AS DECIMAL(20,4))
              - CAST(mjt.credit_ngn AS DECIMAL(20,4)))                      AS balance_ngn,
            SUM(CASE WHEN mjt.journal_currency = 'USD'
                     THEN CAST(mjt.debit AS DECIMAL(20,4)) - CAST(mjt.credit AS DECIMAL(20,4))
                     ELSE 0 END)                                            AS balance_usd,
            SUM(CASE WHEN mjt.journal_currency = 'GBP'
                     THEN CAST(mjt.debit AS DECIMAL(20,4)) - CAST(mjt.credit AS DECIMAL(20,4))
                     ELSE 0 END)                                            AS balance_gbp,
            SUM(CASE WHEN mjt.journal_currency = 'EUR'
                     THEN CAST(mjt.debit AS DECIMAL(20,4)) - CAST(mjt.credit AS DECIMAL(20,4))
                     ELSE 0 END)                                            AS balance_eur
        FROM main_journal_table mjt
        WHERE mjt.ledger_type  = 'Bank Accounts'
          AND mjt.ledger_class = 'Asset'
          AND mjt.journal_date <= ?
        GROUP BY mjt.ledger_name, mjt.ledger_number, mjt.journal_currency
        ORDER BY mjt.ledger_name ASC",
        "s", [$dateTo]
    );

    // Aggregate per ledger (across currencies) into NGN totals, then sum
    $bankByLedger = [];
    foreach ($bankBalancesRaw as $b) {
        $key = $b['ledger_name'] . '|' . $b['ledger_number'];
        if (!isset($bankByLedger[$key])) {
            $bankByLedger[$key] = [
                'ledger_name'   => $b['ledger_name'],
                'ledger_number' => $b['ledger_number'],
                'balance_ngn'   => 0,
                'balance_usd'   => 0,
                'balance_gbp'   => 0,
                'balance_eur'   => 0,
            ];
        }
        $bankByLedger[$key]['balance_ngn'] += (float) $b['balance_ngn'];
        $bankByLedger[$key]['balance_usd'] += (float) $b['balance_usd'];
        $bankByLedger[$key]['balance_gbp'] += (float) $b['balance_gbp'];
        $bankByLedger[$key]['balance_eur'] += (float) $b['balance_eur'];
    }
    $bankAccounts = array_values($bankByLedger);

    $totalBankNGN = 0;
    $totalBankUSD = 0;
    $totalBankGBP = 0;
    $totalBankEUR = 0;
    foreach ($bankAccounts as $b) {
        $totalBankNGN += $b['balance_ngn'];
        $totalBankUSD += $b['balance_usd'];
        $totalBankGBP += $b['balance_gbp'];
        $totalBankEUR += $b['balance_eur'];
    }

    // =========================================================
    // 6. TOP CLIENTS BY INVOICE AMOUNT
    // =========================================================
    $topClients = runQuery($conn,
        "SELECT
            clients_name, clients_id, currency,
            COUNT(*)                                                    AS invoice_count,
            SUM(invoice_amount)                                         AS total_billed,
            SUM(CASE WHEN status = 'Paid' THEN invoice_amount ELSE 0 END) AS total_paid,
            SUM(CASE WHEN status != 'Paid' THEN invoice_amount ELSE 0 END) AS total_outstanding
         FROM invoice_table
         WHERE invoice_date BETWEEN ? AND ?
         GROUP BY clients_name, clients_id, currency
         ORDER BY total_billed DESC
         LIMIT 10",
        $rangeTypes, $rangeParams
    );

    // =========================================================
    // 7. JOURNAL SUMMARY — by journal_type and currency
    //    Using journal_table (header) grouped by journal_type
    //    Shows total debit/credit per type
    // =========================================================
    $journalSummary = runQuery($conn,
        "SELECT
            jt.journal_type,
            jt.journal_currency                       AS currency,
            COUNT(*)                                  AS entry_count,
            SUM(CAST(jt.debit  AS DECIMAL(20,4)))     AS total_debit,
            SUM(CAST(jt.credit AS DECIMAL(20,4)))     AS total_credit,
            SUM(CAST(jt.debit_ngn  AS DECIMAL(20,4))) AS total_debit_ngn,
            SUM(CAST(jt.credit_ngn AS DECIMAL(20,4))) AS total_credit_ngn
         FROM journal_table jt
         WHERE jt.journal_date BETWEEN ? AND ?
         GROUP BY jt.journal_type, jt.journal_currency
         ORDER BY jt.journal_type, jt.journal_currency",
        $rangeTypes, $rangeParams
    );

    // =========================================================
    // 8. REVENUE BREAKDOWN by ledger sub-type (for richer reporting)
    //    Sales vs Referrals vs Other Income
    // =========================================================
    $revenueBreakdownRaw = runQuery($conn,
        "SELECT
            mjt.ledger_name                           AS revenue_type,
            mjt.journal_currency                      AS currency,
            SUM(CAST(mjt.credit     AS DECIMAL(20,4))) AS total,
            SUM(CAST(mjt.credit_ngn AS DECIMAL(20,4))) AS total_ngn
         FROM main_journal_table mjt
         WHERE mjt.ledger_type = 'Revenue'
           AND mjt.journal_date BETWEEN ? AND ?
         GROUP BY mjt.ledger_name, mjt.journal_currency
         ORDER BY total_ngn DESC",
        $rangeTypes, $rangeParams
    );

    // =========================================================
    // 9. OVERVIEW COUNTS
    //    - total_users: ALL users (no date filter) — shows total system users
    //    - Others: filtered by date range
    // =========================================================
    $overview = [];

    // Date-filtered counts (clients, invoices, journals)
    $dateFilteredMap = [
        'total_clients'  => ["table" => "clients_table", "field" => "created_at"],
        'total_invoices' => ["table" => "invoice_table",  "field" => "invoice_date"],
        'total_journals' => ["table" => "journal_table",  "field" => "journal_date"],
    ];
    foreach ($dateFilteredMap as $key => $cfg) {
        $row = runQuerySingle($conn,
            "SELECT COUNT(*) AS cnt FROM {$cfg['table']} WHERE {$cfg['field']} BETWEEN ? AND ?",
            $rangeTypes, $rangeParams
        );
        $overview[$key] = (int)($row['cnt'] ?? 0);
    }

    // Total users — no date filter, fetches ALL users in the system
    $userRow = runQuerySingle($conn,
        "SELECT COUNT(*) AS cnt FROM admin_table"
    );
    $overview['total_users'] = (int)($userRow['cnt'] ?? 0);

    // =========================================================
    // 10. LATEST EXCHANGE RATES (closest rate on or before dateTo)
    // =========================================================
    $latestRates = runQuerySingle($conn,
        "SELECT ngn_rate, usd_rate, gbp_rate, eur_rate, created_at
         FROM currency_table
         WHERE DATE(created_at) <= ?
         ORDER BY created_at DESC LIMIT 1",
        "s", [$dateTo]
    );
    if (!$latestRates) {
        // Fallback: get the most recent rate ever
        $latestRates = runQuerySingle($conn,
            "SELECT ngn_rate, usd_rate, gbp_rate, eur_rate, created_at
             FROM currency_table ORDER BY created_at DESC LIMIT 1"
        );
    }

    // =========================================================
    // 11. RECENT INVOICES (last 5 in period)
    // =========================================================
    $recentInvoices = runQuery($conn,
        "SELECT invoice_number, clients_name, invoice_amount, currency, status, due_date, invoice_date
         FROM invoice_table
         WHERE invoice_date BETWEEN ? AND ?
         ORDER BY invoice_date DESC, created_at DESC LIMIT 5",
        $rangeTypes, $rangeParams
    );

    // =========================================================
    // Build response
    // =========================================================
    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Dashboard data fetched successfully",
        "meta" => [
            "date_from"    => $dateFrom,
            "date_to"      => $dateTo,
            "generated_at" => date('Y-m-d H:i:s'),
            "note"         => "Default range is current year-to-date. Max range is 366 days. Total users is always all-time.",
        ],
        "data" => [
            "overview"         => $overview,
            "latest_rates"     => $latestRates,
            "receivables"      => $receivables,
            "invoice_status"   => $invoiceStatus,
            "revenue_expenses" => $revenueExpenses,
            "monthly_trend"    => $monthlyTrend,
            "revenue_breakdown"=> $revenueBreakdownRaw,
            "bank_balances"    => [
                "accounts"  => $bankAccounts,
                "total_ngn" => round($totalBankNGN, 2),
                "total_usd" => round($totalBankUSD, 2),
                "total_gbp" => round($totalBankGBP, 2),
                "total_eur" => round($totalBankEUR, 2),
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