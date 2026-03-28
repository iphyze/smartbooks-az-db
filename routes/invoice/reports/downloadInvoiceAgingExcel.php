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

    // Validation
    if (!isset($_GET['currency']) || empty(trim($_GET['currency']))) {
        throw new Exception("Missing required parameter: 'currency'.", 400);
    }

    $currency = trim($_GET['currency']);

    /**
     * Fetch Main Data (Efficient Single Query)
     */
    $dataQuery = "
        SELECT 
            clients_name,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 0 AND 30 
                THEN invoice_amount ELSE 0 
            END) AS bucket_0_30,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 31 AND 60 
                THEN invoice_amount ELSE 0 
            END) AS bucket_31_60,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) BETWEEN 61 AND 90 
                THEN invoice_amount ELSE 0 
            END) AS bucket_61_90,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), invoice_date) > 90 
                THEN invoice_amount ELSE 0 
            END) AS bucket_91_plus,
            SUM(invoice_amount) AS total_outstanding
        FROM invoice_table 
        WHERE status = 'Pending' AND currency = ?
        GROUP BY clients_name
        ORDER BY clients_name ASC
    ";

    $stmt = $conn->prepare($dataQuery);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $currency);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate Totals
    $grandTotals = [
        'bucket_0_30' => 0,
        'bucket_31_60' => 0,
        'bucket_61_90' => 0,
        'bucket_91_plus' => 0,
        'total' => 0
    ];

    foreach ($data as $row) {
        $grandTotals['bucket_0_30'] += $row['bucket_0_30'];
        $grandTotals['bucket_31_60'] += $row['bucket_31_60'];
        $grandTotals['bucket_61_90'] += $row['bucket_61_90'];
        $grandTotals['bucket_91_plus'] += $row['bucket_91_plus'];
        $grandTotals['total'] += $row['total_outstanding'];
    }

    /**
     * Generate Excel File
     */
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // --- Define Styles (Matching Old File) ---
    
    $cellStyleArray = [
        'font' => ['size' => 10],
    ];

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
    // Assuming this script is in: root/api/invoice/reports/
    // We need to go up 3 levels to reach root, then into utils/images.
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

    // Row 1 & 2: Logo Area (White Background)
    $sheet->getStyle('A1:F2')->applyFromArray($logoBackground);
    $sheet->getRowDimension('1')->setRowHeight(30);

    // Row 3: Empty (Grey Background starts here visually in old code logic, usually row 3/4)
    $sheet->getStyle('A3:F9')->applyFromArray($grayFillStyleArray);

    // Row 4: Main Title "Invoice Aging Report"
    $sheet->setCellValue('A4', 'Invoice Aging Report');
    $sheet->getStyle('A4')->applyFromArray($titleStyleArray);

    // Row 5: Empty

    // Row 6: "Aging Period" Label
    $sheet->setCellValue('A6', 'Aging Period');
    $sheet->getStyle('A6')->applyFromArray($rowFontWeight); // Bold
    $sheet->getStyle('A6')->applyFromArray($greenColor);     // Green Text

    // Row 7: Currency Info
    $sheet->setCellValue('A7', 'Currency');
    $sheet->setCellValue('B7', $currency);
    $sheet->getStyle('A7')->applyFromArray($rowFontWeight); // Bold "Currency"
    $sheet->getStyle('B7')->applyFromArray($leftAlignStyleArray);

    // Row 8 & 9: Empty spacing before table

    // --- 3. Table Header (Row 10) ---
    $headers = ["Client's Name", "0-30 days", "31-60 days", "61-90 days", "91+ days", "Total"];
    $sheet->fromArray($headers, null, 'A10');
    
    // Apply Green Header Style to Row 10
    $sheet->getStyle('A10:F10')->applyFromArray($greenHeaderStyle);
    $sheet->getStyle('A10:F10')->applyFromArray($allBorders);

    // --- 4. Populate Data (Start Row 11) ---
    $rowIndex = 11;
    foreach ($data as $row) {
        $sheet->setCellValue('A' . $rowIndex, $row['clients_name']);
        
        // Write values
        $sheet->setCellValue('B' . $rowIndex, $row['bucket_0_30']);
        $sheet->setCellValue('C' . $rowIndex, $row['bucket_31_60']);
        $sheet->setCellValue('D' . $rowIndex, $row['bucket_61_90']);
        $sheet->setCellValue('E' . $rowIndex, $row['bucket_91_plus']);
        $sheet->setCellValue('F' . $rowIndex, $row['total_outstanding']);

        // Styling for data rows
        $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($cellStyleArray);
        
        // Number Format & Alignment
        $sheet->getStyle('B' . $rowIndex . ':F' . $rowIndex)
              ->getNumberFormat()
              ->setFormatCode('#,##0.00');
        
        $sheet->getStyle('B' . $rowIndex . ':F' . $rowIndex)
              ->applyFromArray($rightAlignStyleArray);

        $sheet->getRowDimension($rowIndex)->setRowHeight(30);
        $rowIndex++;
    }

    // --- 5. Totals Row ---
    $totalRowIndex = $rowIndex;
    
    $sheet->setCellValue('A' . $totalRowIndex, 'Total');
    $sheet->setCellValue('B' . $totalRowIndex, $grandTotals['bucket_0_30']);
    $sheet->setCellValue('C' . $totalRowIndex, $grandTotals['bucket_31_60']);
    $sheet->setCellValue('D' . $totalRowIndex, $grandTotals['bucket_61_90']);
    $sheet->setCellValue('E' . $totalRowIndex, $grandTotals['bucket_91_plus']);
    $sheet->setCellValue('F' . $totalRowIndex, $grandTotals['total']);

    // Styling Totals
    $sheet->getStyle('A' . $totalRowIndex . ':F' . $totalRowIndex)->applyFromArray($greenColor); // Green Bold Text
    $sheet->getStyle('A' . $totalRowIndex . ':F' . $totalRowIndex)->applyFromArray($rightAlignStyleArray);
    $sheet->getStyle('A' . $totalRowIndex . ':F' . $totalRowIndex)->applyFromArray($allBorders);
    
    $sheet->getStyle('B' . $totalRowIndex . ':F' . $totalRowIndex)
          ->getNumberFormat()
          ->setFormatCode('#,##0.00');

    $sheet->getRowDimension($totalRowIndex)->setRowHeight(30);

    // --- 6. Column Widths ---
    $sheet->getColumnDimension('A')->setWidth(30);
    foreach (range('B', 'F') as $col) {
        $sheet->getColumnDimension($col)->setWidth(18);
    }

    // --- 7. Output File ---
    $writer = new Xlsx($spreadsheet);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Invoice_Aging_Report_' . $currency . '.xlsx"');
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