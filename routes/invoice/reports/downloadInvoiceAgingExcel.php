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
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'] ?? null;

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'], true)) {
        throw new Exception('Unauthorized: Only Admins or Controllers can access this resource', 401);
    }

    if (!isset($_GET['currency']) || empty(trim($_GET['currency']))) {
        throw new Exception("Missing required parameter: 'currency'.", 400);
    }

    $currency = strtoupper(trim($_GET['currency']));
    $allowedCurrencies = ['NGN', 'USD', 'EUR', 'GBP'];

    if (!in_array($currency, $allowedCurrencies, true)) {
        throw new Exception('Invalid currency supplied.', 400);
    }

    /**
     * Schema-specific receivables aging query.
     * Uses invoice_table.invoice_amount minus invoice_table.paid.
     * due_date/invoice_date are varchar in your schema, so invalid legacy values are safely ignored.
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

    $stmt = $conn->prepare($dataQuery);
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $conn->error, 500);
    }

    $stmt->bind_param('s', $currency);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $totals = [
        'bucket_0_30' => 0.0,
        'bucket_31_60' => 0.0,
        'bucket_61_90' => 0.0,
        'bucket_91_plus' => 0.0,
        'total' => 0.0,
        'invoice_count' => 0,
        'pending_count' => 0,
        'partially_paid_count' => 0,
        'overdue_count' => 0,
    ];

    foreach ($data as $row) {
        $totals['bucket_0_30'] += (float) $row['bucket_0_30'];
        $totals['bucket_31_60'] += (float) $row['bucket_31_60'];
        $totals['bucket_61_90'] += (float) $row['bucket_61_90'];
        $totals['bucket_91_plus'] += (float) $row['bucket_91_plus'];
        $totals['total'] += (float) $row['total_outstanding'];
        $totals['invoice_count'] += (int) $row['invoice_count'];
        $totals['pending_count'] += (int) $row['pending_count'];
        $totals['partially_paid_count'] += (int) $row['partially_paid_count'];
        $totals['overdue_count'] += (int) $row['overdue_count'];
    }

    $overdueExposure = $totals['bucket_31_60'] + $totals['bucket_61_90'] + $totals['bucket_91_plus'];
    $overduePercent = $totals['total'] > 0 ? round(($overdueExposure / $totals['total']) * 100, 2) : 0;
    $highRiskPercent = $totals['total'] > 0 ? round(($totals['bucket_91_plus'] / $totals['total']) * 100, 2) : 0;

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Invoice Aging');

    $brand = '00B196';
    $brandDark = '009E87';
    $border = 'DEEEE9';
    $gray = 'F8FCFB';
    $dark = '0D1F1B';
    $muted = '7AADA6';
    $watch = 'CA8A04';
    $concern = 'EA580C';
    $overdue = 'F47C7C';

    $logoPath = dirname(__DIR__, 3) . '/utils/images/az-logo.png';
    if (file_exists($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setDescription('Smartbooks Logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(36);
        $drawing->setWorksheet($sheet);
        $drawing->setCoordinates('A1');
    }

    $sheet->mergeCells('A1:I2');
    $sheet->mergeCells('A4:I4');
    $sheet->mergeCells('A5:I5');
    $sheet->setCellValue('A4', 'Invoice Aging Report');
    $sheet->setCellValue('A5', 'Outstanding receivables by client and days past due');

    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(22)->getColor()->setARGB($dark);
    $sheet->getStyle('A5')->getFont()->setSize(10)->getColor()->setARGB($muted);

    $sheet->setCellValue('A7', 'Currency');
    $sheet->setCellValue('B7', $currency);
    $sheet->setCellValue('D7', 'As of Date');
    $sheet->setCellValue('E7', date('Y-m-d'));
    $sheet->setCellValue('G7', 'Outstanding Basis');
    $sheet->setCellValue('H7', 'invoice_amount - paid');

    $sheet->getStyle('A7:H7')->getFont()->setBold(true);
    $sheet->getStyle('A7:H7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($gray);

    $sheet->fromArray([
        ['Metric', 'Value', '', 'Metric', 'Value', '', 'Metric', 'Value'],
        ['Clients Owing', count($data), '', 'Open Invoices', $totals['invoice_count'], '', 'Overdue Invoices', $totals['overdue_count']],
        ['Pending', $totals['pending_count'], '', 'Partially Paid', $totals['partially_paid_count'], '', 'Overdue Exposure %', $overduePercent . '%'],
        ['High Risk 91+', $totals['bucket_91_plus'], '', 'High Risk %', $highRiskPercent . '%', '', 'Excluded', 'Paid / Cancelled'],
    ], null, 'A9');

    $sheet->getStyle('A9:H12')->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $border]]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle('A9:H9')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A9:H9')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($brandDark);
    $sheet->getStyle('B12')->getNumberFormat()->setFormatCode('#,##0.00');

    $headers = [
        'Client Name',
        '0-30 Days',
        '31-60 Days',
        '61-90 Days',
        '91+ Days',
        'Total Outstanding',
        'Invoices',
        'Overdue Invoices',
        'Oldest Age Days',
    ];

    $headerRow = 15;
    $sheet->fromArray($headers, null, 'A' . $headerRow);
    $sheet->getStyle('A' . $headerRow . ':I' . $headerRow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $brand]],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $border]]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    $rowIndex = $headerRow + 1;
    foreach ($data as $row) {
        $sheet->setCellValue('A' . $rowIndex, html_entity_decode($row['clients_name']));
        $sheet->setCellValue('B' . $rowIndex, (float) $row['bucket_0_30']);
        $sheet->setCellValue('C' . $rowIndex, (float) $row['bucket_31_60']);
        $sheet->setCellValue('D' . $rowIndex, (float) $row['bucket_61_90']);
        $sheet->setCellValue('E' . $rowIndex, (float) $row['bucket_91_plus']);
        $sheet->setCellValue('F' . $rowIndex, (float) $row['total_outstanding']);
        $sheet->setCellValue('G' . $rowIndex, (int) $row['invoice_count']);
        $sheet->setCellValue('H' . $rowIndex, (int) $row['overdue_count']);
        $sheet->setCellValue('I' . $rowIndex, (int) $row['oldest_age_days']);

        $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $border]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        if ($rowIndex % 2 === 0) {
            $sheet->getStyle('A' . $rowIndex . ':I' . $rowIndex)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($gray);
        }

        $sheet->getStyle('B' . $rowIndex . ':F' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('B' . $rowIndex . ':I' . $rowIndex)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $rowIndex++;
    }

    $totalRow = $rowIndex;
    $sheet->setCellValue('A' . $totalRow, 'Grand Total');
    $sheet->setCellValue('B' . $totalRow, $totals['bucket_0_30']);
    $sheet->setCellValue('C' . $totalRow, $totals['bucket_31_60']);
    $sheet->setCellValue('D' . $totalRow, $totals['bucket_61_90']);
    $sheet->setCellValue('E' . $totalRow, $totals['bucket_91_plus']);
    $sheet->setCellValue('F' . $totalRow, $totals['total']);
    $sheet->setCellValue('G' . $totalRow, $totals['invoice_count']);
    $sheet->setCellValue('H' . $totalRow, $totals['overdue_count']);
    $sheet->setCellValue('I' . $totalRow, '');

    $sheet->getStyle('A' . $totalRow . ':I' . $totalRow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => $dark]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'EAFBF8']],
        'borders' => [
            'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $brand]],
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $border]],
        ],
    ]);

    $sheet->getStyle('B' . $totalRow . ':F' . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('B' . $totalRow . ':I' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    foreach (range('B', 'F') as $col) {
        $sheet->getStyle($col . ($headerRow + 1) . ':' . $col . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    $sheet->getStyle('C' . ($headerRow + 1) . ':C' . $totalRow)->getFont()->getColor()->setARGB($watch);
    $sheet->getStyle('D' . ($headerRow + 1) . ':D' . $totalRow)->getFont()->getColor()->setARGB($concern);
    $sheet->getStyle('E' . ($headerRow + 1) . ':E' . $totalRow)->getFont()->getColor()->setARGB($overdue);

    $sheet->getColumnDimension('A')->setWidth(36);
    foreach (range('B', 'F') as $col) {
        $sheet->getColumnDimension($col)->setWidth(18);
    }
    foreach (range('G', 'I') as $col) {
        $sheet->getColumnDimension($col)->setWidth(16);
    }

    $sheet->freezePane('A16');
    $sheet->setAutoFilter('A15:I' . max($totalRow - 1, $headerRow));

    $writer = new Xlsx($spreadsheet);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Invoice_Aging_Report_' . $currency . '_' . date('Ymd') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    error_log('Invoice Aging Excel Error: ' . $e->getMessage());

    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    http_response_code($code);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($e),
    ]);
}
