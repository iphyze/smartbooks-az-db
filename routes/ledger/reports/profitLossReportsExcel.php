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

    $datefrom        = trim($_GET['datefrom']);
    $dateto          = trim($_GET['dateto']);
    $currency        = trim($_GET['currency']);
    $zerobal         = trim($_GET['zerobal']);

    /**
     * Whitelist currency
     */
    $allowedCurrencies = ['NGN' => 'ngn_rate', 'USD' => 'usd_rate', 'EUR' => 'eur_rate', 'GBP' => 'gbp_rate'];
    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency specified.", 400);
    }
    $rateCol = $allowedCurrencies[$currency];

    /**
     * Categories definition.
     *
     * 'flip' controls how the per-ledger balance feeds into the running section total:
     *
     *   false → total += (debit − credit)          [Revenue, COS, Other Income]
     *   true  → total += (debit − credit) × (−1)   [Admin, Selling, Depreciation,
     *                                                Finance Cost, Taxation]
     *
     * This exactly mirrors the legacy code:
     *   • Revenue    : $revenueBalance  = debit − credit          → $totalRevenue  += $revenueBalance
     *   • COS        : $cosBalance      = debit − credit          → $totalCos      += $cosBalance
     *   • Admin      : $adminBalance    = debit − credit          → $totalAdmin    += $adminBalance * (−1)
     *   • Selling    : $sellingBalance  = debit − credit          → $totalSelling  += $sellingBalance * (−1)
     *   • Other Inc  : $othIncBalance   = debit − credit          → $totalOthInc   += $othIncBalance
     *   • Depreciation: $depBalance     = debit − credit          → $totalDep      += $depBalance * (−1)
     *   • Finance    : $finBalance      = debit − credit          → $totalFin      += $finBalance * (−1)
     *   • Taxation   : $taxBalance      = debit − credit          → $totalTax      += $taxBalance * (−1)
     *
     * EBITDA = Rev − CoS − Admin − Selling + OtherIncome
     * Operating Profit = EBITDA − Depreciation
     * PBT  = Operating Profit − FinanceCost
     * PAT  = PBT − Taxation
     */
    $categories = [
        'Revenue' => [
            'title'     => 'Revenue',
            'sub_class' => 'Revenue',
            'type'      => 'Revenue',
            'flip'      => false,   // total += (debit − credit)
        ],
        'CostOfServices' => [
            'title'     => 'Cost of Services',
            'sub_class' => 'Cost of Services',
            'type'      => 'Cost of Services',
            'flip'      => false,   // total += (debit − credit)
        ],
        'Administrative' => [
            'title'     => 'Administrative Expenses',
            'sub_class' => 'Administrative Expenses',
            'type'      => 'Administrative Expenses',
            'flip'      => true,    // total += (debit − credit) × (−1)
        ],
        'Selling' => [
            'title'     => 'Selling Expenses',
            'sub_class' => 'Selling Expenses',
            'type'      => 'Selling Expenses',
            'flip'      => true,    // total += (debit − credit) × (−1)
        ],
        'OtherIncome' => [
            'title'     => 'Other Income',
            'sub_class' => 'Revenue',
            'type'      => 'Other Income',
            'flip'      => false,   // total += (debit − credit)
        ],
        'Depreciation' => [
            'title'     => 'Depreciation & Amortization',
            'sub_class' => 'Depreciation Expenses',
            'type'      => 'Depreciation, Amortization & Impairment (Expenses)',
            'flip'      => true,    // total += (debit − credit) × (−1)
        ],
        'FinanceCost' => [
            'title'     => 'Finance Cost',
            'sub_class' => 'Finance Cost',
            'type'      => 'Finance Cost',
            'flip'      => true,    // total += (debit − credit) × (−1)
        ],
        'Taxation' => [
            'title'     => 'Income & Other Taxes',
            'sub_class' => 'Taxation',
            'type'      => 'Income & Other Taxes',
            'flip'      => true,    // total += (debit − credit) × (−1)
        ],
    ];

    /**
     * Helper: format number with brackets for negatives (matches legacy format_number())
     */
    $formatNumber = function (float $num): string {
        if ($num < 0) {
            return '(' . number_format(abs($num), 2) . ')';
        }
        return number_format($num, 2);
    };

    /**
     * ── QUERY 1: Ledger list ─────────────────────────────────────────────────────
     *
     * Pull every ledger relevant to the P&L in a single query.
     * When zerobal=Yes  → ledger_table      (shows zero-balance accounts)
     * When zerobal=No   → main_journal_table (only accounts with transactions)
     *
     * Results are indexed as: $ledgerMap[$sub_class][$type][] = [name, number]
     */
    if ($zerobal === 'Yes') {
        $ledgerSQL = "
            SELECT ledger_name, ledger_number, ledger_sub_class, ledger_type
            FROM ledger_table
            WHERE (ledger_sub_class = 'Revenue'                  AND ledger_type = 'Revenue')
               OR (ledger_sub_class = 'Cost of Services'         AND ledger_type = 'Cost of Services')
               OR (ledger_sub_class = 'Administrative Expenses'  AND ledger_type = 'Administrative Expenses')
               OR (ledger_sub_class = 'Selling Expenses'         AND ledger_type = 'Selling Expenses')
               OR (ledger_sub_class = 'Revenue'                  AND ledger_type = 'Other Income')
               OR (ledger_sub_class = 'Depreciation Expenses'    AND ledger_type = 'Depreciation, Amortization & Impairment (Expenses)')
               OR (ledger_sub_class = 'Finance Cost'             AND ledger_type = 'Finance Cost')
               OR (ledger_sub_class = 'Taxation'                 AND ledger_type = 'Income & Other Taxes')
            ORDER BY ledger_number ASC
        ";
        $stmtLedger = $conn->prepare($ledgerSQL);
        if (!$stmtLedger) throw new Exception("DB Error (ledger list): " . $conn->error);
        $stmtLedger->execute();
    } else {
        $ledgerSQL = "
            SELECT DISTINCT ledger_name, ledger_number, ledger_sub_class, ledger_type
            FROM main_journal_table
            WHERE (ledger_sub_class = 'Revenue'                  AND ledger_type = 'Revenue')
               OR (ledger_sub_class = 'Cost of Services'         AND ledger_type = 'Cost of Services')
               OR (ledger_sub_class = 'Administrative Expenses'  AND ledger_type = 'Administrative Expenses')
               OR (ledger_sub_class = 'Selling Expenses'         AND ledger_type = 'Selling Expenses')
               OR (ledger_sub_class = 'Revenue'                  AND ledger_type = 'Other Income')
               OR (ledger_sub_class = 'Depreciation Expenses'    AND ledger_type = 'Depreciation, Amortization & Impairment (Expenses)')
               OR (ledger_sub_class = 'Finance Cost'             AND ledger_type = 'Finance Cost')
               OR (ledger_sub_class = 'Taxation'                 AND ledger_type = 'Income & Other Taxes')
            ORDER BY ledger_number ASC
        ";
        $stmtLedger = $conn->prepare($ledgerSQL);
        if (!$stmtLedger) throw new Exception("DB Error (ledger list): " . $conn->error);
        $stmtLedger->execute();
    }

    // Index ledgers by sub_class + type for O(1) lookup per category
    $ledgerMap = [];
    foreach ($stmtLedger->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $ledgerMap[$row['ledger_sub_class']][$row['ledger_type']][] = [
            'ledger_number' => $row['ledger_number'],
            'ledger_name'   => $row['ledger_name'],
        ];
    }
    $stmtLedger->close();

    /**
     * ── QUERY 2: All balances for the date range ─────────────────────────────────
     *
     * One aggregated query fetching debit/credit totals for every ledger account
     * within the requested period. The rate division happens here in SQL so PHP
     * only needs to do a simple subtraction per ledger.
     *
     * Results are indexed as: $balanceMap[$ledger_number] = [total_debit, total_credit]
     */
    $balanceSQL = "
        SELECT
            ledger_number,
            SUM(debit_ngn  / NULLIF($rateCol, 0)) AS total_debit,
            SUM(credit_ngn / NULLIF($rateCol, 0)) AS total_credit
        FROM main_journal_table
        WHERE journal_date BETWEEN ? AND ?
        GROUP BY ledger_number
    ";
    $stmtBal = $conn->prepare($balanceSQL);
    if (!$stmtBal) throw new Exception("DB Error (balances): " . $conn->error);
    $stmtBal->bind_param("ss", $datefrom, $dateto);
    $stmtBal->execute();

    $balanceMap = [];
    foreach ($stmtBal->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $balanceMap[$row['ledger_number']] = [
            'total_debit'  => (float)$row['total_debit'],
            'total_credit' => (float)$row['total_credit'],
        ];
    }
    $stmtBal->close();

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

    // ── Logo ─────────────────────────────────────────────────────────────────────
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

    // ── Header section (rows 1–10) ───────────────────────────────────────────────
    $sheet->getStyle('A1:E2')->applyFromArray($whiteFill);
    $sheet->getRowDimension('1')->setRowHeight(30);
    $sheet->getStyle('A3:E10')->applyFromArray($grayFill);

    $sheet->setCellValue('A4', 'Profit and Loss');
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
    $sheet->setCellValue('E12', 'Total');
    $sheet->getStyle('A12:E12')->applyFromArray($rowHeaderStyle);
    $sheet->getRowDimension('12')->setRowHeight(25);

    // ── Column widths ─────────────────────────────────────────────────────────────
    $sheet->getColumnDimension('A')->setWidth(45);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(18);
    $sheet->getColumnDimension('E')->setWidth(18);

    // ── Data rows ────────────────────────────────────────────────────────────────
    $rowIndex = 13;
    $totals   = array_fill_keys(array_keys($categories), 0.0);

    foreach ($categories as $key => $config) {

        // Blank spacer row before each section
        $sheet->getRowDimension($rowIndex)->setRowHeight(25);
        $rowIndex++;

        // Section header row (green bold)
        $sheet->setCellValue('A' . $rowIndex, $config['title']);
        $sheet->getStyle('A' . $rowIndex)->applyFromArray($greenColor);
        $sheet->getRowDimension($rowIndex)->setRowHeight(25);
        $rowIndex++;

        // Retrieve ledgers for this category from the pre-fetched hashmap (O(1) lookup)
        $ledgers = $ledgerMap[$config['sub_class']][$config['type']] ?? [];

        foreach ($ledgers as $ledger) {
            $ledgerNumber = $ledger['ledger_number'];
            $ledgerName   = $ledger['ledger_name'];

            // Look up pre-fetched balance — zero if ledger had no transactions in range
            $bal    = $balanceMap[$ledgerNumber] ?? ['total_debit' => 0.0, 'total_credit' => 0.0];
            $debit  = $bal['total_debit'];
            $credit = $bal['total_credit'];

            // Raw balance: debit − credit (matches legacy $xxxBalance = $debit − $credit)
            $rawBalance = $debit - $credit;

            // Accumulate section total, applying flip where the legacy code does × (−1)
            $totals[$key] += $config['flip'] ? ($rawBalance * -1) : $rawBalance;

            // Write ledger row
            $sheet->setCellValue('A' . $rowIndex, $ledgerNumber . ' - ' . $ledgerName);
            $sheet->setCellValue('E' . $rowIndex, $formatNumber($rawBalance));
            $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getRowDimension($rowIndex)->setRowHeight(25);
            $rowIndex++;
        }

        // Section total row (black bold)
        $sectionTotalLabel = match ($key) {
            'CostOfServices' => 'Total Cost of Services',
            'Administrative' => 'Total Administration Expenses',
            'Selling'        => 'Total Selling Expenses',
            'OtherIncome'    => 'Total Other Income',
            'Depreciation'   => 'Total Depreciation, Amortization & Impairment',
            'FinanceCost'    => 'Total Depreciation, Amortization & Impairment',  // kept as legacy label
            'Taxation'       => 'Total Depreciation, Amortization & Impairment',  // kept as legacy label
            default          => 'Total ' . $config['title'],
        };

        $sheet->setCellValue('A' . $rowIndex, $sectionTotalLabel);
        $sheet->getStyle('A' . $rowIndex)->applyFromArray($blackBold);
        $sheet->setCellValue('E' . $rowIndex, $formatNumber($totals[$key]));
        $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
        $sheet->getStyle('E' . $rowIndex)->getFont()->setBold(true);
        $sheet->getRowDimension($rowIndex)->setRowHeight(25);
        $rowIndex++;

        // Insert EBITDA marker after Other Income
        if ($key === 'OtherIncome') {
            $ebitda = $totals['Revenue']
                    - $totals['CostOfServices']
                    - $totals['Administrative']
                    - $totals['Selling']
                    + $totals['OtherIncome'];

            $sheet->setCellValue('C' . $rowIndex, '(EBITDA)');
            $sheet->setCellValue('E' . $rowIndex, $formatNumber($ebitda));
            $sheet->getStyle('A' . $rowIndex . ':E' . $rowIndex)->applyFromArray($greenColor);
            $sheet->getStyle('C' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getRowDimension($rowIndex)->setRowHeight(25);
            $rowIndex++;
        }

        // Insert Operating Profit marker after Depreciation
        if ($key === 'Depreciation') {
            $ebitda = $totals['Revenue']
                    - $totals['CostOfServices']
                    - $totals['Administrative']
                    - $totals['Selling']
                    + $totals['OtherIncome'];

            $operatingProfit = $ebitda - $totals['Depreciation'];

            $sheet->setCellValue('C' . $rowIndex, '(Operating Profit)');
            $sheet->setCellValue('E' . $rowIndex, $formatNumber($operatingProfit));
            $sheet->getStyle('A' . $rowIndex . ':E' . $rowIndex)->applyFromArray($greenColor);
            $sheet->getStyle('C' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getRowDimension($rowIndex)->setRowHeight(25);
            $rowIndex++;
        }

        // Insert PBT marker after Finance Cost
        if ($key === 'FinanceCost') {
            $ebitda = $totals['Revenue']
                    - $totals['CostOfServices']
                    - $totals['Administrative']
                    - $totals['Selling']
                    + $totals['OtherIncome'];

            $operatingProfit = $ebitda - $totals['Depreciation'];
            $pbt             = $operatingProfit - $totals['FinanceCost'];

            $sheet->setCellValue('C' . $rowIndex, '(Profit Before Tax)');
            $sheet->setCellValue('E' . $rowIndex, $formatNumber($pbt));
            $sheet->getStyle('A' . $rowIndex . ':E' . $rowIndex)->applyFromArray($greenColor);
            $sheet->getStyle('C' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
            $sheet->getRowDimension($rowIndex)->setRowHeight(25);
            $rowIndex++;
        }
    }

    // ── Final: Profit After Tax ───────────────────────────────────────────────────
    $ebitda          = $totals['Revenue']
                     - $totals['CostOfServices']
                     - $totals['Administrative']
                     - $totals['Selling']
                     + $totals['OtherIncome'];
    $operatingProfit = $ebitda - $totals['Depreciation'];
    $pbt             = $operatingProfit - $totals['FinanceCost'];
    $pat             = $pbt - $totals['Taxation'];

    $sheet->setCellValue('C' . $rowIndex, '(Profit After Tax)');
    $sheet->setCellValue('E' . $rowIndex, $formatNumber($pat));
    $sheet->getStyle('A' . $rowIndex . ':E' . $rowIndex)->applyFromArray($greenColor);
    $sheet->getStyle('A' . $rowIndex . ':E' . $rowIndex)->getFont()->setSize(12);
    $sheet->getStyle('C' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getStyle('E' . $rowIndex)->applyFromArray($rightAlign);
    $sheet->getRowDimension($rowIndex)->setRowHeight(25);

    // ── Right-align column E throughout ──────────────────────────────────────────
    $sheet->getStyle('E2:E' . $rowIndex)->applyFromArray($rightAlign);

    // ── Output ───────────────────────────────────────────────────────────────────
    $writer = new Xlsx($spreadsheet);

    if (ob_get_length()) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Profit_&_Loss_Report.xlsx"');
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