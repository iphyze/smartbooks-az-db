<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

/**
 * Smartbooks - Invoice Aging Report
 *
 * Schema-specific implementation based on your uploaded tables:
 *
 * invoice_table
 * - invoice_number    varchar
 * - invoice_date      varchar (YYYY-MM-DD in valid rows)
 * - due_date          varchar (YYYY-MM-DD in valid rows, but may contain bad legacy values)
 * - clients_id        int
 * - clients_name      varchar
 * - invoice_amount    varchar
 * - paid              float DEFAULT 0
 * - status            varchar: Paid, Pending, Partially Paid, Overdue, Cancelled
 * - currency          varchar
 *
 * main_invoice_table
 * - stores invoice line/tax/composition values: amount, discount, vat, wht, total
 * - invoice_table.invoice_amount already stores the invoice total used for receivables aging.
 *
 * Accounting treatment:
 * - Receivables aging is based on outstanding balance: invoice_amount - paid.
 * - Paid and Cancelled invoices are excluded.
 * - Pending, Partially Paid and Overdue invoices are included only where outstanding > 0.
 * - Aging is based on due_date where valid, otherwise invoice_date, otherwise created_at.
 * - Future due dates are treated as 0 days outstanding and fall in the 0-30 bucket.
 */

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'] ?? null;

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'], true)) {
        throw new Exception('Unauthorized: Only Admins or Controllers can access this resource', 401);
    }

    if (!isset($_GET['currency']) || empty(trim($_GET['currency']))) {
        throw new Exception("Missing required parameter: 'currency' is required.", 400);
    }

    $currency = strtoupper(trim($_GET['currency']));
    $allowedCurrencies = ['NGN', 'USD', 'EUR', 'GBP'];

    if (!in_array($currency, $allowedCurrencies, true)) {
        throw new Exception('Invalid currency supplied.', 400);
    }

    /**
     * Because invoice_amount is varchar and due_date/invoice_date are varchar in your schema,
     * we normalize them inside the query before grouping.
     */
    $dataQuery = "
        SELECT
            aged.clients_id,
            aged.clients_name,
            aged.currency,
            COUNT(*) AS invoice_count,
            SUM(CASE WHEN aged.normalized_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN aged.normalized_status = 'Partially Paid' THEN 1 ELSE 0 END) AS partially_paid_count,
            SUM(CASE WHEN aged.normalized_status = 'Overdue' THEN 1 ELSE 0 END) AS overdue_count,
            MIN(aged.aging_date) AS oldest_invoice_date,
            MAX(aged.days_outstanding) AS oldest_age_days,
            SUM(CASE WHEN aged.days_outstanding BETWEEN 0 AND 30 THEN aged.outstanding_amount ELSE 0 END) AS bucket_0_30,
            SUM(CASE WHEN aged.days_outstanding BETWEEN 31 AND 60 THEN aged.outstanding_amount ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN aged.days_outstanding BETWEEN 61 AND 90 THEN aged.outstanding_amount ELSE 0 END) AS bucket_61_90,
            SUM(CASE WHEN aged.days_outstanding > 90 THEN aged.outstanding_amount ELSE 0 END) AS bucket_91_plus,
            SUM(aged.outstanding_amount) AS total_outstanding
        FROM (
            SELECT
                normalized.clients_id,
                normalized.clients_name,
                normalized.currency,
                normalized.normalized_status,
                normalized.aging_date,
                GREATEST(DATEDIFF(CURDATE(), normalized.aging_date), 0) AS days_outstanding,
                GREATEST(normalized.invoice_total - normalized.amount_paid, 0) AS outstanding_amount
            FROM (
                SELECT
                    clients_id,
                    clients_name,
                    currency,
                    CASE
                        WHEN LOWER(TRIM(status)) = 'partially paid' THEN 'Partially Paid'
                        WHEN LOWER(TRIM(status)) = 'overdue' THEN 'Overdue'
                        ELSE 'Pending'
                    END AS normalized_status,
                    CAST(REPLACE(NULLIF(invoice_amount, ''), ',', '') AS DECIMAL(18, 2)) AS invoice_total,
                    COALESCE(paid, 0) AS amount_paid,
                    COALESCE(
                        CASE
                            WHEN due_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                            THEN STR_TO_DATE(due_date, '%Y-%m-%d')
                        END,
                        CASE
                            WHEN invoice_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                            THEN STR_TO_DATE(invoice_date, '%Y-%m-%d')
                        END,
                        DATE(created_at)
                    ) AS aging_date
                FROM invoice_table
                WHERE currency = ?
                  AND LOWER(TRIM(status)) IN ('pending', 'partially paid', 'overdue')
            ) AS normalized
        ) AS aged
        WHERE aged.outstanding_amount > 0
        GROUP BY aged.clients_id, aged.clients_name, aged.currency
        ORDER BY total_outstanding DESC, aged.clients_name ASC
    ";

    $dataStmt = $conn->prepare($dataQuery);

    if (!$dataStmt) {
        throw new Exception('Failed to prepare data query: ' . $conn->error, 500);
    }

    $dataStmt->bind_param('s', $currency);
    $dataStmt->execute();

    $result = $dataStmt->get_result();
    $reportData = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    $totals = [
        'total_bucket_0_30' => 0.0,
        'total_bucket_31_60' => 0.0,
        'total_bucket_61_90' => 0.0,
        'total_bucket_91_plus' => 0.0,
        'grand_total_outstanding' => 0.0,
        'invoice_count' => 0,
        'pending_count' => 0,
        'partially_paid_count' => 0,
        'overdue_count' => 0,
        'client_count' => count($reportData),
        'overdue_exposure' => 0.0,
        'overdue_exposure_percent' => 0.0,
        'high_risk_exposure_percent' => 0.0,
    ];

    foreach ($reportData as &$row) {
        $row['bucket_0_30'] = (float) $row['bucket_0_30'];
        $row['bucket_31_60'] = (float) $row['bucket_31_60'];
        $row['bucket_61_90'] = (float) $row['bucket_61_90'];
        $row['bucket_91_plus'] = (float) $row['bucket_91_plus'];
        $row['total_outstanding'] = (float) $row['total_outstanding'];
        $row['invoice_count'] = (int) $row['invoice_count'];
        $row['pending_count'] = (int) $row['pending_count'];
        $row['partially_paid_count'] = (int) $row['partially_paid_count'];
        $row['overdue_count'] = (int) $row['overdue_count'];
        $row['oldest_age_days'] = (int) $row['oldest_age_days'];

        $totals['total_bucket_0_30'] += $row['bucket_0_30'];
        $totals['total_bucket_31_60'] += $row['bucket_31_60'];
        $totals['total_bucket_61_90'] += $row['bucket_61_90'];
        $totals['total_bucket_91_plus'] += $row['bucket_91_plus'];
        $totals['grand_total_outstanding'] += $row['total_outstanding'];
        $totals['invoice_count'] += $row['invoice_count'];
        $totals['pending_count'] += $row['pending_count'];
        $totals['partially_paid_count'] += $row['partially_paid_count'];
        $totals['overdue_count'] += $row['overdue_count'];
    }
    unset($row);

    $totals['overdue_exposure'] = $totals['total_bucket_31_60'] + $totals['total_bucket_61_90'] + $totals['total_bucket_91_plus'];

    if ($totals['grand_total_outstanding'] > 0) {
        $totals['overdue_exposure_percent'] = round(($totals['overdue_exposure'] / $totals['grand_total_outstanding']) * 100, 2);
        $totals['high_risk_exposure_percent'] = round(($totals['total_bucket_91_plus'] / $totals['grand_total_outstanding']) * 100, 2);
    }

    http_response_code(200);

    echo json_encode([
        'status' => 'Success',
        'message' => 'Invoice aging report fetched successfully',
        'data' => $reportData,
        'totals' => $totals,
        'meta' => [
            'currency' => $currency,
            'as_of_date' => date('Y-m-d'),
            'aging_basis' => 'due_date, falling back to invoice_date, then created_at',
            'outstanding_basis' => 'invoice_table.invoice_amount minus invoice_table.paid',
            'source_tables' => [
                'invoice_table' => 'Receivables status, invoice total, paid amount, dates and currency',
                'main_invoice_table' => 'Invoice line/tax composition; not required for receivables aging because invoice_table.invoice_amount stores invoice total',
            ],
            'included_statuses' => ['Pending', 'Partially Paid', 'Overdue'],
            'excluded_statuses' => ['Paid', 'Cancelled'],
        ],
    ]);

} catch (Exception $e) {

    error_log('Invoice Aging Error: ' . $e->getMessage());

    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    http_response_code($code);

    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
