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

header('Content-Type: application/json'); // Default header, changed later for Excel

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

    /**
     * Whitelist currency
     */
    $allowedCurrencies = ['NGN' => 'ngn_rate', 'USD' => 'usd_rate', 'EUR' => 'eur_rate', 'GBP' => 'gbp_rate'];
    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency specified.", 400);
    }
    $rateCol = $allowedCurrencies[$currency];

    /**
     * Sort Order Logic for Categories
     */
    $classSortOrder = "
        CASE ledger_class
            WHEN 'Asset'     THEN 1
            WHEN 'Equity'    THEN 2
            WHEN 'Revenue'   THEN 3
            WHEN 'Liability' THEN 4
            WHEN 'Expense'   THEN 5
            ELSE 6
        END
    ";

    // For JOINs, we need table aliases
    $classSortOrderAliased = "
        CASE l.ledger_class
            WHEN 'Asset'     THEN 1
            WHEN 'Equity'    THEN 2
            WHEN 'Revenue'   THEN 3
            WHEN 'Liability' THEN 4
            WHEN 'Expense'   THEN 5
            ELSE 6
        END
    ";

    /**
     * 1. Fetch Data
     */
    $rows = [];

    if ($zerobal === 'Yes') {
        // Fetch ALL ledgers (LEFT JOIN) - ensures ledgers with 0 balance are shown
        $dataQuery = "
            SELECT 
                l.ledger_name,
                l.ledger_number,
                l.ledger_class,
                COALESCE(SUM(m.debit_ngn  / NULLIF(m.$rateCol, 0)), 0) AS total_debit,
                COALESCE(SUM(m.credit_ngn / NULLIF(m.$rateCol, 0)), 0) AS total_credit
            FROM ledger_table l
            LEFT JOIN main_journal_table m 
                ON l.ledger_name = m.ledger_name 
                AND m.journal_date BETWEEN ? AND ?
            GROUP BY l.ledger_name, l.ledger_number, l.ledger_class
            ORDER BY $classSortOrderAliased ASC, l.ledger_number ASC
        ";
        
        $stmt = $conn->prepare($dataQuery);
        if (!$stmt) throw new Exception("DB Error: " . $conn->error);
        $stmt->bind_param("ss", $datefrom, $dateto);

    } else {
        // Fetch ONLY ledgers with transactions
        $dataQuery = "
            SELECT 
                ledger_name,
                ledger_number,
                ledger_class,
                SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
                SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit
            FROM main_journal_table
            WHERE journal_date BETWEEN ? AND ?
            GROUP BY ledger_name, ledger_number, ledger_class
            ORDER BY $classSortOrder ASC, ledger_number ASC
        ";

        $stmt = $conn->prepare($dataQuery);
        if (!$stmt) throw new Exception("DB Error: " . $conn->error);
        $stmt->bind_param("ss", $datefrom, $dateto);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    /**
     * Generate Excel File
     */
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // --- Define Styles ---
    $titleStyleArray = ['font' => ['bold' => true, 'size' => 22]];
    $greenHeaderStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF00b196']],
    ];
    $grayFillStyleArray = [
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFCFCFC']],
    ];
    $logoBackground = [
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFFFF']],
    ];
    $greenColor = [
        'font' => ['color' => ['argb' => 'FF00b196'], 'bold' => true],
    ];
    $rowFontWeight = ['font' => ['bold' => true]];
    $allBorders = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FFC1C1C1'],
            ],
        ],
    ];
    $rightAlignStyle = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]];
    $leftAlignStyle  = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]];

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

    // --- 2. Header Section ---
    $sheet->getStyle('A1:E2')->applyFromArray($logoBackground);
    $sheet->getRowDimension('1')->setRowHeight(30);
    $sheet->getStyle('A3:E10')->applyFromArray($grayFillStyleArray);

    $sheet->setCellValue('A3', 'Trial Balance');
    $sheet->getStyle('A3')->applyFromArray($titleStyleArray);

    $sheet->setCellValue('A5', 'Transaction Period');
    $sheet->getStyle('A5')->applyFromArray($greenColor);

    $sheet->setCellValue('A6', 'From');
    $sheet->setCellValue('B6', date('d M Y', strtotime($datefrom)));
    $sheet->getStyle('A6')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B6')->applyFromArray($leftAlignStyle);

    $sheet->setCellValue('A7', 'To');
    $sheet->setCellValue('B7', date('d M Y', strtotime($dateto)));
    $sheet->getStyle('A7')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B7')->applyFromArray($leftAlignStyle);

    $sheet->setCellValue('A9', 'Currency');
    $sheet->setCellValue('B9', $currency);
    $sheet->getStyle('A9')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B9')->applyFromArray($leftAlignStyle);

    // --- 3. Table Header (Row 12) ---
    $headers = ['Ledger Name', 'Ledger Number', 'Net Debit', 'Net Credit'];
    $sheet->fromArray($headers, null, 'A12');
    $sheet->getStyle('A12:D12')->applyFromArray($greenHeaderStyle);
    $sheet->getStyle('A12:D12')->applyFromArray($allBorders);

    // --- 4. Populate Data Rows (Grouped by Category) ---
    $rowIndex = 13;
    $currentClass = null;
    
    $grandTotalDebit = 0;
    $grandTotalCredit = 0;
    
    $subTotalDebit = 0;
    $subTotalCredit = 0;

    // Helper function to write subtotal rows
    $writeSubtotal = function($sheet, $rowIndex, $className, $debit, $credit) use ($greenColor, $allBorders, $rightAlignStyle) {
        $sheet->setCellValue('A' . $rowIndex, '');
        $sheet->setCellValue('B' . $rowIndex, 'Total ' . $className);
        $sheet->setCellValue('C' . $rowIndex, $debit);
        $sheet->setCellValue('D' . $rowIndex, $credit);
        
        $sheet->getStyle('A' . $rowIndex . ':D' . $rowIndex)->applyFromArray($greenColor);
        $sheet->getStyle('A' . $rowIndex . ':D' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('C' . $rowIndex . ':D' . $rowIndex)->applyFromArray($rightAlignStyle);
        $sheet->getStyle('C' . $rowIndex . ':D' . $rowIndex)
              ->getNumberFormat()->setFormatCode('#,##0.00');
    };

    foreach ($rows as $row) {
        $class = $row['ledger_class'];
        $debit = (float)$row['total_debit'];
        $credit = (float)$row['total_credit'];

        // Detect Category Change
        if ($currentClass !== $class) {
            // If not the first category, write subtotal for previous category
            if ($currentClass !== null) {
                $writeSubtotal($sheet, $rowIndex, $currentClass, $subTotalDebit, $subTotalCredit);
                $rowIndex++;
                
                // Add an empty row for spacing
                $rowIndex++;
            }

            // Reset Subtotals
            $currentClass = $class;
            $subTotalDebit = 0;
            $subTotalCredit = 0;

            // Write Category Header
            $sheet->setCellValue('A' . $rowIndex, $class . 's'); // e.g. "Assets"
            $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
            $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
            $rowIndex++;
        }

        // Write Data Row
        $sheet->setCellValue('A' . $rowIndex, $row['ledger_name']);
        $sheet->setCellValue('B' . $rowIndex, $row['ledger_number']);
        $sheet->setCellValue('C' . $rowIndex, $debit);
        $sheet->setCellValue('D' . $rowIndex, $credit);

        $sheet->getStyle('A' . $rowIndex . ':D' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('C' . $rowIndex . ':D' . $rowIndex)->applyFromArray($rightAlignStyle);
        $sheet->getStyle('C' . $rowIndex . ':D' . $rowIndex)
              ->getNumberFormat()->setFormatCode('#,##0.00');

        // Accumulate Totals
        $subTotalDebit  += $debit;
        $subTotalCredit += $credit;
        $grandTotalDebit  += $debit;
        $grandTotalCredit += $credit;

        $rowIndex++;
    }

    // Write Subtotal for the LAST category
    if ($currentClass !== null) {
        $writeSubtotal($sheet, $rowIndex, $currentClass, $subTotalDebit, $subTotalCredit);
        $rowIndex++;
    }

    // --- 5. Grand Totals ---
    $rowIndex++; // Extra space
    
    $sheet->setCellValue('A' . $rowIndex, '');
    $sheet->setCellValue('B' . $rowIndex, 'Grand Total');
    $sheet->setCellValue('C' . $rowIndex, $grandTotalDebit);
    $sheet->setCellValue('D' . $rowIndex, $grandTotalCredit);

    // Style Grand Total
    $sheet->getStyle('A' . $rowIndex . ':D' . $rowIndex)->applyFromArray($greenHeaderStyle);
    $sheet->getStyle('A' . $rowIndex . ':D' . $rowIndex)->applyFromArray($allBorders);
    $sheet->getStyle('C' . $rowIndex . ':D' . $rowIndex)->applyFromArray($rightAlignStyle);
    $sheet->getStyle('C' . $rowIndex . ':D' . $rowIndex)
          ->getNumberFormat()->setFormatCode('#,##0.00');

    // --- 6. Column Widths ---
    $sheet->getColumnDimension('A')->setWidth(30);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(18);

    // --- 7. Output File ---
    $writer = new Xlsx($spreadsheet);

    // Clear output buffer to prevent corrupt Excel file
    if (ob_get_length()) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Trial_Balance_Report.xlsx"');
    header('Cache-Control: max-age=0');

    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);

    // Ensure JSON error response if Excel headers haven't been sent yet
    header('Content-Type: application/json');
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}