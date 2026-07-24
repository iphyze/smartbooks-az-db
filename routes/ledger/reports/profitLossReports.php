<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/financialStatementHelpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];
    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'], true)) {
        throw new Exception('Unauthorized: Only Admins or Controllers can access this resource', 401);
    }

    $requiredParams = ['datefrom', 'dateto', 'currency', 'zerobal'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim((string) $_GET[$param]))) {
            throw new Exception("Missing required parameter: '{$param}' is required.", 400);
        }
    }

    $datefrom = trim((string) $_GET['datefrom']);
    $dateto = trim((string) $_GET['dateto']);
    $currency = trim((string) $_GET['currency']);
    $zerobal = trim((string) $_GET['zerobal']);

    $allowedCurrencies = [
        'NGN' => 'ngn_rate',
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate',
    ];
    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception('Invalid currency specified.', 400);
    }
    $rateCol = $allowedCurrencies[$currency];
    smartbooksFinancialStatementAssertStoredRates($conn, $rateCol, $dateto, $datefrom);
    $categories = smartbooksFinancialStatementPnlCategories();
    $pnlCondition = smartbooksFinancialStatementPnlSqlCondition('l');

    if ($zerobal === 'Yes') {
        $dataQuery = "
            SELECT
                l.ledger_name,
                l.ledger_number,
                l.ledger_sub_class,
                l.ledger_type,
                COALESCE(SUM(m.debit_ngn / NULLIF(m.{$rateCol}, 0)), 0) AS total_debit,
                COALESCE(SUM(m.credit_ngn / NULLIF(m.{$rateCol}, 0)), 0) AS total_credit
            FROM ledger_table l
            LEFT JOIN main_journal_table m
                ON l.ledger_number = m.ledger_number
               AND m.journal_date BETWEEN ? AND ?
               AND NOT EXISTS (
                    SELECT 1
                    FROM fiscal_year_closures c
                    WHERE c.journal_id = m.journal_id
                       OR c.reversal_journal_id = m.journal_id
               )
            WHERE {$pnlCondition}
            GROUP BY l.ledger_name, l.ledger_number, l.ledger_sub_class, l.ledger_type
            ORDER BY l.ledger_number ASC
        ";
    } else {
        $dataQuery = "
            SELECT
                m.ledger_name,
                m.ledger_number,
                m.ledger_sub_class,
                m.ledger_type,
                SUM(m.debit_ngn / NULLIF(m.{$rateCol}, 0)) AS total_debit,
                SUM(m.credit_ngn / NULLIF(m.{$rateCol}, 0)) AS total_credit
            FROM main_journal_table m
            WHERE m.journal_date BETWEEN ? AND ?
              AND " . smartbooksFinancialStatementPnlSqlCondition('m') . "
              AND NOT EXISTS (
                    SELECT 1
                    FROM fiscal_year_closures c
                    WHERE c.journal_id = m.journal_id
                       OR c.reversal_journal_id = m.journal_id
              )
            GROUP BY m.ledger_name, m.ledger_number, m.ledger_sub_class, m.ledger_type
            ORDER BY m.ledger_number ASC
        ";
    }

    $stmt = $conn->prepare($dataQuery);
    if (!$stmt) {
        throw new Exception('DB Error: ' . $conn->error);
    }
    $stmt->bind_param('ss', $datefrom, $dateto);
    $stmt->execute();

    $reportData = [];
    $totals = [];
    foreach ($categories as $key => $config) {
        $reportData[$key] = [
            'title' => $config['title'],
            'records' => [],
            'total' => 0.0,
        ];
        $totals[$key] = 0.0;
    }

    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = smartbooksFinancialStatementPnlCategoryKey(
            (string) $row['ledger_sub_class'],
            (string) $row['ledger_type']
        );
        if ($key === null) {
            continue;
        }

        $config = $categories[$key];
        $reportBalance = smartbooksFinancialStatementPnlAmount(
            (float) $row['total_debit'],
            (float) $row['total_credit'],
            (string) $config['nature']
        );

        $reportData[$key]['records'][] = [
            'ledger_name' => trim((string) $row['ledger_name']),
            'ledger_number' => trim((string) $row['ledger_number']),
            'balance' => $reportBalance,
        ];
        $reportData[$key]['total'] += $reportBalance;
        $totals[$key] += $reportBalance;
    }
    $stmt->close();

    $summary = smartbooksFinancialStatementPnlSummary($totals);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Profit and Loss report fetched successfully',
        'data' => $reportData,
        'summary' => $summary,
        'meta' => [
            'currency' => $currency,
            'datefrom' => $datefrom,
            'dateto' => $dateto,
            'zerobal' => $zerobal,
        ],
    ]);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($e),
    ]);
}
