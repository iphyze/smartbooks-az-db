<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function brFail(string $m, int $c = 400): void
{
    throw new Exception($m, $c);
}
function cleanSheetName(string $name): string
{
    $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $name);
    $name = trim(preg_replace('/\\s+/', ' ', $name));

    // ensure not empty after cleaning
    if ($name === '') {
        $name = 'Category';
    }

    return substr($name, 0, 31);
}
function fmtD($d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '';
}
function moneyFmt($sheet, string $range): void
{
    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
}
function styleRange($sheet, string $range, array $o = []): void
{
    $s = [];
    if (!empty($o['bold'])) $s['font']['bold'] = true;
    if (!empty($o['size'])) $s['font']['size'] = $o['size'];
    if (!empty($o['color'])) $s['font']['color'] = ['argb' => $o['color']];
    if (!empty($o['italic'])) $s['font']['italic'] = true;
    if (!empty($o['bg'])) $s['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $o['bg']]];
    if (!empty($o['wrap'])) $s['alignment']['wrapText'] = true;
    if (!empty($o['align'])) $s['alignment']['horizontal'] = $o['align'];
    if (!empty($o['valign'])) $s['alignment']['vertical'] = $o['valign'];
    if (!empty($o['border'])) $s['borders']['allBorders'] = ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD8EAE6']];
    if ($s) $sheet->getStyle($range)->applyFromArray($s);
}
function fetchAll(mysqli $conn, string $sql): array
{
    $r = $conn->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}
function amountSum(array $rows): float
{
    return array_reduce($rows, fn($s, $r) => $s + (float)($r['amount'] ?? 0), 0.0);
}

/**
 * Convert the stored cash-flow direction into the correct accounting-side columns.
 * Stored direction is shared across both files:
 *   OUT = money paid out of the bank account
 *   IN  = money received into the bank account
 * Presentation differs by source:
 *   Bank:   OUT -> Debit,  IN -> Credit
 *   Ledger: OUT -> Credit, IN -> Debit
 */
function debitCreditForSource(string $source, ?string $direction, $amount): array
{
    $source = strtolower(trim($source));
    $isOut = strtoupper((string)$direction) === 'OUT';
    $value = (float)$amount;

    if ($source === 'ledger') {
        return $isOut ? [null, $value] : [$value, null];
    }

    return $isOut ? [$value, null] : [null, $value];
}
function writeHeaders($sheet, int $row, array $headers, string $bg = 'FF009E87'): void
{
    foreach ($headers as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $h);
    $last = Coordinate::stringFromColumnIndex(count($headers));
    styleRange($sheet, "A{$row}:{$last}{$row}", ['bold' => true, 'color' => 'FFFFFFFF', 'bg' => $bg, 'border' => true, 'align' => Alignment::HORIZONTAL_CENTER]);
}
function writeStatementSheet($sheet, string $title, array $recon, array $lines, string $source): void
{
    // Bank columns keep uploaded bank-side presentation: OUT -> Debit, IN -> Credit.
    // Ledger columns keep uploaded ledger-side presentation: OUT -> Credit, IN -> Debit.
    $headers = ['Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance'];
    $lastCol = 'F';

    $sheet->setCellValue('A1', strtoupper($title));
    $sheet->setCellValue('A2', $recon['company_name']);
    $sheet->setCellValue('A3', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
    styleRange($sheet, "A1:{$lastCol}1", ['bold' => true, 'size' => 14, 'color' => 'FF00B196']);
    styleRange($sheet, "A2:{$lastCol}3", ['bold' => true, 'bg' => 'FFE8F5F2', 'border' => true]);

    writeHeaders($sheet, 5, $headers);
    $row = 6;
    foreach ($lines as $i => $l) {
        [$debit, $credit] = debitCreditForSource($source, $l['direction'] ?? '', $l['amount'] ?? 0);
        $values = [
            fmtD($l['txn_date']),
            $l['description'],
            $l['reference'],
            $debit,
            $credit,
            (float)($l['running_balance'] ?? 0),
        ];
        foreach ($values as $c => $v) $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $v);
        styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB', 'border' => true, 'wrap' => true]);
        $row++;
    }
    // Totals row
    $sheet->setCellValue("C{$row}", 'Totals');
    $sheet->setCellValue("D{$row}", '=SUM(D6:D' . ($row - 1) . ')');
    $sheet->setCellValue("E{$row}", '=SUM(E6:E' . ($row - 1) . ')');
    moneyFmt($sheet, "D6:F{$row}");
    styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);

    foreach (['A' => 13, 'B' => 62, 'C' => 20, 'D' => 18, 'E' => 18, 'F' => 20] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
    $sheet->freezePane('A6');
}
function writeCategorySheet($sheet, string $category, array $items, array $recon): void
{
    // Columns: Source | Date | Description | Reference | Amount | Recon Classification | Dr Ledger | Cr Ledger | Note
    // (Direction removed — same columns as Bank/Ledger sheets)
    $acctStr = trim(($recon['bank_name'] ?: '') . ' — A/C ' . ($recon['account_number'] ?: $recon['account_name'] ?: ''));
    $sheet->setCellValue('A1', $recon['company_name']);
    $sheet->setCellValue('A2', $category . ' — Extract');
    $sheet->setCellValue('A3', $acctStr);
    $sheet->setCellValue('A4', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
    styleRange($sheet, 'A1:F1', ['bold' => true, 'size' => 13]);
    styleRange($sheet, 'A2:F2', ['bold' => true, 'size' => 11, 'color' => 'FF00B196']);
    $sheet->getStyle('A3:F3')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF0F4C39']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5F2']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF00B196']]],
    ]);
    $headers = [
        // 'Source', 
        'Date', 
        'Description', 
        'Reference', 
        'Debit', 
        'Credit', 
        // 'Recon Classification', 
        // 'Dr Ledger', 
        // 'Cr Ledger', 
        'Note'];
    writeHeaders($sheet, 5, $headers, 'FF0F766E');
    $row = 6;
    foreach ($items as $i => $l) {
        $lineSource = strtolower((string)($l['_source'] ?? 'Bank')) === 'ledger' ? 'ledger' : 'bank';
        [$debit, $credit] = debitCreditForSource($lineSource, $l['direction'] ?? '', $l['amount'] ?? 0);
        $values = [
            // $l['_source'],
            fmtD($l['txn_date']),
            $l['description'],
            $l['reference'],
            $debit,
            $credit,
            // $l['recon_classification'],
            // $l['suggested_dr_ledger'],
            // $l['suggested_cr_ledger'],
            $l['journal_note'],
        ];
        foreach ($values as $c => $v) $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $v);
        styleRange($sheet, "A{$row}:F{$row}", ['bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB', 'border' => true, 'wrap' => true]);
        $row++;
    }
    $sheet->setCellValue("C{$row}", 'Total');
    $sheet->setCellValue("D{$row}", '=SUM(D6:D' . ($row - 1) . ')');
    $sheet->setCellValue("E{$row}", '=SUM(E6:E' . ($row - 1) . ')');
    moneyFmt($sheet, "D6:E{$row}");
    styleRange($sheet, "A{$row}:F{$row}", ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);
    foreach (['A' => 13, 'B' => 58, 'C' => 30, 'D' => 30, 'E' => 30, 'F' => 30] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
    $sheet->freezePane('A6');
}

function writeDetailsBlock($sheet, int $start, string $heading, array $items, string $color): array
{
    $row = $start;

    // Section heading row
    $sheet->setCellValue("A{$row}", $heading);
    $sheet->mergeCells("A{$row}:C{$row}");
    styleRange($sheet, "A{$row}:C{$row}", ['bold' => true, 'color' => 'FFFFFFFF', 'bg' => $color, 'border' => true]);
    $row++;

    // Column headers: Category | Items | Amount
    $sheet->setCellValue("A{$row}", 'Category');
    $sheet->setCellValue("B{$row}", 'Items');
    $sheet->setCellValue("C{$row}", 'Amount');
    styleRange($sheet, "A{$row}:C{$row}", ['bold' => true, 'color' => 'FFFFFFFF', 'bg' => 'FF009E87', 'border' => true]);
    $row++;

    $firstData = $row;
    $grandTotal = 0.0;

    if (!$items) {
        $sheet->setCellValue("A{$row}", 'No items');
        $sheet->mergeCells("A{$row}:C{$row}");
        styleRange($sheet, "A{$row}:C{$row}", ['italic' => true, 'color' => 'FF7AADA6', 'border' => true]);
        $row++;
    } else {
        // Group items by category name
        $byCat = [];
        foreach ($items as $l) {
            $cat = trim($l['category_name'] ?: ($l['bank_only_type'] ?? 'Other')) ?: 'Other';
            $byCat[$cat][] = $l;
        }
        $i = 0;
        foreach ($byCat as $cat => $catItems) {
            $catTotal = array_reduce($catItems, fn($s, $r) => $s + (float)($r['amount'] ?? 0), 0.0);
            $grandTotal += $catTotal;
            $sheet->setCellValue("A{$row}", $cat);
            $sheet->setCellValue("B{$row}", count($catItems));
            $sheet->setCellValue("C{$row}", $catTotal);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            styleRange($sheet, "A{$row}:C{$row}", [
                'bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB',
                'border' => true,
            ]);
            $row++;
            $i++;
        }
    }

    // Grand total row
    $sheet->setCellValue("B{$row}", 'Total');
    $sheet->setCellValue("C{$row}", $grandTotal);
    $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
    styleRange($sheet, "A{$row}:C{$row}", ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);

    // Column widths for this section
    $sheet->getColumnDimension('A')->setWidth(40);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(36);

    return [$grandTotal, $row + 2];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) brFail('Unauthorized', 401);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) brFail('id is required.');

    $recon = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$recon) brFail('Reconciliation not found.', 404);

    $bankLines = fetchAll($conn, "SELECT *, 'Bank' AS _source FROM bank_recon_bank_lines WHERE recon_id=$id ORDER BY txn_date,id");
    $ledgerLines = fetchAll($conn, "SELECT *, 'Ledger' AS _source FROM bank_recon_ledger_lines WHERE recon_id=$id ORDER BY txn_date,id");
    // Matches query removed (Matched Items sheet removed)

    $classified = array_values(array_filter(array_merge($bankLines, $ledgerLines), fn($l) => in_array($l['match_status'], ['Classified', 'Bank-Only']) && !empty($l['recon_classification'])));
    $classes = [
        "We Debit They Don't Credit" => [],
        "They Debit We Don't Credit" => [],
        "We Credit They Don't Debit" => [],
        "They Credit We Don't Debit" => [],
        "Prior Period Item"           => [],   // pass-through — excluded from adjusted balances
    ];
    foreach ($classified as $line) if (isset($classes[$line['recon_classification']])) $classes[$line['recon_classification']][] = $line;

    // Only the four balance-affecting classes feed the adjusted balance formula
    $weDebitTheyDontCredit  = amountSum($classes["We Debit They Don't Credit"]);
    $theyDebitWeDontCredit = amountSum($classes["They Debit We Don't Credit"]);
    $weCreditTheyDontDebit = amountSum($classes["We Credit They Don't Debit"]);
    $theyCreditWeDontDebit = amountSum($classes["They Credit We Don't Debit"]);
    // "Prior Period Item" intentionally excluded from both sides

    $adjustedLedger = (float)$recon['ledger_closing'] - $theyDebitWeDontCredit + $theyCreditWeDontDebit;
    $adjustedBank   = (float)$recon['bank_closing'] + $weDebitTheyDontCredit - $weCreditTheyDontDebit;
    // $diff = round($adjustedBank - $adjustedLedger, 2);
    $diff = round($adjustedLedger - $adjustedBank, 2);

    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Smartbooks')->setTitle('Bank Reconciliation ' . $recon['recon_number']);

    // ══════════════════════════════════════════════════════════════════
    // Sheet 1: RECON — Professional modern summary
    // Layout uses cols A–F, rows 1–45
    // ══════════════════════════════════════════════════════════════════
    $s1 = $ss->getActiveSheet()->setTitle('Recon');

    // ── Top header band (rows 1–5) ────────────────────────────────────
    // Merge A1:F1 — dark teal banner with company name
    $s1->mergeCells('A1:F1');
    $s1->setCellValue('A1', strtoupper($recon['company_name']));
    $s1->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 17, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F4C39']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $s1->getRowDimension(1)->setRowHeight(32);

    $s1->mergeCells('A2:F2');
    $s1->setCellValue('A2', 'BANK RECONCILIATION STATEMENT');
    $s1->getStyle('A2')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF00B196']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $s1->getRowDimension(2)->setRowHeight(24);

    // Period + Account info bar
    $s1->mergeCells('A3:C3');
    $acctStr = trim(($recon['bank_name'] ?: '') . ' — A/C ' . ($recon['account_number'] ?: $recon['account_name'] ?: ''));
    $s1->setCellValue('A3', $acctStr);
    $s1->mergeCells('D3:F3');
    $monthYear = date('F Y', strtotime($recon['period_to']));
    $s1->setCellValue('D3', 'Period: ' . $monthYear);
    $s1->getStyle('A3:F3')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF0F4C39']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5F2']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF00B196']]],
    ]);
    $s1->getStyle('D3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $s1->getRowDimension(3)->setRowHeight(21);

    // Currency chip
    $s1->mergeCells('A4:F4');
    $s1->setCellValue('A4', 'Currency: ' . ($recon['currency'] ?: 'NGN'));
    $s1->getStyle('A4')->applyFromArray([
        'font'      => ['italic' => true, 'size' => 11, 'color' => ['argb' => 'FF64748B']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $s1->getRowDimension(4)->setRowHeight(22);

    // ── Helper: write a section block ────────────────────────────────
    // Returns next available row
    $writeSection = function(
        $sheet, int $startRow, string $sectionTitle,
        string $titleBg, string $titleFg,
        array $dataRows,  // each: ['label'=>string, 'value'=>mixed, 'bold'=>bool, 'indent'=>bool, 'color'=>string, 'bgOverride'=>string]
        bool $addSpacer = true
    ) use ($s1): int {
        $row = $startRow;

        // Section title bar
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("A{$row}", $sectionTitle);
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => $titleFg]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $titleBg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 0],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD8EAE6']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $row++;

        foreach ($dataRows as $dr) {
            $isBold   = !empty($dr['bold']);
            $isIndent = !empty($dr['indent']);
            $fgColor  = $dr['color'] ?? ($isBold ? 'FF0F4C39' : 'FF1F4E3A');
            $bgColor  = $dr['bgOverride'] ?? ($isBold ? 'FFE8F5F2' : 'FFFFFFFF');
            $label    = ($isIndent ? '    ' : '') . $dr['label'];

            $sheet->setCellValue("A{$row}", $label);
            if ($dr['value'] !== null) {
                $sheet->setCellValue("F{$row}", $dr['value']);
                $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                'font'      => ['bold' => $isBold, 'size' => 12, 'color' => ['argb' => $fgColor]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bgColor]],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFDDE9E5']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            if (!empty($dr['borderTop'])) {
                $sheet->getStyle("A{$row}:F{$row}")->getBorders()
                    ->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF00B196');
            }
            $sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(26);
            $row++;
        }

        if ($addSpacer) {
            $sheet->getRowDimension($row)->setRowHeight(8);
            $row++;
        }
        return $row;
    };

    // ── Ledger Section ────────────────────────────────────────────────
    $row = 5;
    $row = $writeSection($s1, $row, 'BALANCE PER LEDGER', 'FF0F4C39', 'FFFFFFFF', [
        ['label' => 'Balance Current Period',      'value' => (float)$recon['ledger_closing'],    'bold' => true, 'indent' => false],
        ['label' => "Add: They Debit We DON'T Credit", 'value' => $theyDebitWeDontCredit,         'bold' => false, 'indent' => false, 'color' => 'FFCA8A04'],
        ['label' => "Less: They Credit We DON'T Debit",'value' => $theyCreditWeDontDebit,         'bold' => false, 'indent' => false, 'color' => 'FF16A34A'],
        ['label' => 'Adjusted Ledger Balance',     'value' => $adjustedLedger,                    'bold' => true,  'bgOverride' => 'FFD4F0EA', 'borderTop' => true],
    ]);

    // ── Bank Section ──────────────────────────────────────────────────
    $row = $writeSection($s1, $row, 'BALANCE PER BANK', 'FF0F4C39', 'FFFFFFFF', [
        ['label' => 'Balance Current Period',       'value' => (float)$recon['bank_closing'],     'bold' => true, 'indent' => false],
        ['label' => "Add: We Debit They DON'T Credit",  'value' => $weDebitTheyDontCredit,        'bold' => false, 'indent' => false, 'color' => 'FFDC2626'],
        ['label' => "Less: We Credit They DON'T Debit", 'value' => $weCreditTheyDontDebit,        'bold' => false, 'indent' => false, 'color' => 'FF6366F1'],
        ['label' => 'Adjusted Bank Balance',        'value' => $adjustedBank,                     'bold' => true,  'bgOverride' => 'FFD4F0EA', 'borderTop' => true],
    ]);

    // ── Difference row ────────────────────────────────────────────────
    $diffOk  = abs($diff) <= 0.01;
    $diffRow = $row;
    $s1->mergeCells("A{$diffRow}:E{$diffRow}");
    $s1->setCellValue("A{$diffRow}", 'UNRECONCILED DIFFERENCE');
    $s1->setCellValue("F{$diffRow}", $diff);
    $s1->getStyle("F{$diffRow}")->getNumberFormat()->setFormatCode('#,##0.00');
    $s1->getStyle("A{$diffRow}:F{$diffRow}")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => $diffOk ? 'FF0F4C39' : 'FFDC2626']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $diffOk ? 'FFFEF3C7' : 'FFFEE2E2']],
        // 'borders'   => [
        //     'top'    => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $diffOk ? 'FF00B196' : 'FFDC2626']],
        //     'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $diffOk ? 'FF00B196' : 'FFDC2626']],
        // ],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $s1->getStyle("F{$diffRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $s1->getRowDimension($diffRow)->setRowHeight(27);
    $row++;

    // ── Prior Period memo ─────────────────────────────────────────────
    $priorTotal = amountSum($classes["Prior Period Item"]);
    if ($priorTotal > 0) {
        $row++;
        $s1->setCellValue("A{$row}", 'Prior Period Items (memo — no balance effect)');
        $s1->setCellValue("F{$row}", $priorTotal);
        $s1->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $s1->getStyle("A{$row}:F{$row}")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 11, 'color' => ['argb' => 'FF64748B']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD8EAE6']]],
        ]);
        $row++;
    }

    // ── Separator ─────────────────────────────────────────────────────
    $row += 2;

    // ── Signatories block ─────────────────────────────────────────────
    $sigRows = [
        ['Prepared by:', $recon['created_by'] ?? ''],
        ['Reviewed by:', $recon['updated_by'] ?? ''],
        ['Approved by:', ''],
    ];
    foreach ($sigRows as $sig) {
        $lbl = $sig[0];
        $val = $sig[1];
        $s1->setCellValue("A{$row}", $lbl);
        $s1->setCellValue("B{$row}", $val);
        $s1->setCellValue("D{$row}", 'DATE');
        $s1->setCellValue("E{$row}", $lbl === 'Prepared by:' ? date('d/m/Y') : '');
        $s1->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF64748B']]]);
        $s1->getStyle("B{$row}")->applyFromArray([
            'font'    => ['size' => 10],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF00B196']]],
        ]);
        $s1->getStyle("D{$row}:E{$row}")->applyFromArray(['font' => ['size' => 11, 'color' => ['argb' => 'FF64748B']]]);
        $s1->getRowDimension($row)->setRowHeight(24);
        $row += 2;
    }

    // ── Column widths ─────────────────────────────────────────────────
    foreach (['A' => 20, 'B' => 38, 'C' => 4, 'D' => 14, 'E' => 14, 'F' => 22] as $col => $w) {
        $s1->getColumnDimension($col)->setWidth($w);
    }
    // No spacer row between Currency and BALANCE PER LEDGER.

    // Sheet 2: Details
    $s2 = $ss->createSheet()->setTitle('Details');
    $s2->setCellValue('A1', 'Reconciling Items');
    $s2->setCellValue('A2', 'These sections feed the Recon summary and mirror the manual ZBN schedule.');
    styleRange($s2, 'A1:F1', ['bold' => true, 'size' => 14, 'color' => 'FF00B196']);
    styleRange($s2, 'A2:F2', ['italic' => true, 'color' => 'FF3D5752']);
    $row = 4;
    [$totalWDTDC, $row] = writeDetailsBlock($s2, $row, "We Debit They DON'T Credit", $classes["We Debit They Don't Credit"], 'FFDC2626');
    [$totalTDWDC, $row] = writeDetailsBlock($s2, $row, "They Debit We DON'T Credit", $classes["They Debit We Don't Credit"], 'FFCA8A04');
    [$totalWCTDD, $row] = writeDetailsBlock($s2, $row, "We Credit They DON'T Debit", $classes["We Credit They Don't Debit"], 'FF16A34A');
    [$totalTCWDD, $row] = writeDetailsBlock($s2, $row, "They Credit We DON'T Debit", $classes["They Credit We Don't Debit"], 'FF6366F1');
    // Prior Period Items — shown for transparency, not used in balance formula
    writeDetailsBlock($s2, $row, 'Prior Period Items (No Balance Effect)', $classes['Prior Period Item'], 'FF64748B');
    // Column widths set inside writeDetailsBlock — A=36, B=10, C=20

    // Sheet 3 and 4
    writeStatementSheet($ss->createSheet()->setTitle('Bank'), 'Bank Statement', $recon, $bankLines, 'bank');
    writeStatementSheet($ss->createSheet()->setTitle('Ledger'), 'Ledger Statement', $recon, $ledgerLines, 'ledger');

    // Category sheets from classified items — deduplicate sheet names
    $byCat = [];
    foreach ($classified as $l) {
        $cat = trim($l['category_name'] ?: ($l['bank_only_type'] ?? 'Other')) ?: 'Other';
        $byCat[$cat][] = $l;
    }
    ksort($byCat);

    $existingNames = [];

    foreach ($byCat as $cat => $items) {

        $baseName = cleanSheetName($cat);
        $sheetName = $baseName;
        $counter = 1;

        // Ensure uniqueness
        while (in_array($sheetName, $existingNames, true)) {
            $suffix = " ($counter)";
            $sheetName = substr($baseName, 0, 31 - strlen($suffix)) . $suffix;
            $counter++;
        }

        $existingNames[] = $sheetName;

        $sheet = $ss->createSheet();
        $sheet->setTitle($sheetName);

        writeCategorySheet($sheet, $cat, $items, $recon);
    }

    // Matched Items sheet removed per user request

    $ss->setActiveSheetIndex(0);
    $filename = 'BankRecon_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $recon['recon_number']) . '.xlsx';
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    $writer = new Xlsx($ss);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($e)]);
}