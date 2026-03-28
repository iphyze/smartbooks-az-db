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
    $requiredParams = ['datefrom', 'dateto', 'currency'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim($_GET[$param]))) {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $currency = trim($_GET['currency']);

    /**
     * Whitelist currency to determine rate column — prevents SQL injection
     */
    $allowedCurrencies = ['NGN' => 'ngn_rate', 'USD' => 'usd_rate', 'EUR' => 'eur_rate', 'GBP' => 'gbp_rate'];
    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency specified.", 400);
    }
    $rateCol = $allowedCurrencies[$currency];

    /**
     * 1. Fetch Main Data using LEFT JOIN from ledger_table.
     *
     * KEY FIX: The grand totals are derived by summing the per-ledger rows in PHP,
     * NOT from a separate SQL query. This ensures the totals row always equals
     * the sum of the visible rows, even if some transactions reference ledgers
     * that don't exist in ledger_table (those are excluded from both rows AND totals).
     *
     * $rateCol is whitelisted above so it is safe to interpolate directly.
     */
    $dataQuery = "
        SELECT 
            l.ledger_name,
            l.ledger_number,
            COALESCE(SUM(m.debit_ngn  / NULLIF(m.$rateCol, 0)), 0) AS total_debit,
            COALESCE(SUM(m.credit_ngn / NULLIF(m.$rateCol, 0)), 0) AS total_credit,
            COALESCE(SUM((m.debit_ngn - m.credit_ngn) / NULLIF(m.$rateCol, 0)), 0) AS balance
        FROM ledger_table l
        LEFT JOIN main_journal_table m 
            ON l.ledger_name = m.ledger_name 
            AND m.journal_date BETWEEN ? AND ?
        GROUP BY l.ledger_name, l.ledger_number
        ORDER BY l.ledger_name ASC
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $dataStmt->bind_param("ss", $datefrom, $dateto);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    // Fetch all rows and accumulate grand totals from the result set itself
    $rows = [];
    $grandTotalDebit   = 0;
    $grandTotalCredit  = 0;
    $grandTotalBalance = 0;

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $grandTotalDebit   += (float)$row['total_debit'];
        $grandTotalCredit  += (float)$row['total_credit'];
        $grandTotalBalance += (float)$row['balance'];
    }

    $dataStmt->close();

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

    $sheet->setCellValue('A3', 'General Ledger');
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
    $headers = ['Ledger Name', 'Ledger Number', 'Debit', 'Credit', 'Balance'];
    $sheet->fromArray($headers, null, 'A12');
    $sheet->getStyle('A12:E12')->applyFromArray($greenHeaderStyle);
    $sheet->getStyle('A12:E12')->applyFromArray($allBorders);

    // --- 4. Totals Row FIRST (Row 13) — shown at the top like the screenshot ---
    $totalsRow = 13;

    $sheet->setCellValue('A' . $totalsRow, '');
    $sheet->setCellValue('B' . $totalsRow, 'Total');
    $sheet->setCellValue('C' . $totalsRow, $grandTotalDebit);
    $sheet->setCellValue('D' . $totalsRow, $grandTotalCredit);
    $sheet->setCellValue('E' . $totalsRow, $grandTotalBalance);

    $sheet->getStyle('A' . $totalsRow . ':E' . $totalsRow)->applyFromArray($greenColor);
    $sheet->getStyle('A' . $totalsRow . ':E' . $totalsRow)->applyFromArray($allBorders);
    $sheet->getStyle('C' . $totalsRow . ':E' . $totalsRow)->applyFromArray($rightAlignStyle);
    $sheet->getStyle('C' . $totalsRow . ':E' . $totalsRow)
          ->getNumberFormat()->setFormatCode('#,##0.00');

    // --- 5. Populate Ledger Rows (Start Row 14) ---
    $rowIndex = 14;

    foreach ($rows as $row) {
        $sheet->setCellValue('A' . $rowIndex, $row['ledger_name']);
        $sheet->setCellValue('B' . $rowIndex, $row['ledger_number']);
        $sheet->setCellValue('C' . $rowIndex, (float)$row['total_debit']);
        $sheet->setCellValue('D' . $rowIndex, (float)$row['total_credit']);
        $sheet->setCellValue('E' . $rowIndex, (float)$row['balance']);

        $sheet->getStyle('A' . $rowIndex . ':E' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('C' . $rowIndex . ':E' . $rowIndex)->applyFromArray($rightAlignStyle);
        $sheet->getStyle('C' . $rowIndex . ':E' . $rowIndex)
              ->getNumberFormat()->setFormatCode('#,##0.00');

        $rowIndex++;
    }

    // --- 6. Column Widths ---
    $sheet->getColumnDimension('A')->setWidth(30);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(18);
    $sheet->getColumnDimension('E')->setWidth(18);

    // --- 7. Output File ---
    $writer = new Xlsx($spreadsheet);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="General_Ledger_Report.xlsx"');
    header('Cache-Control: max-age=0');

    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);

    header('Content-Type: application/json');
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}