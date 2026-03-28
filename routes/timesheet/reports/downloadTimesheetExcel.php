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
     * Validation
     */
    if (!isset($_GET['datefrom']) || empty(trim($_GET['datefrom']))) {
        throw new Exception("Missing required parameter: 'datefrom'.", 400);
    }
    if (!isset($_GET['dateto']) || empty(trim($_GET['dateto']))) {
        throw new Exception("Missing required parameter: 'dateto'.", 400);
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $staff    = isset($_GET['staff']) ? trim($_GET['staff']) : 'All Staff';

    // Format dates for display
    $fromFormatted = date('d M Y', strtotime($datefrom));
    $toFormatted   = date('d M Y', strtotime($dateto));

    /**
     * Fetch Main Data
     * We fetch all relevant entries sorted by staff and date to process grouping in PHP.
     * This is more efficient than running queries inside a loop.
     */
    $dataQuery = "
        SELECT 
            staff_name,
            staff_id,
            date,
            clients_name,
            clients_id,
            project,
            task,
            start_time,
            finish_time,
            total_hours
        FROM timesheet_table 
        WHERE date BETWEEN ? AND ?
    ";

    $params = [$datefrom, $dateto];
    $types = "ss";

    // Specific staff filter
    if ($staff !== "All Staff") {
        $dataQuery .= " AND staff_name = ?";
        $params[] = $staff;
        $types .= "s";
    }

    $dataQuery .= " ORDER BY staff_name ASC, date ASC";

    $stmt = $conn->prepare($dataQuery);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rawData = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rawData)) {
        // Optionally return an empty Excel or an error. 
        // Following legacy logic, we can proceed to generate an empty template or return message.
        // Here we will generate the Excel with just headers/labels.
    }

    /**
     * Generate Excel File
     */
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // --- Define Styles ---

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
    // Adjusted path logic to match the provided modern file structure
    $logoPath = dirname(__DIR__, 3) . '/utils/images/az-logo.png';
    
    // Fallback to legacy path if modern path doesn't exist (optional robustness)
    if (!file_exists($logoPath)) {
         $logoPath = __DIR__ . '/logo.png';
    }

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

    // Row 3-10: Metadata Area (Grey Background)
    $sheet->getStyle('A3:F10')->applyFromArray($grayFillStyleArray);

    // Row 4: Main Title
    $sheet->setCellValue('A4', 'Timesheet Management');
    $sheet->getStyle('A4')->applyFromArray($titleStyleArray);

    // Row 6: "Timesheet Period" Label
    $sheet->setCellValue('A6', 'Timesheet Period');
    $sheet->getStyle('A6')->applyFromArray($rowFontWeight);
    $sheet->getStyle('A6')->applyFromArray($greenColor);

    // Row 7: From
    $sheet->setCellValue('A7', 'From');
    $sheet->setCellValue('B7', $fromFormatted);
    $sheet->getStyle('A7')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B7')->applyFromArray($leftAlignStyleArray);

    // Row 8: To
    $sheet->setCellValue('A8', 'To');
    $sheet->setCellValue('B8', $toFormatted);
    $sheet->getStyle('A8')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B8')->applyFromArray($leftAlignStyleArray);

    // Row 9: Staff
    $sheet->setCellValue('A9', 'Staff');
    $sheet->setCellValue('B9', $staff);
    $sheet->getStyle('A9')->applyFromArray($rowFontWeight);
    $sheet->getStyle('B9')->applyFromArray($leftAlignStyleArray);

    // Row 10: Empty

    // --- 3. Table Header (Row 11) ---
    $headers = ['Name/Date', 'Clients Name', 'Task', 'Start Time', 'Finish Time', 'Total Hrs'];
    $sheet->fromArray($headers, null, 'A11');
    
    // Apply Green Header Style to Row 11
    $sheet->getStyle('A11:F11')->applyFromArray($greenHeaderStyle);
    $sheet->getStyle('A11:F11')->applyFromArray($allBorders);

    // --- 4. Populate Data (Start Row 12) ---
    $rowIndex = 12;
    
    // Variables to track grouping
    $currentStaff = null;
    $staffTotalHours = 0;
    $staffStartRow = 12;

    // Helper to write Staff Subtotal
    $writeSubtotal = function($sheet, $rowIndex, $totalHours) use ($greenColor, $rightAlignStyleArray, $allBorders) {
        $sheet->setCellValue('E' . $rowIndex, 'Total Hours');
        $sheet->setCellValue('F' . $rowIndex, number_format($totalHours, 2));
        
        // Apply styles
        $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($greenColor);
        $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($rightAlignStyleArray);
        $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($allBorders);
        
        return $rowIndex + 1;
    };

    foreach ($rawData as $row) {
        // Check if we are switching to a new staff member
        if ($currentStaff !== $row['staff_name']) {
            // If we have processed a previous staff member, write their subtotal first
            if ($currentStaff !== null) {
                $rowIndex = $writeSubtotal($sheet, $rowIndex, $staffTotalHours);
                $rowIndex++; // Add a small gap (optional, legacy logic implies separate blocks)
            }

            // Reset for new staff
            $currentStaff = $row['staff_name'];
            $staffTotalHours = 0;

            // Write Staff Name Header Row
            $sheet->setCellValue('A' . $rowIndex, $currentStaff);
            $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
            // Apply borders to the staff header row
            $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($allBorders);
            
            $rowIndex++;
        }

        // Write Entry Data
        $dateFormatted = date('D jS M, Y', strtotime($row['date']));
        
        $sheet->setCellValue('A' . $rowIndex, $dateFormatted);
        $sheet->setCellValue('B' . $rowIndex, $row['clients_name']);
        $sheet->setCellValue('C' . $rowIndex, $row['task']);
        $sheet->setCellValue('D' . $rowIndex, $row['start_time']);
        $sheet->setCellValue('E' . $rowIndex, $row['finish_time']);
        $sheet->setCellValue('F' . $rowIndex, number_format($row['total_hours'], 2));

        // Styling for data rows
        $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('A' . $rowIndex . ':F' . $rowIndex)->applyFromArray($cellStyleArray);
        
        // Right align numbers
        $sheet->getStyle('F' . $rowIndex)->applyFromArray($rightAlignStyleArray);

        // Accumulate total
        $staffTotalHours += (float) $row['total_hours'];

        $rowIndex++;
    }

    // Write the subtotal for the very last staff member in the list
    if ($currentStaff !== null) {
        $rowIndex = $writeSubtotal($sheet, $rowIndex, $staffTotalHours);
    }

    // --- 5. Column Widths ---
    $sheet->getColumnDimension('A')->setWidth(30); // Name/Date
    $sheet->getColumnDimension('B')->setWidth(18); // Clients Name
    $sheet->getColumnDimension('C')->setWidth(18); // Task
    $sheet->getColumnDimension('D')->setWidth(15); // Start Time
    $sheet->getColumnDimension('E')->setWidth(15); // Finish Time
    $sheet->getColumnDimension('F')->setWidth(12); // Total Hrs

    // --- 6. Output File ---
    $writer = new Xlsx($spreadsheet);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Timesheet_Report.xlsx"');
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