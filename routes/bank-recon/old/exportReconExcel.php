<?php
/**
 * GET /bank-recon/export-excel?id=X
 *
 * Produces an Excel workbook that mirrors the manual ZBN template structure:
 *
 *   Sheet 1 — Recon      (Summary balance formula)
 *   Sheet 2 — Details    (4 reconciling item categories)
 *   Sheet 3 — Bank       (Full bank statement)
 *   Sheet 4 — Ledger     (Full ledger statement)
 *   Sheet 5+ — Category  (One sheet per bank-only type: Bank Charges, Stamp Duty, etc.)
 */

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

function brFail(string $m, int $c = 400): void { throw new Exception($m, $c); }

// ── Style helpers ─────────────────────────────────────────────────────────────
$BRAND = 'FF00B196'; $BRAND_DARK = 'FF009E87';
$RED   = 'FFDC2626'; $GREEN = 'FF16A34A'; $AMBER = 'FFCA8A04';
$GRAY  = 'FFF8FCFB'; $WHITE = 'FFFFFFFF'; $TEXT1 = 'FF0D1F1B'; $TEXT2 = 'FF3D5752';

function cellStyle($ws, $range, $opts = []) {
    $style = [];
    if (!empty($opts['bold']))   $style['font']['bold'] = true;
    if (!empty($opts['size']))   $style['font']['size'] = $opts['size'];
    if (!empty($opts['color']))  $style['font']['color'] = ['argb' => $opts['color']];
    if (!empty($opts['italic'])) $style['font']['italic'] = true;
    if (!empty($opts['bg']))     $style['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $opts['bg']]];
    if (!empty($opts['halign'])) $style['alignment']['horizontal'] = $opts['halign'];
    if (!empty($opts['valign'])) $style['alignment']['vertical'] = $opts['valign'];
    if (!empty($opts['wrap']))   $style['alignment']['wrapText'] = true;
    if (!empty($opts['borders'])) {
        $bs = $opts['borders'];
        $style['borders']['allBorders'] = ['borderStyle' => $bs === 'thin' ? Border::BORDER_THIN : Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFD0EAE5']];
    }
    if ($style) $ws->getStyle($range)->applyFromArray($style);
}

function numFmt($ws, $range, $fmt = '#,##0.00') {
    $ws->getStyle($range)->getNumberFormat()->setFormatCode($fmt);
}

function fmtD($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }

// ── Write a category block to the Details sheet ───────────────────────────────
// Returns the next available row
function writeDetailsCategory($ws, int $startRow, string $heading, array $items, array $opts): int {
    global $BRAND, $BRAND_DARK, $GRAY, $WHITE, $TEXT1;
    $row = $startRow;

    // Section heading
    $ws->setCellValue("A$row", $heading);
    $ws->mergeCells("A$row:G$row");
    cellStyle($ws, "A$row:G$row", ['bold'=>true, 'size'=>10, 'color'=>'FFFFFFFF', 'bg'=>$opts['headBg']??$BRAND, 'halign'=>Alignment::HORIZONTAL_LEFT, 'valign'=>Alignment::VERTICAL_CENTER]);
    $ws->getRowDimension($row)->setRowHeight(20);
    $row++;

    // Sub-header
    foreach (['A'=>'Date','B'=>'Reference','C'=>'Description','D'=>'Amount','E'=>'Type','F'=>'Dr Ledger','G'=>'Remarks'] as $col=>$label) {
        $ws->setCellValue("$col$row", $label);
    }
    cellStyle($ws, "A$row:G$row", ['bold'=>true, 'size'=>8, 'color'=>'FFFFFFFF', 'bg'=>$BRAND_DARK, 'borders'=>'thin']);
    $ws->getRowDimension($row)->setRowHeight(16);
    $row++;

    if (empty($items)) {
        $ws->setCellValue("A$row", 'No items in this category');
        $ws->mergeCells("A$row:G$row");
        cellStyle($ws, "A$row:G$row", ['italic'=>true, 'color'=>'FF7AADA6']);
        $row++;
    } else {
        foreach ($items as $i => $l) {
            $ws->setCellValue("A$row", $l['txn_date'] ?? '');
            $ws->setCellValue("B$row", $l['reference'] ?? '');
            $ws->setCellValue("C$row", $l['description'] ?? '');
            $ws->setCellValue("D$row", (float)($l['amount'] ?? 0));
            $ws->setCellValue("E$row", $l['bank_only_type'] ?? $l['suggested_type'] ?? '');
            $ws->setCellValue("F$row", $l['suggested_dr_ledger'] ?? $l['ledger_name'] ?? '');
            $ws->setCellValue("G$row", $l['journal_note'] ?? '');
            $bg = $i % 2 === 0 ? $WHITE : $GRAY;
            cellStyle($ws, "A$row:G$row", ['bg'=>$bg, 'borders'=>'thin', 'size'=>8]);
            numFmt($ws, "D$row");
            $ws->getRowDimension($row)->setRowHeight(15);
            $row++;
        }
        // Total row
        $ws->setCellValue("C$row", 'Total');
        $firstDataRow = $startRow + 2;
        $lastDataRow  = $row - 1;
        $ws->setCellValue("D$row", "=SUM(D$firstDataRow:D$lastDataRow)");
        cellStyle($ws, "A$row:G$row", ['bold'=>true, 'color'=>'FF'.'009E87', 'bg'=>'FFE8F5F2', 'borders'=>'thin']);
        numFmt($ws, "D$row");
        $ws->getRowDimension($row)->setRowHeight(16);
        $row++;
    }
    $row++; // spacer
    return $row;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') brFail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) brFail('Unauthorized', 401);

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) brFail('id is required.');

    $recon = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$recon) brFail('Reconciliation not found.', 404);

    $bankLines   = $conn->query("SELECT * FROM bank_recon_bank_lines   WHERE recon_id=$id ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);
    $ledgerLines = $conn->query("SELECT * FROM bank_recon_ledger_lines WHERE recon_id=$id ORDER BY txn_date, id")->fetch_all(MYSQLI_ASSOC);

    // Classify lines
    $bankOnly    = array_values(array_filter($bankLines,   fn($l) => $l['match_status'] === 'Bank-Only'));
    $bankUnm     = array_values(array_filter($bankLines,   fn($l) => $l['match_status'] === 'Unmatched'));
    $ledgerUnm   = array_values(array_filter($ledgerLines, fn($l) => $l['match_status'] === 'Unmatched'));

    // Reconciling categories
    $weDrTheyNoCredit = $bankOnly;  // bank OUT unclassified (actually all bank-only OUT)
    $theyDrWeNoCredit = array_values(array_filter($ledgerUnm, fn($l) => $l['direction'] === 'OUT')); // ledger OUT, not in bank
    $weCrTheyNoDebit  = array_values(array_filter($ledgerUnm, fn($l) => $l['direction'] === 'OUT')); // same - outstanding payments
    $theyCrWeNoDebit  = array_values(array_filter($ledgerUnm, fn($l) => $l['direction'] === 'IN'));  // ledger IN, not in bank

    // Strictly matching the template's 4 categories:
    // 1. We Debit They DON'T Credit     = bank-only OUT (we paid, not in their books)
    // 2. They Debit We DON'T Credit     = ledger OUT not yet in bank (timing)
    //    Actually this is: we recorded payment in ledger, bank hasn't cleared it yet
    // 3. We Credit They DON'T Debit     = bank-only IN (they paid us, not in our ledger)
    // 4. They Credit We DON'T Debit     = bank IN not in ledger (timing)
    $cat1 = array_values(array_filter($bankOnly, fn($l) => $l['direction'] === 'OUT'));
    $cat2 = array_values(array_filter($ledgerUnm, fn($l) => $l['direction'] === 'OUT'));  // outstanding payments
    $cat3 = array_values(array_filter($bankOnly, fn($l) => $l['direction'] === 'IN'));
    $cat4 = array_values(array_filter($ledgerUnm, fn($l) => $l['direction'] === 'IN'));   // outstanding receipts

    $sum1 = array_sum(array_column($cat1, 'amount'));
    $sum2 = array_sum(array_column($cat2, 'amount'));
    $sum3 = array_sum(array_column($cat3, 'amount'));
    $sum4 = array_sum(array_column($cat4, 'amount'));

    // Group bank-only by type for category sheets
    $byType = [];
    foreach ($bankOnly as $l) {
        $t = $l['bank_only_type'] ?: 'Other';
        $byType[$t][] = $l;
    }

    // ── Build workbook ─────────────────────────────────────────────────────────
    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Smartbooks')->setTitle('Bank Reconciliation ' . $recon['recon_number']);

    // ══════════════════════════════════════════════════════════════════
    // SHEET 1 — RECON (Summary)  mirrors ZBN template layout
    // ══════════════════════════════════════════════════════════════════
    $s1 = $ss->getActiveSheet()->setTitle('Recon');

    // Logo path — walk up the directory tree looking for utils/images/az-logo.png
    // Uses dirname() with a single argument (PHP 5.2+ safe, no Intelephense warning)
    $logoPath = '';
    $dir = __DIR__;
    for ($i = 0; $i < 4; $i++) {
        $try = $dir . '/utils/images/az-logo.png';
        if (file_exists($try)) { $logoPath = $try; break; }
        $dir = dirname($dir);   // one arg — fully compatible, no static-analysis warnings
    }
    if ($logoPath !== '') {
        $d = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $d->setName('Logo');
        $d->setDescription('Logo');
        $d->setPath($logoPath);
        $d->setHeight(30);
        $d->setWorksheet($s1);
        $d->setCoordinates('B1');
    }

    // Company header
    $s1->setCellValue('B1', $recon['company_name']);
    $s1->setCellValue('B2', 'BANK RECONCILIATION');
    $s1->setCellValue('B3', fmtD($recon['period_to']));
    $s1->setCellValue('A4', 'Acc # ');
    $acctStr = trim(($recon['bank_name'] ?: '') . ' A/C ' . ($recon['account_number'] ?: $recon['account_name'] ?: ''));
    $s1->setCellValue('B4', $acctStr ?: '—');
    cellStyle($s1, 'B1', ['bold'=>true, 'size'=>12, 'color'=>'FF'.'0D1F1B']);
    cellStyle($s1, 'B2', ['bold'=>true, 'size'=>11, 'color'=>'FF'.'00B196']);
    cellStyle($s1, 'A4', ['bold'=>true]);

    // ── Balance per Ledger ──────────────────────────────────────────────────
    $s1->setCellValue('A6', 'Balance Per Ledger');
    cellStyle($s1, 'A6', ['bold'=>true, 'size'=>10, 'color'=>'FF00B196', 'bg'=>'FFE8F5F2']);
    $s1->mergeCells('A6:D6');

    $s1->setCellValue('A7', 'Balance Current Period');    $s1->setCellValue('E7', (float)$recon['ledger_closing']);
    $s1->setCellValue('A8', "They Debit We DON'T Credit"); $s1->setCellValue('E8', $sum2);
    $s1->setCellValue('A9', "They Credit We DON'T Debit"); $s1->setCellValue('E9', $sum4);
    // Adjusted Ledger = closing + timing OUTs (they debited, we didn't credit) - timing INs
    $adjLedger = (float)$recon['ledger_closing'] + $sum2 - $sum4;
    $s1->setCellValue('A10', 'Adjusted Ledger Balance');   $s1->setCellValue('E10', '=E7+E8-E9');

    foreach ([7,8,9,10] as $r) {
        numFmt($s1, "E$r");
        if ($r === 10) cellStyle($s1, "A$r:E$r", ['bold'=>true, 'bg'=>'FFD4F0EA', 'borders'=>'thin']);
        else cellStyle($s1, "A$r:E$r", ['borders'=>'thin']);
    }

    // ── Balance per Bank ────────────────────────────────────────────────────
    $s1->setCellValue('A12', 'Balance Per Bank');
    cellStyle($s1, 'A12', ['bold'=>true, 'size'=>10, 'color'=>'FF00B196', 'bg'=>'FFE8F5F2']);
    $s1->mergeCells('A12:D12');

    $s1->setCellValue('A13', 'Balance Current Period');     $s1->setCellValue('E13', (float)$recon['bank_closing']);
    $s1->setCellValue('A14', "We Credit They DON'T Debit"); $s1->setCellValue('E14', $sum2); // ledger OUT not in bank
    $s1->setCellValue('A15', "We Debit They DON'T Credit"); $s1->setCellValue('E15', $sum1); // bank-only OUT

    $s1->setCellValue('A16', 'Adjusted Bank Balance'); $s1->setCellValue('E16', '=E13-E14-E15');
    $s1->setCellValue('A17', 'Difference');
    $s1->setCellValue('E17', '=E16-E10');

    foreach ([13,14,15,16,17] as $r) {
        numFmt($s1, "E$r");
        if ($r === 16) cellStyle($s1, "A$r:E$r", ['bold'=>true, 'bg'=>'FFD4F0EA', 'borders'=>'thin']);
        elseif ($r === 17) cellStyle($s1, "A$r:E$r", ['bold'=>true, 'size'=>11, 'bg'=>'FFE8F5F2', 'borders'=>'thin']);
        else cellStyle($s1, "A$r:E$r", ['borders'=>'thin']);
    }

    // Sign-off
    $s1->setCellValue('A19', 'Prepared by:');  $s1->setCellValue('B19', $recon['created_by']);
    $s1->setCellValue('D19', 'DATE'); $s1->setCellValue('E19', date('d M Y'));
    $s1->setCellValue('A20', 'Reviewed by:');
    $s1->setCellValue('A21', 'Approved by:');
    foreach ([19,20,21] as $r) cellStyle($s1, "A$r", ['bold'=>true]);

    // Column widths
    $s1->getColumnDimension('A')->setWidth(38);
    $s1->getColumnDimension('B')->setWidth(22);
    $s1->getColumnDimension('C')->setWidth(14);
    $s1->getColumnDimension('D')->setWidth(10);
    $s1->getColumnDimension('E')->setWidth(20);

    // ══════════════════════════════════════════════════════════════════
    // SHEET 2 — DETAILS (Reconciling items, 4 categories)
    // ══════════════════════════════════════════════════════════════════
    $s2 = $ss->createSheet()->setTitle('Details');
    $s2->setCellValue('A1', 'Reconciling Items — ' . $recon['recon_number']);
    cellStyle($s2, 'A1', ['bold'=>true, 'size'=>13, 'color'=>'FF00B196']);
    $s2->getRowDimension(1)->setRowHeight(22);

    $dRow = 3;
    $dRow = writeDetailsCategory($s2, $dRow, "We Debit They DON'T Credit  (Bank-Only OUT: bank debited, no ledger entry)", $cat1, ['headBg'=>'FFDC2626']);
    $dRow = writeDetailsCategory($s2, $dRow, "They Debit We DON'T Credit  (Outstanding Payments: posted in ledger, not yet cleared in bank)", $cat2, ['headBg'=>'FFCA8A04']);
    $dRow = writeDetailsCategory($s2, $dRow, "We Credit They DON'T Debit  (Bank-Only IN: bank credited, no ledger entry)", $cat3, ['headBg'=>'FF16A34A']);
    $dRow = writeDetailsCategory($s2, $dRow, "They Credit We DON'T Debit  (Outstanding Receipts: receipted in ledger, not yet in bank)", $cat4, ['headBg'=>'FF6366F1']);

    foreach (['A'=>40,'B'=>16,'C'=>60,'D'=>16,'E'=>22,'F'=>28,'G'=>30] as $col=>$w) $s2->getColumnDimension($col)->setWidth($w);

    // ══════════════════════════════════════════════════════════════════
    // SHEET 3 — BANK (Full bank statement)
    // ══════════════════════════════════════════════════════════════════
    $s3 = $ss->createSheet()->setTitle('Bank');

    // Account header block
    $s3->setCellValue('A1', 'ACCOUNT NAME');
    $s3->setCellValue('B1', $recon['account_name'] ?: $recon['company_name']);
    $s3->setCellValue('C1', 'ACCOUNT NUMBER');
    $s3->setCellValue('D1', $recon['account_number'] ?: '');
    $s3->setCellValue('A2', 'PERIOD');
    $s3->setCellValue('B2', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
    $s3->setCellValue('A3', 'OPENING BALANCE');
    $s3->setCellValue('B3', (float)$recon['bank_opening']);
    numFmt($s3, 'B3');
    cellStyle($s3, 'A1:D2', ['bold'=>true, 'bg'=>'FFE8F5F2', 'borders'=>'thin']);

    // Column headers
    $bankHdrs = ['Date','Description','Reference','Direction','Debit / Amount','Credit / Amount','Balance','Status','Match Group','Classification'];
    foreach (array_values($bankHdrs) as $i => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $s3->setCellValue("{$col}5", $h);
    }
    cellStyle($s3, 'A5:J5', ['bold'=>true, 'color'=>'FFFFFFFF', 'bg'=>'FF009E87', 'borders'=>'thin']);

    $r = 6;
    foreach ($bankLines as $i => $l) {
        $isOut = $l['direction'] === 'OUT';
        $s3->setCellValue("A$r", $l['txn_date']);
        $s3->setCellValue("B$r", $l['description']);
        $s3->setCellValue("C$r", $l['reference'] ?: '');
        $s3->setCellValue("D$r", $l['direction']);
        $s3->setCellValue("E$r", $isOut ? (float)$l['amount'] : '');
        $s3->setCellValue("F$r", $isOut ? '' : (float)$l['amount']);
        $s3->setCellValue("G$r", $l['running_balance'] ? (float)$l['running_balance'] : '');
        $s3->setCellValue("H$r", $l['match_status']);
        $s3->setCellValue("I$r", $l['match_group'] ?: '');
        $s3->setCellValue("J$r", $l['bank_only_type'] ?: '');
        $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB';
        cellStyle($s3, "A$r:J$r", ['bg'=>$bg, 'borders'=>'thin', 'size'=>8]);
        numFmt($s3, "E$r"); numFmt($s3, "F$r"); numFmt($s3, "G$r");
        // Colour direction
        if ($isOut) cellStyle($s3, "D$r", ['color'=>'FFDC2626']);
        else        cellStyle($s3, "D$r", ['color'=>'FF16A34A']);
        $r++;
    }
    // Totals
    $s3->setCellValue("D$r", 'Totals');
    $s3->setCellValue("E$r", "=SUM(E6:E".($r-1).")");
    $s3->setCellValue("F$r", "=SUM(F6:F".($r-1).")");
    cellStyle($s3, "A$r:J$r", ['bold'=>true, 'bg'=>'FFD4F0EA', 'borders'=>'thin']);
    numFmt($s3, "E$r"); numFmt($s3, "F$r");

    foreach (['A'=>12,'B'=>55,'C'=>20,'D'=>10,'E'=>18,'F'=>18,'G'=>18,'H'=>13,'I'=>22,'J'=>20] as $col=>$w) $s3->getColumnDimension($col)->setWidth($w);

    // ══════════════════════════════════════════════════════════════════
    // SHEET 4 — LEDGER (Full ledger statement — mirrors Datacom format)
    // ══════════════════════════════════════════════════════════════════
    $s4 = $ss->createSheet()->setTitle('Ledger');

    $s4->setCellValue('A1', $recon['company_name']);
    $s4->setCellValue('F1', 'Statement of Account');
    $s4->setCellValue('A2', 'Date From:'); $s4->setCellValue('B2', fmtD($recon['period_from']));
    $s4->setCellValue('A3', 'Date To:');   $s4->setCellValue('B3', fmtD($recon['period_to']));
    $s4->setCellValue('A4', 'Opening Balance:'); $s4->setCellValue('B4', (float)$recon['ledger_opening']);
    numFmt($s4, 'B4');
    cellStyle($s4, 'A1', ['bold'=>true, 'size'=>12]);
    cellStyle($s4, 'F1', ['bold'=>true, 'size'=>11, 'color'=>'FF00B196']);

    $ledgerHdrs = ['Date','Reference','Description','Ledger Name','Debit','Credit','Balance','Status','Match Group'];
    foreach (array_values($ledgerHdrs) as $i => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $s4->setCellValue("{$col}6", $h);
    }
    cellStyle($s4, 'A6:I6', ['bold'=>true, 'color'=>'FFFFFFFF', 'bg'=>'FF009E87', 'borders'=>'thin']);

    $r = 7;
    foreach ($ledgerLines as $i => $l) {
        $isOut = $l['direction'] === 'OUT';
        $s4->setCellValue("A$r", $l['txn_date']);
        $s4->setCellValue("B$r", $l['reference'] ?: '');
        $s4->setCellValue("C$r", $l['description']);
        $s4->setCellValue("D$r", $l['ledger_name'] ?: '');
        $s4->setCellValue("E$r", $isOut ? (float)$l['amount'] : '');  // credit = debit in ledger = payment
        $s4->setCellValue("F$r", $isOut ? '' : (float)$l['amount']);  // debit = credit in ledger = receipt
        $s4->setCellValue("G$r", isset($l['running_balance']) && $l['running_balance'] ? (float)$l['running_balance'] : '');
        $s4->setCellValue("H$r", $l['match_status']);
        $s4->setCellValue("I$r", $l['match_group'] ?: '');
        $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB';
        cellStyle($s4, "A$r:I$r", ['bg'=>$bg, 'borders'=>'thin', 'size'=>8]);
        numFmt($s4, "E$r"); numFmt($s4, "F$r"); numFmt($s4, "G$r");
        $r++;
    }
    $s4->setCellValue("C$r", 'Totals');
    $s4->setCellValue("E$r", "=SUM(E7:E".($r-1).")");
    $s4->setCellValue("F$r", "=SUM(F7:F".($r-1).")");
    cellStyle($s4, "A$r:I$r", ['bold'=>true, 'bg'=>'FFD4F0EA', 'borders'=>'thin']);
    numFmt($s4, "E$r"); numFmt($s4, "F$r");

    foreach (['A'=>12,'B'=>18,'C'=>55,'D'=>22,'E'=>16,'F'=>16,'G'=>16,'H'=>13,'I'=>22] as $col=>$w) $s4->getColumnDimension($col)->setWidth($w);

    // ══════════════════════════════════════════════════════════════════
    // SHEET 5+ — One sheet per Bank-Only category (Bank Charges, Stamp Duty, etc.)
    // ══════════════════════════════════════════════════════════════════
    $categoryColors = [
        'Bank Charge'       => 'FFDC2626',
        'Stamp Duty'        => 'FFCA8A04',
        'WHT Remittance'    => 'FF8B5CF6',
        'Bank Interest'     => 'FF16A34A',
        'LC/Trade Finance'  => 'FF0EA5E9',
        'Reversal'          => 'FF6B7280',
        'Other'             => 'FF374151',
        'Unmatched Bank'    => 'FFB45309',
    ];

    foreach ($byType as $typeName => $items) {
        $sheetName = substr($typeName, 0, 31); // Excel limit
        $sc = $ss->createSheet()->setTitle($sheetName);
        $color = $categoryColors[$typeName] ?? 'FF009E87';

        // Header
        $sc->setCellValue('A1', $recon['company_name']);
        $sc->setCellValue('A2', $typeName . ' — Extract from Bank Statement');
        $sc->setCellValue('A3', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
        $sc->setCellValue('A4', $recon['bank_name'] ?: '');
        cellStyle($sc, 'A1', ['bold'=>true, 'size'=>12]);
        cellStyle($sc, 'A2', ['bold'=>true, 'size'=>11, 'color'=>'FF'.$typeName]);

        $catHdrs = ['Date','Description','Reference','Amount','Dr Ledger','Cr Ledger','Note'];
        foreach (array_values($catHdrs) as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1);
            $sc->setCellValue("{$col}6", $h);
        }
        cellStyle($sc, 'A6:G6', ['bold'=>true, 'color'=>'FFFFFFFF', 'bg'=>$color, 'borders'=>'thin']);

        $r = 7;
        foreach ($items as $i => $l) {
            $sc->setCellValue("A$r", $l['txn_date']);
            $sc->setCellValue("B$r", $l['description']);
            $sc->setCellValue("C$r", $l['reference'] ?: '');
            $sc->setCellValue("D$r", (float)$l['amount']);
            $sc->setCellValue("E$r", $l['suggested_dr_ledger'] ?: '');
            $sc->setCellValue("F$r", $l['suggested_cr_ledger'] ?: '');
            $sc->setCellValue("G$r", $l['journal_note'] ?: '');
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB';
            cellStyle($sc, "A$r:G$r", ['bg'=>$bg, 'borders'=>'thin', 'size'=>8]);
            numFmt($sc, "D$r");
            $r++;
        }
        // Total
        $sc->setCellValue("C$r", 'Total');
        $sc->setCellValue("D$r", "=SUM(D7:D".($r-1).")");
        cellStyle($sc, "A$r:G$r", ['bold'=>true, 'bg'=>'FFD4F0EA', 'borders'=>'thin', 'color'=>'FF009E87']);
        numFmt($sc, "D$r");

        foreach (['A'=>12,'B'=>55,'C'=>18,'D'=>18,'E'=>28,'F'=>28,'G'=>30] as $col=>$w) $sc->getColumnDimension($col)->setWidth($w);
    }

    // Add "Unmatched Bank" sheet for bank lines with no classification
    if (!empty($bankUnm)) {
        $su = $ss->createSheet()->setTitle('Unmatched Bank');
        $su->setCellValue('A1', $recon['company_name']);
        $su->setCellValue('A2', 'Unmatched Bank Lines — No ledger entry found');
        cellStyle($su, 'A1', ['bold'=>true, 'size'=>12]);
        cellStyle($su, 'A2', ['bold'=>true, 'size'=>11, 'color'=>'FFB45309']);
        $uHdrs = ['Date','Description','Reference','Dir','Amount','Balance','Action Needed'];
        foreach (array_values($uHdrs) as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1);
            $su->setCellValue("{$col}4", $h);
        }
        cellStyle($su, 'A4:G4', ['bold'=>true, 'color'=>'FFFFFFFF', 'bg'=>'FFB45309', 'borders'=>'thin']);
        $r=5;
        foreach ($bankUnm as $i => $l) {
            $su->setCellValue("A$r", $l['txn_date']);
            $su->setCellValue("B$r", $l['description']);
            $su->setCellValue("C$r", $l['reference'] ?: '');
            $su->setCellValue("D$r", $l['direction']);
            $su->setCellValue("E$r", (float)$l['amount']);
            $su->setCellValue("F$r", $l['running_balance'] ? (float)$l['running_balance'] : '');
            $su->setCellValue("G$r", 'Classify or match manually');
            $bg = $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB';
            cellStyle($su, "A$r:G$r", ['bg'=>$bg, 'borders'=>'thin', 'size'=>8]);
            numFmt($su, "E$r"); numFmt($su, "F$r");
            $r++;
        }
        foreach (['A'=>12,'B'=>55,'C'=>18,'D'=>8,'E'=>18,'F'=>18,'G'=>25] as $col=>$w) $su->getColumnDimension($col)->setWidth($w);
    }

    // ── Output ─────────────────────────────────────────────────────────────────
    $ss->setActiveSheetIndex(0);
    $filename = 'BankRecon_' . $recon['recon_number'] . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}