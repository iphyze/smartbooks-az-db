<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/trialBalanceHelpers.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    if (!in_array($userData['integrity'], ['Admin', 'Controller'], true)) {
        throw new Exception('Unauthorized: Only Admins or Controllers can access this resource', 401);
    }

    foreach (['datefrom', 'dateto', 'currency', 'zerobal'] as $param) {
        if (!isset($_GET[$param]) || trim((string) $_GET[$param]) === '') {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim((string) $_GET['datefrom']);
    $dateto = trim((string) $_GET['dateto']);
    $currency = strtoupper(trim((string) $_GET['currency']));
    $zerobal = trim((string) $_GET['zerobal']);

    $allowedCurrencies = [
        'NGN' => 'ngn_rate',
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate',
    ];
    if (!isset($allowedCurrencies[$currency])) {
        throw new Exception('Invalid currency specified.', 400);
    }

    $fromDate = DateTime::createFromFormat('Y-m-d', $datefrom);
    $toDate = DateTime::createFromFormat('Y-m-d', $dateto);
    if (!$fromDate || $fromDate->format('Y-m-d') !== $datefrom || !$toDate || $toDate->format('Y-m-d') !== $dateto) {
        throw new Exception('Dates must use the YYYY-MM-DD format.', 400);
    }
    if ($datefrom > $dateto) {
        throw new Exception('Date From cannot be later than Date To.', 400);
    }

    $report = fetchTrialBalanceReport(
        $conn,
        $datefrom,
        $dateto,
        $allowedCurrencies[$currency],
        $zerobal
    );

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Trial Balance');
    $sheet->setShowGridlines(false);

    $brand = 'FF00B196';
    $brandDark = 'FF087F72';
    $navy = 'FF132238';
    $slate = 'FF546A7B';
    $softTeal = 'FFEAF8F5';
    $softBlue = 'FFEEF4FF';
    $softAmber = 'FFFFF7E6';
    $softGray = 'FFF7FAFC';
    $border = 'FFD8E4E1';
    $white = 'FFFFFFFF';
    $debitColor = 'FF2563EB';
    $creditColor = 'FFD97706';

    $thinBorder = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => $border],
            ],
        ],
    ];
    $moneyFormat = '#,##0.00;[Red](#,##0.00);-';

    // Header / branding area.
    $sheet->mergeCells('A1:I1');
    $sheet->getStyle('A1:I10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($white);
    $sheet->getRowDimension(1)->setRowHeight(34);

    $logoPath = dirname(__DIR__, 3) . '/utils/images/az-logo.png';
    if (file_exists($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('A-Z Consultancy Logo');
        $drawing->setDescription('A-Z Consultancy Logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(30);
        $drawing->setCoordinates('G2');
        $drawing->setOffsetX(20);
        $drawing->setWorksheet($sheet);
    }

    $sheet->mergeCells('A3:E3');
    $sheet->setCellValue('A3', 'Trial Balance');
    $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(20)->getColor()->setARGB($navy);

    $sheet->mergeCells('A4:E4');
    $sheet->setCellValue('A4', 'Opening balances, period movement and closing balances');
    $sheet->getStyle('A4')->getFont()->setSize(10)->getColor()->setARGB($slate);

    $sheet->setCellValue('A6', 'Period From');
    $sheet->setCellValue('B6', date('d M Y', strtotime($datefrom)));
    $sheet->setCellValue('D6', 'Period To');
    $sheet->setCellValue('E6', date('d M Y', strtotime($dateto)));
    $sheet->setCellValue('G6', 'Currency');
    $sheet->setCellValue('H6', $currency);
    $sheet->setCellValue('A8', 'Zero balances');
    $sheet->setCellValue('B8', strcasecmp($zerobal, 'Yes') === 0 ? 'Included' : 'Excluded');
    $sheet->setCellValue('D8', 'Generated');
    $sheet->setCellValue('E8', date('d M Y H:i'));

    foreach (['A6', 'D6', 'G6', 'A8', 'D8'] as $cell) {
        $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB($brandDark);
    }
    foreach (['B6', 'E6', 'H6', 'B8', 'E8'] as $cell) {
        $sheet->getStyle($cell)->getFont()->setSize(9)->getColor()->setARGB($navy);
    }

    // Two-tier grouped table header.
    $headerTopRow = 11;
    $headerSubRow = 12;
    $sheet->mergeCells("A{$headerTopRow}:A{$headerSubRow}");
    $sheet->mergeCells("B{$headerTopRow}:B{$headerSubRow}");
    $sheet->mergeCells("C{$headerTopRow}:C{$headerSubRow}");
    $sheet->mergeCells("D{$headerTopRow}:E{$headerTopRow}");
    $sheet->mergeCells("F{$headerTopRow}:G{$headerTopRow}");
    $sheet->mergeCells("H{$headerTopRow}:I{$headerTopRow}");

    $sheet->setCellValue("A{$headerTopRow}", '#');
    $sheet->setCellValue("B{$headerTopRow}", 'Ledger Number');
    $sheet->setCellValue("C{$headerTopRow}", 'Ledger Name');
    $sheet->setCellValue("D{$headerTopRow}", 'Opening Balance');
    $sheet->setCellValue("F{$headerTopRow}", 'Period Movement');
    $sheet->setCellValue("H{$headerTopRow}", 'Closing Balance');
    $sheet->fromArray(['Debit', 'Credit', 'Debit', 'Credit', 'Debit', 'Credit'], null, "D{$headerSubRow}");

    $sheet->getStyle("A{$headerTopRow}:I{$headerSubRow}")->applyFromArray($thinBorder);
    $sheet->getStyle("A{$headerTopRow}:I{$headerTopRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($navy);
    $sheet->getStyle("A{$headerTopRow}:I{$headerTopRow}")->getFont()->setBold(true)->setSize(9)->getColor()->setARGB($white);
    $sheet->getStyle("A{$headerSubRow}:I{$headerSubRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($brandDark);
    $sheet->getStyle("A{$headerSubRow}:I{$headerSubRow}")->getFont()->setBold(true)->setSize(8)->getColor()->setARGB($white);
    $sheet->getStyle("A{$headerTopRow}:I{$headerSubRow}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("C{$headerTopRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getRowDimension($headerTopRow)->setRowHeight(24);
    $sheet->getRowDimension($headerSubRow)->setRowHeight(20);

    $classLabels = [
        'Asset' => 'Assets',
        'Equity' => 'Equity',
        'Revenue' => 'Revenue',
        'Liability' => 'Liabilities',
        'Expense' => 'Expenses',
    ];

    $rowIndex = 13;
    $sequence = 1;

    foreach ($report['data'] as $class => $group) {
        $records = $group['records'] ?? [];
        if (!$records) {
            continue;
        }

        $classLabel = $classLabels[$class] ?? $class;
        $sheet->mergeCells("A{$rowIndex}:I{$rowIndex}");
        $sheet->setCellValue("A{$rowIndex}", $classLabel . '  -  ' . count($records) . ' ledger' . (count($records) === 1 ? '' : 's'));
        $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($softTeal);
        $sheet->getStyle("A{$rowIndex}")->getFont()->setBold(true)->setSize(10)->getColor()->setARGB($brandDark);
        $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($rowIndex)->setRowHeight(22);
        $rowIndex++;

        foreach ($records as $record) {
            $sheet->setCellValue("A{$rowIndex}", $sequence++);
            $sheet->setCellValueExplicit("B{$rowIndex}", (string) $record['ledger_number'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$rowIndex}", $record['ledger_name']);
            $sheet->fromArray([
                (float) $record['opening_debit'],
                (float) $record['opening_credit'],
                (float) $record['movement_debit'],
                (float) $record['movement_credit'],
                (float) $record['closing_debit'],
                (float) $record['closing_credit'],
            ], null, "D{$rowIndex}");

            $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->applyFromArray($thinBorder);
            $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($rowIndex % 2 === 0 ? $white : $softGray);
            $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getFont()->setSize(8.5)->getColor()->setARGB($navy);
            $sheet->getStyle("B{$rowIndex}")->getFont()->setBold(true)->getColor()->setARGB($brandDark);
            $sheet->getStyle("D{$rowIndex}:I{$rowIndex}")->getNumberFormat()->setFormatCode($moneyFormat);
            $sheet->getStyle("D{$rowIndex}:I{$rowIndex}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("A{$rowIndex}:B{$rowIndex}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $rowIndex++;
        }

        $sheet->mergeCells("A{$rowIndex}:C{$rowIndex}");
        $sheet->setCellValue("A{$rowIndex}", 'Total ' . $classLabel);
        $sheet->fromArray([
            (float) $group['sub_total_opening_debit'],
            (float) $group['sub_total_opening_credit'],
            (float) $group['sub_total_movement_debit'],
            (float) $group['sub_total_movement_credit'],
            (float) $group['sub_total_closing_debit'],
            (float) $group['sub_total_closing_credit'],
        ], null, "D{$rowIndex}");
        $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->applyFromArray($thinBorder);
        $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($softBlue);
        $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getFont()->setBold(true)->setSize(8.5)->getColor()->setARGB($navy);
        $sheet->getStyle("A{$rowIndex}")->getFont()->getColor()->setARGB($brandDark);
        $sheet->getStyle("A{$rowIndex}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("D{$rowIndex}:I{$rowIndex}")->getNumberFormat()->setFormatCode($moneyFormat);
        $sheet->getStyle("D{$rowIndex}:I{$rowIndex}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $rowIndex += 2;
    }

    $grandRow = $rowIndex;
    $sheet->mergeCells("A{$grandRow}:C{$grandRow}");
    $sheet->setCellValue("A{$grandRow}", 'Grand Total');
    $sheet->fromArray([
        (float) $report['totals']['grand_total_opening_debit'],
        (float) $report['totals']['grand_total_opening_credit'],
        (float) $report['totals']['grand_total_movement_debit'],
        (float) $report['totals']['grand_total_movement_credit'],
        (float) $report['totals']['grand_total_closing_debit'],
        (float) $report['totals']['grand_total_closing_credit'],
    ], null, "D{$grandRow}");
    $sheet->getStyle("A{$grandRow}:I{$grandRow}")->applyFromArray($thinBorder);
    $sheet->getStyle("A{$grandRow}:I{$grandRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($navy);
    $sheet->getStyle("A{$grandRow}:I{$grandRow}")->getFont()->setBold(true)->setSize(9)->getColor()->setARGB($white);
    $sheet->getStyle("A{$grandRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("D{$grandRow}:I{$grandRow}")->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle("D{$grandRow}:I{$grandRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getRowDimension($grandRow)->setRowHeight(23);

    $statusRow = $grandRow + 2;
    $isBalanced = abs((float) $report['totals']['grand_closing_difference']) < 0.01;
    $sheet->mergeCells("F{$statusRow}:I{$statusRow}");
    $sheet->setCellValue(
        "F{$statusRow}",
        $isBalanced
            ? 'Closing trial balance is balanced'
            : 'Closing difference: ' . number_format((float) $report['totals']['grand_closing_difference'], 2)
    );
    $sheet->getStyle("F{$statusRow}:I{$statusRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($isBalanced ? $softTeal : $softAmber);
    $sheet->getStyle("F{$statusRow}:I{$statusRow}")->getFont()->setBold(true)->setSize(9)->getColor()->setARGB($isBalanced ? $brandDark : $creditColor);
    $sheet->getStyle("F{$statusRow}:I{$statusRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Column sizing and print setup.
    $sheet->getColumnDimension('A')->setWidth(6);
    $sheet->getColumnDimension('B')->setWidth(17);
    $sheet->getColumnDimension('C')->setWidth(38);
    foreach (['D', 'E', 'F', 'G', 'H', 'I'] as $column) {
        $sheet->getColumnDimension($column)->setWidth(17);
    }

    $sheet->freezePane('D13');
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.35)->setRight(0.25)->setBottom(0.35)->setLeft(0.25);
    $sheet->getHeaderFooter()->setOddFooter('&LTrial Balance - ' . $currency . '&RPage &P of &N');

    $writer = new Xlsx($spreadsheet);
    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Trial_Balance_' . $currency . '_' . $datefrom . '_to_' . $dateto . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
} catch (Exception $e) {
    error_log('Trial Balance Excel Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($e),
    ]);
}
