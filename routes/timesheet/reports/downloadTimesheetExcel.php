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

function getRequiredQueryParam($key) {
    if (!isset($_GET[$key]) || trim($_GET[$key]) === '') {
        throw new Exception("Missing required parameter: '{$key}'.", 400);
    }
    return trim($_GET[$key]);
}

function validateDateParam($value, $label) {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new Exception("Invalid {$label}. Expected format: YYYY-MM-DD.", 400);
    }
}

function buildTimesheetConditions($datefrom, $dateto, $staff, $search, &$params, &$types) {
    $conditions = "WHERE date BETWEEN ? AND ?";
    $params = [$datefrom, $dateto];
    $types = "ss";

    if ($staff !== '' && $staff !== 'All Staff') {
        $conditions .= " AND staff_name = ?";
        $params[] = $staff;
        $types .= "s";
    }

    if ($search !== '') {
        $conditions .= " AND (staff_name LIKE ? OR clients_name LIKE ? OR project LIKE ? OR task LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= "ssss";
    }

    return $conditions;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];
    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    $datefrom = getRequiredQueryParam('datefrom');
    $dateto = getRequiredQueryParam('dateto');
    validateDateParam($datefrom, 'datefrom');
    validateDateParam($dateto, 'dateto');
    if (strtotime($datefrom) > strtotime($dateto)) {
        throw new Exception("Invalid date range: datefrom cannot be later than dateto.", 400);
    }

    $staff = isset($_GET['staff']) ? trim($_GET['staff']) : 'All Staff';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $params = [];
    $types = '';
    $conditions = buildTimesheetConditions($datefrom, $dateto, $staff, $search, $params, $types);

    $dataQuery = "
        SELECT
            staff_id,
            staff_name,
            date,
            clients_id,
            clients_name,
            project,
            task,
            start_time,
            finish_time,
            CAST(NULLIF(total_hours, '') AS DECIMAL(12,2)) AS total_hours
        FROM timesheet_table
        $conditions
        ORDER BY staff_name ASC, date ASC, start_time ASC, id ASC
    ";

    $stmt = $conn->prepare($dataQuery);
    if (!$stmt) {
        throw new Exception("Failed to prepare timesheet export query: " . $conn->error, 500);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Timesheet Report');

    $brand = 'FF00B196';
    $brandDark = 'FF009E87';
    $soft = 'FFF8FCFB';
    $border = 'FFDEEEE9';
    $text = 'FF0D1F1B';

    $titleStyle = [
        'font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => $text]],
    ];
    $metaLabelStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => $brand]],
    ];
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $brandDark]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];
    $subtotalStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => $brand]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $soft]],
    ];
    $allBorders = [
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $border]]],
    ];
    $rightAlign = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]];

    $logoPath = dirname(__DIR__, 3) . '/utils/images/az-logo.png';
    if (file_exists($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('Smartbooks Logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(34);
        $drawing->setCoordinates('A1');
        $drawing->setWorksheet($sheet);
    }

    $sheet->mergeCells('A1:I2');
    $sheet->mergeCells('A3:I3');
    $sheet->setCellValue('A3', 'Timesheet Report');
    $sheet->getStyle('A3')->applyFromArray($titleStyle);

    $fromFormatted = date('d M Y', strtotime($datefrom));
    $toFormatted = date('d M Y', strtotime($dateto));

    $sheet->setCellValue('A5', 'Period');
    $sheet->setCellValue('B5', $fromFormatted . ' to ' . $toFormatted);
    $sheet->setCellValue('A6', 'Staff');
    $sheet->setCellValue('B6', $staff);
    $sheet->setCellValue('A7', 'Search');
    $sheet->setCellValue('B7', $search !== '' ? $search : 'None');
    $sheet->setCellValue('A8', 'Generated');
    $sheet->setCellValue('B8', date('d M Y H:i'));
    $sheet->getStyle('A5:A8')->applyFromArray($metaLabelStyle);
    $sheet->getStyle('A5:I9')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($soft);

    $headers = ['Staff / Date', 'Client', 'Project', 'Task', 'Start', 'Finish', 'Hours', 'Client ID', 'Staff ID'];
    $headerRow = 11;
    $sheet->fromArray($headers, null, 'A' . $headerRow);
    $sheet->getStyle('A' . $headerRow . ':I' . $headerRow)->applyFromArray($headerStyle);
    $sheet->getStyle('A' . $headerRow . ':I' . $headerRow)->applyFromArray($allBorders);

    $rowIndex = $headerRow + 1;
    $currentStaff = null;
    $staffTotal = 0;
    $grandTotal = 0;
    $entryCount = 0;

    $writeSubtotal = function($sheet, $rowIndex, $totalHours) use ($subtotalStyle, $allBorders, $rightAlign) {
        $sheet->mergeCells('A' . $rowIndex . ':F' . $rowIndex);
        $sheet->setCellValue('A' . $rowIndex, 'Staff Total');
        $sheet->setCellValue('G' . $rowIndex, $totalHours);
        $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray($subtotalStyle);
        $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('G' . $rowIndex)->applyFromArray($rightAlign);
        $sheet->getStyle('G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');
        return $rowIndex + 1;
    };

    foreach ($rows as $row) {
        if ($currentStaff !== $row['staff_name']) {
            if ($currentStaff !== null) {
                $rowIndex = $writeSubtotal($sheet, $rowIndex, $staffTotal);
                $rowIndex++;
            }
            $currentStaff = $row['staff_name'];
            $staffTotal = 0;
            $sheet->mergeCells('A' . $rowIndex . ':I' . $rowIndex);
            $sheet->setCellValue('A' . $rowIndex, $currentStaff);
            $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray($subtotalStyle);
            $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray($allBorders);
            $rowIndex++;
        }

        $hours = (float) $row['total_hours'];
        $sheet->setCellValue('A' . $rowIndex, date('D, d M Y', strtotime($row['date'])));
        $sheet->setCellValue('B' . $rowIndex, $row['clients_name']);
        $sheet->setCellValue('C' . $rowIndex, $row['project']);
        $sheet->setCellValue('D' . $rowIndex, $row['task']);
        $sheet->setCellValue('E' . $rowIndex, $row['start_time']);
        $sheet->setCellValue('F' . $rowIndex, $row['finish_time']);
        $sheet->setCellValue('G' . $rowIndex, $hours);
        $sheet->setCellValue('H' . $rowIndex, $row['clients_id']);
        $sheet->setCellValue('I' . $rowIndex, $row['staff_id']);
        $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray($allBorders);
        $sheet->getStyle('G' . $rowIndex)->applyFromArray($rightAlign);
        $sheet->getStyle('G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');

        $staffTotal += $hours;
        $grandTotal += $hours;
        $entryCount++;
        $rowIndex++;
    }

    if ($currentStaff !== null) {
        $rowIndex = $writeSubtotal($sheet, $rowIndex, $staffTotal);
    }

    $rowIndex += 1;
    $sheet->mergeCells('A' . $rowIndex . ':F' . $rowIndex);
    $sheet->setCellValue('A' . $rowIndex, 'Grand Total Hours');
    $sheet->setCellValue('G' . $rowIndex, $grandTotal);
    $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $brand]],
    ]);
    $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray($allBorders);
    $sheet->getStyle('G' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');

    if ($entryCount === 0) {
        $sheet->mergeCells('A12:I12');
        $sheet->setCellValue('A12', 'No timesheet entries found for the selected filters.');
        $sheet->getStyle('A12')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    foreach (range('A', 'I') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
    $sheet->freezePane('A12');

    $writer = new Xlsx($spreadsheet);
    $filename = 'Timesheet_Report_' . $datefrom . '_to_' . $dateto . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    error_log('Timesheet Excel export error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage()
    ]);
}
