<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

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

function lineSource(array $line): string
{
    return strtolower((string)($line['_source'] ?? '')) === 'ledger' ? 'ledger' : 'bank';
}

function hasCategoryReference(array $line): bool
{
    return trim((string)($line['category_name'] ?? '')) !== ''
        || trim((string)($line['bank_only_type'] ?? '')) !== ''
        || trim((string)($line['recon_classification'] ?? '')) !== '';
}

function categoryNameForLine(array $line): string
{
    $cat = trim((string)($line['category_name'] ?? ''));
    if ($cat === '') $cat = trim((string)($line['bank_only_type'] ?? ''));
    if ($cat === '') $cat = trim((string)($line['recon_classification'] ?? ''));
    return $cat !== '' ? $cat : 'Other';
}

function activeReconCategoryLine(array $line): bool
{
    $status = (string)($line['match_status'] ?? '');
    return in_array($status, ['Classified', 'Bank-Only'], true) && trim((string)($line['recon_classification'] ?? '')) !== '';
}

function splitCategoryRows(array $items): array
{
    $active = [];
    $matched = [];
    $other = [];

    foreach ($items as $item) {
        $status = (string)($item['match_status'] ?? '');
        if (in_array($status, ['Classified', 'Bank-Only'], true)) {
            $active[] = $item;
        } elseif ($status === 'Matched') {
            $matched[] = $item;
        } else {
            $other[] = $item;
        }
    }

    return [$active, $matched, $other];
}

function sourceModeForLines(array $items, string $fallback = 'both'): string
{
    $hasBank = false;
    $hasLedger = false;

    foreach ($items as $item) {
        if (lineSource($item) === 'ledger') $hasLedger = true;
        else $hasBank = true;
    }

    if ($hasBank && $hasLedger) return 'both';
    if ($hasLedger) return 'ledger';
    if ($hasBank) return 'bank';
    return $fallback;
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

function writeBalanceStrip($sheet, int $startRow, array $recon, string $sourceMode = 'both', string $lastCol = 'F'): int
{
    $row = $startRow;
    $mode = strtolower($sourceMode ?: 'both');
    $writeBank = in_array($mode, ['bank', 'both', 'mixed'], true);
    $writeLedger = in_array($mode, ['ledger', 'both', 'mixed'], true);

    $rows = [];
    if ($writeBank) {
        $rows[] = ['Bank Opening Balance', (float)($recon['bank_opening'] ?? 0), 'Bank Closing Balance', (float)($recon['bank_closing'] ?? 0)];
    }
    if ($writeLedger) {
        $rows[] = ['Ledger Opening Balance', (float)($recon['ledger_opening'] ?? 0), 'Ledger Closing Balance', (float)($recon['ledger_closing'] ?? 0)];
    }

    foreach ($rows as $balanceRow) {
        [$leftLabel, $leftValue, $rightLabel, $rightValue] = $balanceRow;
        $sheet->setCellValue("A{$row}", $leftLabel);
        $sheet->setCellValue("B{$row}", $leftValue);
        $sheet->setCellValue("D{$row}", $rightLabel);
        $sheet->setCellValue("E{$row}", $rightValue);
        $sheet->getStyle("B{$row}:E{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bold' => true, 'bg' => 'FFF4FBF9', 'border' => true, 'color' => 'FF0F4C39']);
        $sheet->getRowDimension($row)->setRowHeight(21);
        $row++;
    }

    return $row;
}

function applyCompactReconLayout($sheet, int $lastRow): void
{
    // Reduce text from the 3rd column (C) onward by 1pt on the Recon sheet.
    // This keeps the left description area readable while making the value/right-side area fit better.
    $maxRow = max(1, $lastRow);
    for ($row = 1; $row <= $maxRow; $row++) {
        for ($col = 3; $col <= 6; $col++) {
            $cell = Coordinate::stringFromColumnIndex($col) . $row;
            $font = $sheet->getStyle($cell)->getFont();
            $size = $font->getSize();
            if ($size === null) {
                $size = 11;
            }
            $font->setSize(max(8, (float)$size - 1));
        }
    }

    // Compact margins for a cleaner printed/PDF export after reducing the right-side text.
    $sheet->getPageMargins()
        ->setTop(0.35)
        ->setRight(0.25)
        ->setLeft(0.25)
        ->setBottom(0.35)
        ->setHeader(0.15)
        ->setFooter(0.15);
    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
}

function writeStatementSheet($sheet, string $title, array $recon, array $lines, string $source): void
{
    // Bank columns:   Date | Description | Reference | Debit | Credit | Balance
    // Ledger columns: Date | Description | Reference | Debit | Credit | Balance
    $headers = ['Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance'];
    $lastCol = 'F';

    $bankName = trim((string)($recon['bank_name'] ?? '')) ?: 'N/A';
    $bankAccountNumber = trim((string)($recon['account_number'] ?? '')) ?: 'N/A';

    $sheet->setCellValue('A1', strtoupper($title));
    $sheet->setCellValue('A2', $recon['company_name']);
    $sheet->setCellValue('A3', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
    $sheet->setCellValue('A4', 'Bank Name: ' . $bankName);
    $sheet->setCellValue('A5', 'Bank Account Number: ' . $bankAccountNumber);

    styleRange($sheet, "A1:{$lastCol}1", ['bold' => true, 'size' => 14, 'color' => 'FF00B196']);
    styleRange($sheet, "A2:{$lastCol}5", ['bold' => true, 'bg' => 'FFE8F5F2', 'border' => true]);
    styleRange($sheet, "A4:{$lastCol}5", ['color' => 'FF0F4C39']);

    $nextRow = writeBalanceStrip($sheet, 6, $recon, strtolower($source) === 'ledger' ? 'ledger' : 'bank', $lastCol);
    $headerRow = $nextRow + 1;
    $dataStartRow = $headerRow + 1;

    writeHeaders($sheet, $headerRow, $headers);
    $row = $dataStartRow;
    if (!$lines) {
        $emptyText = strtolower($source) === 'ledger'
            ? 'No ledger transactions for this period. Heading-only ledger extract accepted for no-movement support.'
            : 'No bank transactions for this period. Heading-only bank statement accepted for no-movement support.';
        $sheet->setCellValue("A{$row}", $emptyText);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['italic' => true, 'color' => 'FF64748B', 'bg' => 'FFFFFFFF', 'border' => true, 'wrap' => true]);
        $sheet->getRowDimension($row)->setRowHeight(28);
        $row++;
    } else {
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
    }
    // Totals row
    $lastDataRow = $row - 1;
    $sheet->setCellValue("C{$row}", 'Totals');
    $sheet->setCellValue("D{$row}", $lastDataRow >= $dataStartRow ? '=SUM(D' . $dataStartRow . ':D' . $lastDataRow . ')' : 0);
    $sheet->setCellValue("E{$row}", $lastDataRow >= $dataStartRow ? '=SUM(E' . $dataStartRow . ':E' . $lastDataRow . ')' : 0);
    moneyFmt($sheet, "D{$dataStartRow}:F{$row}");
    styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);

    foreach (['A' => 13, 'B' => 62, 'C' => 20, 'D' => 18, 'E' => 18, 'F' => 20] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
    $sheet->freezePane('A' . $dataStartRow);
}

function writeCategoryLineSection($sheet, int $startRow, string $title, array $items, string $sourceMode, string $statusLabel, string $bgColor, string $emptyMessage): int
{
    $row = $startRow;
    $lastCol = 'I';

    $sheet->setCellValue("A{$row}", $title);
    $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
    styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bold' => true, 'color' => 'FFFFFFFF', 'bg' => $bgColor, 'border' => true]);
    $row++;

    $headers = ['Source', 'Date', 'Description', 'Reference', 'Debit', 'Credit', 'Match Status', 'Recon Effect', 'Note'];
    writeHeaders($sheet, $row, $headers, 'FF0F766E');
    $row++;
    $dataStartRow = $row;

    if (!$items) {
        $sheet->setCellValue("A{$row}", $emptyMessage);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['italic' => true, 'color' => 'FF64748B', 'border' => true, 'bg' => 'FFFFFFFF']);
        $row++;
    } else {
        foreach ($items as $i => $l) {
            $lineSource = lineSource($l);
            [$debit, $credit] = debitCreditForSource($lineSource, $l['direction'] ?? '', $l['amount'] ?? 0);
            $matchStatus = (string)($l['match_status'] ?? '');
            $effect = $statusLabel;
            if ($matchStatus === 'Matched') {
                $effect = 'Reference only — excluded from Recon totals';
            } elseif (!in_array($matchStatus, ['Classified', 'Bank-Only'], true)) {
                $effect = 'Reference only — no current Recon effect';
            }

            $values = [
                strtoupper($lineSource),
                fmtD($l['txn_date'] ?? ''),
                $l['description'] ?? '',
                $l['reference'] ?? '',
                $debit,
                $credit,
                $matchStatus,
                $effect,
                $l['journal_note'] ?? '',
            ];
            foreach ($values as $c => $v) $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $v);
            styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB', 'border' => true, 'wrap' => true]);
            $row++;
        }
    }

    $lastDataRow = $row - 1;
    $sheet->setCellValue("D{$row}", 'Section Total');
    $sheet->setCellValue("E{$row}", $lastDataRow >= $dataStartRow && $items ? '=SUM(E' . $dataStartRow . ':E' . $lastDataRow . ')' : 0);
    $sheet->setCellValue("F{$row}", $lastDataRow >= $dataStartRow && $items ? '=SUM(F' . $dataStartRow . ':F' . $lastDataRow . ')' : 0);
    moneyFmt($sheet, "E{$dataStartRow}:F{$row}");
    styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);

    return $row + 2;
}

function writeCategorySheet($sheet, string $category, array $items, array $recon): void
{
    // Category sheets now keep matched/posted items as reference attachments.
    // Only active Classified/Bank-Only rows affect the Recon/Details totals.
    $lastCol = 'I';
    $acctStr = trim(($recon['bank_name'] ?: '') . ' — A/C ' . ($recon['account_number'] ?: $recon['account_name'] ?: ''));
    [$activeItems, $matchedItems, $otherItems] = splitCategoryRows($items);
    $sourceMode = sourceModeForLines($items, 'both');

    $activeTotal = amountSum($activeItems);
    $matchedTotal = amountSum($matchedItems);
    $otherTotal = amountSum($otherItems);
    $sheetTotal = amountSum($items);

    $sheet->setCellValue('A1', $recon['company_name']);
    $sheet->setCellValue('A2', $category . ' — Category Extract');
    $sheet->setCellValue('A3', $acctStr);
    $sheet->setCellValue('A4', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
    styleRange($sheet, "A1:{$lastCol}1", ['bold' => true, 'size' => 13]);
    styleRange($sheet, "A2:{$lastCol}2", ['bold' => true, 'size' => 11, 'color' => 'FF00B196']);
    $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF0F4C39']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5F2']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF00B196']]],
    ]);

    $row = writeBalanceStrip($sheet, 5, $recon, $sourceMode, $lastCol) + 1;

    $sheet->setCellValue("A{$row}", 'Category Control Summary');
    $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
    styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bold' => true, 'color' => 'FFFFFFFF', 'bg' => 'FF0F4C39', 'border' => true]);
    $row++;

    $summaryRows = [
        ['Active outstanding total (affects Recon)', $activeTotal],
        ['Matched/posted reference total (excluded)', $matchedTotal],
        ['Other reference total (excluded)', $otherTotal],
        ['Sheet total for attachment reference', $sheetTotal],
    ];
    foreach ($summaryRows as $i => $sr) {
        [$label, $value] = $sr;
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $value);
        $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        styleRange($sheet, "A{$row}:{$lastCol}{$row}", ['bold' => $i === 0 || $i === 3, 'bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB', 'border' => true, 'color' => $i === 1 ? 'FF64748B' : 'FF0F4C39']);
        $row++;
    }
    $row++;

    $row = writeCategoryLineSection(
        $sheet,
        $row,
        'Outstanding Category Items — Included in Recon Totals',
        $activeItems,
        $sourceMode,
        'Included in Recon totals',
        'FF00B196',
        'No outstanding items remain in this category. Sheet retained for matched/reference support.'
    );

    $row = writeCategoryLineSection(
        $sheet,
        $row,
        'Matched / Posted Category Items — Reference Only',
        $matchedItems,
        $sourceMode,
        'Reference only — excluded from Recon totals',
        'FF64748B',
        'No matched reference items in this category yet.'
    );

    if ($otherItems) {
        writeCategoryLineSection(
            $sheet,
            $row,
            'Other Category Reference Items — No Current Recon Effect',
            $otherItems,
            $sourceMode,
            'Reference only — no current Recon effect',
            'FF94A3B8',
            'No other reference items in this category.'
        );
    }

    foreach (['A' => 12, 'B' => 14, 'C' => 58, 'D' => 24, 'E' => 18, 'F' => 18, 'G' => 18, 'H' => 34, 'I' => 34] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
    $sheet->freezePane('A13');
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
    $user = requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) brFail('id is required.');

    $recon = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$recon) brFail('Reconciliation not found.', 404);

    $bankLines = fetchAll($conn, "SELECT *, 'Bank' AS _source FROM bank_recon_bank_lines WHERE recon_id=$id ORDER BY txn_date,id");
    $ledgerLines = fetchAll($conn, "SELECT *, 'Ledger' AS _source FROM bank_recon_ledger_lines WHERE recon_id=$id ORDER BY txn_date,id");
    // Matches query removed (Matched Items sheet removed)

    $allLines = array_merge($bankLines, $ledgerLines);
    $noMovementPeriod = count($bankLines) === 0 && count($ledgerLines) === 0;

    // Active classified lines affect the Recon summary and Details totals.
    // Matched rows are deliberately excluded here, so categories reduce once items are posted/matched.
    $classified = array_values(array_filter($allLines, 'activeReconCategoryLine'));

    // Category sheets are wider attachment/reference schedules.
    // They include matched rows too, provided category/classification metadata exists.
    $categoryReferenceLines = array_values(array_filter($allLines, 'hasCategoryReference'));
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

    $adjustedLedger = round((float)$recon['ledger_closing'] - $theyDebitWeDontCredit + $theyCreditWeDontDebit, 2);
    $adjustedBank   = round((float)$recon['bank_closing'] + $weDebitTheyDontCredit - $weCreditTheyDontDebit, 2);

    // Treat tiny currency rounding differences as reconciled.
    // Example: ledger 14,661,757.83 vs bank 14,661,757.84 should not keep the workbook at -0.01
    // when every reconciling item has been cleared/matched.
    $rawDiff = round($adjustedLedger - $adjustedBank, 2);
    $roundingTolerance = 0.01;
    $diff = abs($rawDiff) <= $roundingTolerance ? 0.00 : $rawDiff;

    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('AccountLab')->setTitle('Bank Reconciliation ' . $recon['recon_number']);

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

    // Opening/closing balance strip at the top of the Recon workbook.
    writeBalanceStrip($s1, 5, $recon, 'both', 'F');
    $s1->getRowDimension(7)->setRowHeight(8);

    if ($noMovementPeriod) {
        $s1->mergeCells('A8:F8');
        $s1->setCellValue('A8', 'NO MOVEMENT PERIOD — no bank or ledger transaction lines were uploaded. Closing balances determine whether the period is reconciled.');
        $s1->getStyle('A8:F8')->applyFromArray([
            'font' => ['bold' => true, 'italic' => true, 'size' => 10, 'color' => ['argb' => 'FF0F4C39']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5F2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF00B196']]],
        ]);
        $s1->getRowDimension(8)->setRowHeight(28);
        $s1->getRowDimension(9)->setRowHeight(8);
    }

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
    $row = $noMovementPeriod ? 10 : 8;
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
    applyCompactReconLayout($s1, max($row, $diffRow + 8));
    // No spacer row between Currency and BALANCE PER LEDGER.

    // Sheet 2: Details
    $s2 = $ss->createSheet()->setTitle('Details');
    $s2->setCellValue('A1', 'Reconciling Items');
    $s2->setCellValue('A2', $noMovementPeriod ? 'No transaction lines were uploaded for this period. Details are intentionally empty.' : 'These sections feed the Recon summary and mirror the manual ZBN schedule.');
    styleRange($s2, 'A1:F1', ['bold' => true, 'size' => 14, 'color' => 'FF00B196']);
    styleRange($s2, 'A2:F2', ['italic' => true, 'color' => 'FF3D5752']);
    // No opening/closing balance strip on Details. Balances are kept on Recon, Bank, Ledger and category sheets only.
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

    // Category sheets from every categorized/classified line — including matched rows.
    // Reconciliation totals are reduced because the Details/Recon sections use only active rows above.
    $byCat = [];
    foreach ($categoryReferenceLines as $l) {
        $cat = categoryNameForLine($l);
        $byCat[$cat][] = $l;
    }
    ksort($byCat);

    $existingNames = $ss->getSheetNames();

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
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}