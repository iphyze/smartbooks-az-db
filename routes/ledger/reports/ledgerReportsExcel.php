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
use PhpOffice\PhpSpreadsheet\Style\Color;

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

    // Determine columns and title
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
     * 1. Fetch Distinct Ledgers (No pagination for full download)
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

    if (empty($ledgers)) {
        throw new Exception("No transactions found for the selected period.", 404);
    }

    /**
     * Generate Excel File
     */
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // --- Define Styles ---
    $titleStyleArray = [
        'font' => ['bold' => true, 'size' => 22],
    ];

    $greenHeaderStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => '00b196'],
        ],
    ];

    $grayFillStyleArray = [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FCFCFCFC'],
        ],
    ];

    $logoBackground = [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FFFFFF'],
        ],
    ];

    $greenColor = [
        'font' => [
            'color' => ['argb' => '00b196'],
            'bold' => true,
        ],
    ];

    $rowFontWeight = [
        'font' => ['bold' => true],
    ];

    $allBorders = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'C1C1C1'],
            ],
        ],
    ];

    $rightAlignStyleArray = [
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
        ],
    ];

    $leftAlignStyleArray = [
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
        ],
    ];

    // --- 1. Add Logo ---
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

    // --- 2. Layout Header Section ---
    $sheet->getStyle('A1:G2')->applyFromArray($logoBackground);
    $sheet->getStyle('A3:G12')->applyFromArray($grayFillStyleArray); // Background for header info

    // Title
    $sheet->setCellValue('A3', $reportTitle);
    $sheet->getStyle('A3')->applyFromArray($titleStyleArray);

    // Info Rows
    $sheet->setCellValue('A5', 'Transaction Period');
    $sheet->getStyle('A5')->applyFromArray($greenColor);

    $sheet->setCellValue('A6', 'From');
    $sheet->setCellValue('B6', $datefrom);
    $sheet->getStyle('A6')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B6')->applyFromArray($leftAlignStyleArray);

    $sheet->setCellValue('A7', 'To');
    $sheet->setCellValue('B7', $dateto);
    $sheet->getStyle('A7')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B7')->applyFromArray($leftAlignStyleArray);

    $sheet->setCellValue('A9', 'Transaction Ledger(s)');
    $sheet->getStyle('A9')->applyFromArray($greenColor);

    $sheet->setCellValue('A10', 'From');
    $sheet->setCellValue('B10', $fromledger);
    $sheet->getStyle('A10')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B10')->applyFromArray($leftAlignStyleArray);

    $sheet->setCellValue('A11', 'To');
    $sheet->setCellValue('B11', $toledger);
    $sheet->getStyle('A11')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B11')->applyFromArray($leftAlignStyleArray);

    // --- Set Column Widths ---
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(26);
    $sheet->getColumnDimension('C')->setWidth(16);
    $sheet->getColumnDimension('D')->setWidth(77);
    $sheet->getColumnDimension('E')->setWidth(20);
    $sheet->getColumnDimension('F')->setWidth(20);
    $sheet->getColumnDimension('G')->setWidth(20);

    // Start writing data from Row 13
    $rowIndex = 13;

    /**
     * 3. Loop through each ledger
     */
    foreach ($ledgers as $ledger) {
        $ledgerNumber = $ledger['ledger_number'];
        $ledgerName   = $ledger['ledger_name'];
        $ledgerCurrency = $ledger['journal_currency'];

        // A. Calculate Previous Balance
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

        // B. Ledger Header Block
        $sheet->setCellValue('A' . $rowIndex, 'Ledger Number');
        $sheet->setCellValue('B' . $rowIndex, 'Ledger Name');
        $sheet->setCellValue('C' . $rowIndex, 'Ledger Currency');
        $sheet->setCellValue('D' . $rowIndex, 'Previous Balance');
        $sheet->getStyle('A' . $rowIndex . ':D' . $rowIndex)->applyFromArray($rowFontWeight);
        
        $rowIndex++;
        
        $sheet->setCellValue('A' . $rowIndex, $ledgerNumber);
        $sheet->setCellValue('B' . $rowIndex, $ledgerName);
        $sheet->setCellValue('C' . $rowIndex, $ledgerCurrency);
        $sheet->setCellValue('D' . $rowIndex, $previousBalance);
        $sheet->getStyle('D' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('D' . $rowIndex)->applyFromArray($rightAlignStyleArray);

        $rowIndex += 2; // Add spacing

        // C. Table Header
        $headers = ['Date', 'Transaction Type', 'Transaction Ref', 'Description', 'Debit', 'Credit', 'Balance'];
        $sheet->fromArray($headers, null, 'A' . $rowIndex);
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($greenHeaderStyle);
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($allBorders);
        
        $rowIndex++;

        // D. Fetch Transactions
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

        $periodTotalDebit  = 0;
        $periodTotalCredit = 0;
        $runningBalance    = $previousBalance;

        foreach ($transactions as $trans) {
            $debitAmount  = $trans['debit'] ?? 0;
            $creditAmount = $trans['credit'] ?? 0;

            $periodTotalDebit  += $debitAmount;
            $periodTotalCredit += $creditAmount;
            $runningBalance    += ($debitAmount - $creditAmount);

            $sheet->setCellValue('A' . $rowIndex, $trans['journal_date']);
            $sheet->setCellValue('B' . $rowIndex, $trans['journal_type']);
            $sheet->setCellValue('C' . $rowIndex, $trans['journal_id']);
            $sheet->setCellValue('D' . $rowIndex, $trans['journal_description']);
            $sheet->setCellValue('E' . $rowIndex, $debitAmount);
            $sheet->setCellValue('F' . $rowIndex, $creditAmount);
            $sheet->setCellValue('G' . $rowIndex, $runningBalance);

            // Style Rows
            $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($allBorders);
            $sheet->getStyle('E' . $rowIndex . ':G' . $rowIndex)->applyFromArray($rightAlignStyleArray);
            $sheet->getStyle('E' . $rowIndex . ':G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');

            $rowIndex++;
        }

        // E. Totals Section
        $netMovement = $periodTotalDebit - $periodTotalCredit;
        $closingBalance = $previousBalance + $netMovement;

        // Row: Total Period
        $sheet->setCellValue('D' . $rowIndex, 'Total Period');
        $sheet->setCellValue('E' . $rowIndex, $periodTotalDebit);
        $sheet->setCellValue('F' . $rowIndex, $periodTotalCredit);
        $sheet->setCellValue('G' . $rowIndex, $runningBalance); // Use Running Balance which ends at closing for this section if following old logic, but let's stick to clean logic
        
        // Styling Total Period
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($greenColor);
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('E' . $rowIndex . ':G' . $rowIndex)->applyFromArray($rightAlignStyleArray);
        $sheet->getStyle('E' . $rowIndex . ':G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');
        $rowIndex++;

        // Row: Total (Closing)
        $sheet->setCellValue('D' . $rowIndex, 'Total');
        $sheet->setCellValue('E' . $rowIndex, $periodTotalDebit);
        $sheet->setCellValue('F' . $rowIndex, $periodTotalCredit);
        $sheet->setCellValue('G' . $rowIndex, $closingBalance);

        // Styling Total
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($greenColor);
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($rowFontWeight);
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('E' . $rowIndex . ':G' . $rowIndex)->applyFromArray($rightAlignStyleArray);
        $sheet->getStyle('E' . $rowIndex . ':G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');

        $rowIndex += 3; // Add spacing between ledgers
    }

    // --- Output File ---
    $writer = new Xlsx($spreadsheet);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Ledger_Statement_Report.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}