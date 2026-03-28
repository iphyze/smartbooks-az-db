<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    /**
     * Validate Inputs
     */
    $requiredParams = ['datefrom', 'dateto', 'currency', 'zerobal'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim($_GET[$param]))) {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $currency = trim($_GET['currency']);
    $zerobal  = trim($_GET['zerobal']);

    // Period year (Balance Sheet is cumulative up to year-end)
    $transacYear = (int) date('Y', strtotime($dateto));
    $period      = ($transacYear === (int) date('Y')) ? (int) date('Y') : $transacYear;

    /**
     * Whitelist currency
     */
    $allowedCurrencies = ['NGN' => 'ngn_rate', 'USD' => 'usd_rate', 'EUR' => 'eur_rate', 'GBP' => 'gbp_rate'];
    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency specified.", 400);
    }
    $rateCol = $allowedCurrencies[$currency];

    // ════════════════════════════════════════════════════════════════════════════
    // CALCULATION 1 — CURRENT YEAR EARNINGS (P&L)
    // ════════════════════════════════════════════════════════════════════════════

    $plCategories = [
        'Revenue'        => ['sub_class' => 'Revenue',                 'type' => 'Revenue',                                  'flip' => false],
        'CostOfServices' => ['sub_class' => 'Cost of Services',         'type' => 'Cost of Services',                        'flip' => false],
        'Administrative' => ['sub_class' => 'Administrative Expenses',  'type' => 'Administrative Expenses',                 'flip' => true],
        'Selling'        => ['sub_class' => 'Selling Expenses',         'type' => 'Selling Expenses',                        'flip' => true],
        'OtherIncome'    => ['sub_class' => 'Revenue',                  'type' => 'Other Income',                            'flip' => false],
        'Depreciation'   => ['sub_class' => 'Depreciation Expenses',    'type' => 'Depreciation, Amortization & Impairment', 'flip' => true],
        'FinanceCost'    => ['sub_class' => 'Finance Cost',             'type' => 'Finance Cost',                            'flip' => true],
        'Taxation'       => ['sub_class' => 'Taxation',                 'type' => 'Income & Other Taxes',                    'flip' => true],
    ];

    $plWhere = [];
    foreach ($plCategories as $c) {
        $sc = $conn->real_escape_string($c['sub_class']);
        $tp = $conn->real_escape_string($c['type']);
        $plWhere[] = "(ledger_sub_class = '$sc' AND ledger_type = '$tp')";
    }

    $plSQL = "
        SELECT
            ledger_sub_class,
            ledger_type,
            SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
            SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit
        FROM main_journal_table
        WHERE journal_date BETWEEN ? AND ?
          AND (" . implode(' OR ', $plWhere) . ")
        GROUP BY ledger_sub_class, ledger_type
    ";

    $stmtPL = $conn->prepare($plSQL);
    if (!$stmtPL) throw new Exception("DB Error (P&L): " . $conn->error);
    $stmtPL->bind_param("ss", $datefrom, $dateto);
    $stmtPL->execute();

    $plTotals = array_fill_keys(array_keys($plCategories), 0.0);
    foreach ($stmtPL->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        foreach ($plCategories as $key => $c) {
            if (trim($row['ledger_sub_class']) === $c['sub_class'] && trim($row['ledger_type']) === $c['type']) {
                $bal = (float)$row['total_debit'] - (float)$row['total_credit'];
                $plTotals[$key] += $c['flip'] ? ($bal * -1) : $bal;
                break;
            }
        }
    }
    $stmtPL->close();

    $plEbitda          = $plTotals['Revenue'] - $plTotals['CostOfServices'] - $plTotals['Administrative'] - $plTotals['Selling'] + $plTotals['OtherIncome'];
    $plOperatingProfit = $plEbitda - $plTotals['Depreciation'];
    $plPBT             = $plOperatingProfit - $plTotals['FinanceCost'];
    $currentYearEarnings = $plPBT - $plTotals['Taxation'];


    // ════════════════════════════════════════════════════════════════════════════
    // QUERY 2 — BALANCE SHEET LEDGER LIST
    // ════════════════════════════════════════════════════════════════════════════

    $ledgerTable = ($zerobal === 'Yes') ? 'ledger_table' : 'main_journal_table';

    $stmtLedger = $conn->prepare("
        SELECT DISTINCT ledger_name, ledger_number, ledger_sub_class, ledger_type
        FROM $ledgerTable
        WHERE
            (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'Intangible Assets')
         OR (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'Tangible Assets')
         OR (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'Depreciation, Amortization & Impairment')
         OR (ledger_sub_class = 'Non-Current Asset'     AND ledger_type = 'CWIP')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Service Customers')
         OR (ledger_sub_class = 'Contra Asset'          AND ledger_type = 'Allowances for Doubtful Debts')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Strategic Partners')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Agents')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Short Term Investments')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Bank Accounts')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Petty Cash')
         OR (ledger_sub_class = 'Current Asset'         AND ledger_type = 'Offshore Bank Accounts')
         OR (ledger_sub_class = 'Equity'                AND ledger_type = 'Capital')
         OR (ledger_sub_class = 'Equity'                AND ledger_type = 'Retained Earnings')
         OR (ledger_sub_class = 'Non-Current Liability' AND ledger_type = 'Deferred Tax Payable')
         OR (ledger_sub_class = 'Non-Current Liability' AND ledger_type = 'Loans and Similar Debts')
         OR (ledger_sub_class = 'Current Liability'     AND ledger_type = 'Suppliers / Creditors')
         OR (ledger_sub_class = 'Current Liability'     AND ledger_type = 'Payroll and Similar Accounts')
         OR (ledger_sub_class = 'Current Liability'     AND ledger_type = 'Outsourcing Agents')
         OR (ledger_sub_class = 'Taxation'              AND ledger_type != 'Income & Other Taxes')
        ORDER BY ledger_number ASC
    ");
    if (!$stmtLedger) throw new Exception("DB Error (ledger list): " . $conn->error);
    $stmtLedger->execute();

    $ledgerMap     = [];
    $govTaxLedgers = [];

    foreach ($stmtLedger->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $subClass = trim($row['ledger_sub_class']);
        $type     = trim($row['ledger_type']);
        $num      = trim($row['ledger_number']);
        $name     = trim($row['ledger_name']);

        if ($subClass === 'Taxation' && $type !== 'Income & Other Taxes') {
            $govTaxLedgers[] = ['ledger_number' => $num, 'ledger_name' => $name];
        } else {
            $ledgerMap[$subClass][$type][] = [
                'ledger_number' => $num,
                'ledger_name'   => $name,
            ];
        }
    }
    $stmtLedger->close();

    // ════════════════════════════════════════════════════════════════════════════
    // QUERY 3 — CUMULATIVE BALANCES up to year $period
    // ════════════════════════════════════════════════════════════════════════════

    $stmtBal = $conn->prepare("
        SELECT
            ledger_number, ledger_name,
            SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
            SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit
        FROM main_journal_table
        WHERE YEAR(journal_date) <= ?
        GROUP BY ledger_number
    ");
    if (!$stmtBal) throw new Exception("DB Error (balances): " . $conn->error);
    $stmtBal->bind_param("i", $period);
    $stmtBal->execute();

    $balanceMap = [];
    foreach ($stmtBal->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $balanceMap[trim($row['ledger_number'])] = [
            'total_debit'  => (float)$row['total_debit'],
            'total_credit' => (float)$row['total_credit'],
        ];
    }
    $stmtBal->close();


    // ════════════════════════════════════════════════════════════════════════════
    // BUILD REPORT & EXCEL
    // ════════════════════════════════════════════════════════════════════════════

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    // ── Style definitions ────────────────────────────────────────────────────────
    $greenColor = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FF00b196']],
    ];
    $blackBold = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FF000000']],
    ];
    $rowHeaderStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF00b196']],
    ];
    $grayFill = [
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFCFCFC']],
    ];
    $whiteFill = [
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFFFF']],
    ];
    $rightAlign = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]];
    $leftAlign  = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]];
    $titleStyle = ['font' => ['bold' => true, 'size' => 22]];

    $formatNumber = function (float $num): string {
        if ($num < 0) {
            return '(' . number_format(abs($num), 2) . ')';
        }
        return number_format($num, 2);
    };

    // ── Logo & Header ─────────────────────────────────────────────────────────────
    $logoPath = dirname(__DIR__, 3) . '/utils/images/az-logo.png';
    if (file_exists($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setDescription('Logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(30);
        $drawing->setWorksheet($sheet);
        $drawing->setCoordinates('A1');
    }

    $sheet->getStyle('A1:E2')->applyFromArray($whiteFill);
    $sheet->getRowDimension('1')->setRowHeight(30);
    $sheet->getStyle('A3:E10')->applyFromArray($grayFill);

    $sheet->setCellValue('A4', 'Balance Sheet');
    $sheet->getStyle('A4')->applyFromArray($titleStyle);

    $sheet->setCellValue('A6', 'Transaction Period');
    $sheet->getStyle('A6')->applyFromArray($greenColor);

    $sheet->setCellValue('A7', 'From');
    $sheet->setCellValue('B7', date('d M Y', strtotime($datefrom)));
    $sheet->getStyle('A7')->getFont()->setBold(true);
    $sheet->getStyle('B7')->applyFromArray($leftAlign);

    $sheet->setCellValue('A8', 'To');
    $sheet->setCellValue('B8', date('d M Y', strtotime($dateto)));
    $sheet->getStyle('A8')->getFont()->setBold(true);
    $sheet->getStyle('B8')->applyFromArray($leftAlign);

    $sheet->setCellValue('A10', 'Currency');
    $sheet->setCellValue('B10', $currency);
    $sheet->getStyle('A10')->getFont()->setBold(true);
    $sheet->getStyle('B10')->applyFromArray($leftAlign);

    // ── Table header (row 12) ────────────────────────────────────────────────────
    $sheet->setCellValue('A12', 'Ledger Name');
    $sheet->setCellValue('C12', 'Ledger Number');
    $sheet->setCellValue('E12', 'Total');
    $sheet->getStyle('A12:E12')->applyFromArray($rowHeaderStyle);
    $sheet->getRowDimension('12')->setRowHeight(25);

    // ── Column widths ─────────────────────────────────────────────────────────────
    $sheet->getColumnDimension('A')->setWidth(45);
    $sheet->getColumnDimension('B')->setWidth(5);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(5);
    $sheet->getColumnDimension('E')->setWidth(25);

    // ── Data Generation Logic ────────────────────────────────────────────────────
    
    $rowIndex = 13;
    $totals   = []; // Store totals for summary calculations

    // Helper to write section
    $writeSection = function($title, $subClass, $type, $flip = false, $indent = 2) use (
        &$sheet, &$rowIndex, &$totals, $ledgerMap, $balanceMap, $greenColor, $blackBold, $rightAlign, $formatNumber
    ) {
        // Section Title (Green)
        $sheet->setCellValue('A' . $rowIndex, str_repeat(' ', $indent) . $title);
        $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
        $sheet->getRowDimension($rowIndex)->setRowHeight(25);
        $rowIndex++;

        $sectionTotal = 0.0;
        $ledgers = $ledgerMap[$subClass][$type] ?? [];

        foreach ($ledgers as $ledger) {
            $num  = $ledger['ledger_number'];
            $name = $ledger['ledger_name'];

            $bal  = $balanceMap[$num] ?? ['total_debit' => 0.0, 'total_credit' => 0.0];
            $raw  = $bal['total_debit'] - $bal['total_credit'];
            
            // Special logic for Retained Earnings (ids 11000002, 11000003)
            if ($type === 'Retained Earnings' && ($num === '11000002' || $num === '11000003')) {
                 $secVal = $raw * -1;
            } else {
                 $secVal = $flip ? ($raw * -1) : $raw;
            }

            $sectionTotal += $secVal;

            $sheet->setCellValue('A' . $rowIndex, str_repeat(' ', $indent + 2) . $name);
            $sheet->setCellValue('C' . $rowIndex, $num);
            $sheet->setCellValue('E' . $rowIndex, $formatNumber($raw)); // Display raw balance or formatted?
            // The reference script shows format_number($rawBalance) for the line item
            // But the reference script for Allowance Doubtful Debts line item shows $debtAllowDr (raw), while total shows flipped.
            // Let's stick to showing raw balance for line items as per standard accounting reports usually do, 
            // but total row uses the calculated signed value.
            
            $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getRowDimension($rowIndex)->setRowHeight(25);
            $rowIndex++;
        }

        // Total Row (Black Bold)
        $sheet->setCellValue('A' . $rowIndex, str_repeat(' ', $indent) . 'Total ' . $title);
        $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
        $sheet->setCellValue('E' . $rowIndex, $formatNumber($sectionTotal));
        $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
        $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
        $sheet->getRowDimension($rowIndex)->setRowHeight(25);
        $rowIndex++;

        return $sectionTotal;
    };

    // ── ASSETS ───────────────────────────────────────────────────────────────────
    $sheet->setCellValue('A' . $rowIndex, 'Assets');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    // Non-Current Assets
    $sheet->setCellValue('A' . $rowIndex, '  Non - Current Assets');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totals['IntangibleAssets'] = $writeSection('Intangible Assets', 'Non-Current Asset', 'Intangible Assets', false, 4);
    $totals['TangibleAssets']   = $writeSection('Tangible Assets', 'Non-Current Asset', 'Tangible Assets', false, 4);
    $totals['Depreciation']     = $writeSection('Less: Depreciation, Amortization & Impairment of Non - Current Assets', 'Non-Current Asset', 'Depreciation, Amortization & Impairment', true, 4);
    $totals['CWIP']             = $writeSection('Non - Current Assets Work in Progress (CWIP)', 'Non-Current Asset', 'CWIP', false, 4);

    $totalNonCurrentAssets = $totals['IntangibleAssets'] + $totals['TangibleAssets'] + $totals['Depreciation'] + $totals['CWIP'];
    
    $sheet->setCellValue('A' . $rowIndex, '  Total for Non - Current Assets');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalNonCurrentAssets));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;
    
    $sheet->getRowDimension($rowIndex)->setRowHeight(10); // Spacer
    $rowIndex++;

    // Current Assets
    $sheet->setCellValue('A' . $rowIndex, '  Current Assets');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totals['ServiceCustomers'] = $writeSection('Service Customers', 'Current Asset', 'Service Customers', false, 4);
    $totals['AllowanceDoubtful'] = $writeSection('Less: Allowances for Doubtful Debts', 'Contra Asset', 'Allowances for Doubtful Debts', true, 4);
    
    $netServiceCustomers = $totals['ServiceCustomers'] + $totals['AllowanceDoubtful'];
    
    $sheet->setCellValue('A' . $rowIndex, '  Service Customers - (Net)');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($netServiceCustomers));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totals['StrategicPartners']    = $writeSection('Strategic Partners', 'Current Asset', 'Strategic Partners', false, 4);
    $totals['Agents']               = $writeSection('Agents', 'Current Asset', 'Agents', false, 4);
    
    // Treasury Accounts Header (Short Term Investments)
    $sheet->setCellValue('A' . $rowIndex, '    Treasury Accounts');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totals['ShortTermInv']         = $writeSection('Short Term Investments', 'Current Asset', 'Short Term Investments', false, 4);
    $totals['BankAccounts']         = $writeSection('Bank Accounts', 'Current Asset', 'Bank Accounts', false, 4);
    $totals['PettyCash']            = $writeSection('Petty Cash', 'Current Asset', 'Petty Cash', false, 4);
    $totals['OffshoreBankAccounts'] = $writeSection('Offshore Bank Accounts', 'Current Asset', 'Offshore Bank Accounts', false, 4);

    $totalCurrentAssets = $netServiceCustomers + $totals['StrategicPartners'] + $totals['Agents'] + $totals['ShortTermInv'] + $totals['BankAccounts'] + $totals['PettyCash'] + $totals['OffshoreBankAccounts'];

    $sheet->setCellValue('A' . $rowIndex, '  Total Current Assets');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalCurrentAssets));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    // Total Assets
    $totalAssets = $totalNonCurrentAssets + $totalCurrentAssets;
    $sheet->setCellValue('A' . $rowIndex, 'Total Assets');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalAssets));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $sheet->getRowDimension($rowIndex)->setRowHeight(10); // Spacer
    $rowIndex++;

    // ── EQUITY ───────────────────────────────────────────────────────────────────
    $sheet->setCellValue('A' . $rowIndex, 'Equity');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totals['Capital'] = $writeSection('Capital', 'Equity', 'Capital', false, 4);
    $totals['RetainedEarnings'] = $writeSection('Retained Earnings', 'Equity', 'Retained Earnings', false, 4);
    
    // Current Year Earnings Row
    $sheet->setCellValue('A' . $rowIndex, '    Current Year Earnings');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($currentYearEarnings));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totalEquity = $totals['Capital'] + $totals['RetainedEarnings'] + $currentYearEarnings;

    $sheet->setCellValue('A' . $rowIndex, 'Total Equity');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalEquity));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $sheet->getRowDimension($rowIndex)->setRowHeight(10); // Spacer
    $rowIndex++;

    // ── LIABILITIES ──────────────────────────────────────────────────────────────
    $sheet->setCellValue('A' . $rowIndex, 'Liabilities');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    // Non-Current Liabilities
    $sheet->setCellValue('A' . $rowIndex, '  Non-Current Liabilities');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totals['DeferredTax'] = $writeSection('Deferred Tax Payable', 'Non-Current Liability', 'Deferred Tax Payable', false, 4);
    $totals['Loans']       = $writeSection('Loans and Similar Debts', 'Non-Current Liability', 'Loans and Similar Debts', false, 4);

    $totalNonCurrentLiability = $totals['DeferredTax'] + $totals['Loans'];

    $sheet->setCellValue('A' . $rowIndex, '  Total Non - Current Liabilities');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalNonCurrentLiability));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $sheet->getRowDimension($rowIndex)->setRowHeight(10); // Spacer
    $rowIndex++;

    // Current Liabilities
    $sheet->setCellValue('A' . $rowIndex, '  Current Liabilities');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totals['Suppliers']    = $writeSection('Suppliers / Creditors', 'Current Liability', 'Suppliers / Creditors', false, 4);
    $totals['Payroll']      = $writeSection('Payroll and Similar Accounts', 'Current Liability', 'Payroll and Similar Accounts', false, 4);
    $totals['Outsourcing']  = $writeSection('Outsourcing Agents', 'Current Liability', 'Outsourcing Agents', false, 4);
    
    // Government Tax Special Handling
    $sheet->setCellValue('A' . $rowIndex, '    Amounts Payable / Receivable to and from the Govt Agencies');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $govTaxTotal = 0.0;
    foreach ($govTaxLedgers as $ledger) {
        $num  = $ledger['ledger_number'];
        $name = $ledger['ledger_name'];
        $bal  = $balanceMap[$num] ?? ['total_debit' => 0.0, 'total_credit' => 0.0];
        $raw  = $bal['total_debit'] - $bal['total_credit'];
        $govTaxTotal += $raw; // Assuming no flip for gov tax in balance sheet logic based on config

        $sheet->setCellValue('A' . $rowIndex, '      ' . $name);
        $sheet->setCellValue('C' . $rowIndex, $num);
        $sheet->setCellValue('E' . $rowIndex, $formatNumber($raw));
        $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
        $sheet->getRowDimension($rowIndex)->setRowHeight(25);
        $rowIndex++;
    }
    $sheet->setCellValue('A' . $rowIndex, '    Total Amounts Payable / Receivable to and from the Govt Agencies');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($govTaxTotal));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $totalCurrentLiabilities = $totals['Suppliers'] + $totals['Payroll'] + $totals['Outsourcing'] + $govTaxTotal;

    $sheet->setCellValue('A' . $rowIndex, '  Total Current Liabilities');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalCurrentLiabilities));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $sheet->getRowDimension($rowIndex)->setRowHeight(10); // Spacer
    $rowIndex++;

    // Total Liabilities
    $totalLiabilities = $totalNonCurrentLiability + $totalCurrentLiabilities;

    $sheet->setCellValue('A' . $rowIndex, 'Total Liabilities');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalLiabilities));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);
    $rowIndex++;

    $sheet->getRowDimension($rowIndex)->setRowHeight(10); // Spacer
    $rowIndex++;

    // ── FINAL TOTAL ──────────────────────────────────────────────────────────────
    $totalEquityLiabilities = $totalEquity + $totalLiabilities;

    $sheet->setCellValue('A' . $rowIndex, 'Total Equity & Liabilities');
    $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($totalEquityLiabilities));
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);

    // ── Right-align column E throughout ──────────────────────────────────────────
    $sheet->getStyle('E2:E' . $rowIndex)->applyFromArray($rightAlign);

    // ── Output ───────────────────────────────────────────────────────────────────
    $writer = new Xlsx($spreadsheet);

    if (ob_get_length()) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Balance_Sheet_Report.xlsx"');
    header('Cache-Control: max-age=0');

    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    header('Content-Type: application/json');
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage(),
    ]);
}