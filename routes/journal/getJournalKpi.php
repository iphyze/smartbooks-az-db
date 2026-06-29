<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'], true)) {
        throw new Exception('Unauthorized', 401);
    }

    // Each journal header stores balanced totals. GREATEST selects one side once,
    // avoiding double-counting while retaining the stored NGN equivalent.
    $amountExpression = "
        GREATEST(
            ABS(COALESCE(CAST(NULLIF(TRIM(debit_ngn), '') AS DECIMAL(20,2)), 0)),
            ABS(COALESCE(CAST(NULLIF(TRIM(credit_ngn), '') AS DECIMAL(20,2)), 0)),
            ABS(COALESCE(CAST(NULLIF(TRIM(debit), '') AS DECIMAL(20,2)), 0)),
            ABS(COALESCE(CAST(NULLIF(TRIM(credit), '') AS DECIMAL(20,2)), 0))
        )
    ";

    $sql = "
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM($amountExpression), 0) AS total_amount_ngn,

            COALESCE(SUM(
                CASE
                    WHEN MONTH(STR_TO_DATE(journal_date, '%Y-%m-%d')) = MONTH(CURDATE())
                     AND YEAR(STR_TO_DATE(journal_date, '%Y-%m-%d')) = YEAR(CURDATE())
                    THEN 1 ELSE 0
                END
            ), 0) AS this_month_count,
            COALESCE(SUM(
                CASE
                    WHEN MONTH(STR_TO_DATE(journal_date, '%Y-%m-%d')) = MONTH(CURDATE())
                     AND YEAR(STR_TO_DATE(journal_date, '%Y-%m-%d')) = YEAR(CURDATE())
                    THEN $amountExpression ELSE 0
                END
            ), 0) AS this_month_amount_ngn,

            COALESCE(SUM(CASE WHEN journal_type = 'Sales' THEN 1 ELSE 0 END), 0) AS sales_count,
            COALESCE(SUM(CASE WHEN journal_type = 'Sales' THEN $amountExpression ELSE 0 END), 0) AS sales_amount_ngn,

            COALESCE(SUM(CASE WHEN journal_type = 'Receipt' THEN 1 ELSE 0 END), 0) AS receipt_count,
            COALESCE(SUM(CASE WHEN journal_type = 'Receipt' THEN $amountExpression ELSE 0 END), 0) AS receipt_amount_ngn,

            COALESCE(SUM(CASE WHEN journal_type = 'Payment' THEN 1 ELSE 0 END), 0) AS payment_count,
            COALESCE(SUM(CASE WHEN journal_type = 'Payment' THEN $amountExpression ELSE 0 END), 0) AS payment_amount_ngn,

            COALESCE(SUM(CASE WHEN journal_type NOT IN ('Sales', 'Receipt', 'Payment') THEN 1 ELSE 0 END), 0) AS other_count,
            COALESCE(SUM(CASE WHEN journal_type NOT IN ('Sales', 'Receipt', 'Payment') THEN $amountExpression ELSE 0 END), 0) AS other_amount_ngn
        FROM journal_table
    ";

    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception('DB Error (journal KPI query): ' . $conn->error, 500);
    }

    $row = $result->fetch_assoc();
    if (!$row) {
        throw new Exception('No data returned from journal_table.', 500);
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Journal KPIs fetched successfully',
        'data' => [
            'total' => [
                'count' => (int) $row['total_count'],
                'amount_ngn' => round((float) $row['total_amount_ngn'], 2),
            ],
            'this_month' => [
                'count' => (int) $row['this_month_count'],
                'amount_ngn' => round((float) $row['this_month_amount_ngn'], 2),
            ],
            'sales' => [
                'count' => (int) $row['sales_count'],
                'amount_ngn' => round((float) $row['sales_amount_ngn'], 2),
            ],
            'receipts' => [
                'count' => (int) $row['receipt_count'],
                'amount_ngn' => round((float) $row['receipt_amount_ngn'], 2),
            ],
            'payments' => [
                'count' => (int) $row['payment_count'],
                'amount_ngn' => round((float) $row['payment_amount_ngn'], 2),
            ],
            'other' => [
                'count' => (int) $row['other_count'],
                'amount_ngn' => round((float) $row['other_amount_ngn'], 2),
            ],
        ],
    ]);
} catch (Exception $e) {
    error_log('Journal KPI Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($e),
    ]);
}
