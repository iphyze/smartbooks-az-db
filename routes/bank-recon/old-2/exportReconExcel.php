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
    $name = preg_replace('/[\\\/\?\*\[\]:]/', ' ', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return substr($name ?: 'Sheet', 0, 31);
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
function writeHeaders($sheet, int $row, array $headers, string $bg = 'FF009E87'): void
{
    foreach ($headers as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $h);
    $last = Coordinate::stringFromColumnIndex(count($headers));
    styleRange($sheet, "A{$row}:{$last}{$row}", ['bold' => true, 'color' => 'FFFFFFFF', 'bg' => $bg, 'border' => true, 'align' => Alignment::HORIZONTAL_CENTER]);
}
function writeStatementSheet($sheet, string $title, array $recon, array $lines, string $source): void
{
    $sheet->setCellValue('A1', strtoupper($title));
    $sheet->setCellValue('A2', $recon['company_name']);
    $sheet->setCellValue('A3', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
    styleRange($sheet, 'A1:J1', ['bold' => true, 'size' => 14, 'color' => 'FF00B196']);
    styleRange($sheet, 'A2:J3', ['bold' => true, 'bg' => 'FFE8F5F2', 'border' => true]);

    if ($source === 'bank') {
        $headers = ['Date', 'Description', 'Reference', 'Direction', 'Debit', 'Credit', 'Balance', 'Status', 'Match Group', 'Category', 'Classification'];
    } else {
        $headers = ['Date', 'Description', 'Reference', 'Ledger', 'Direction', 'Debit', 'Credit', 'Balance', 'Status', 'Match Group', 'Category', 'Classification'];
    }
    writeHeaders($sheet, 5, $headers);
    $row = 6;
    foreach ($lines as $i => $l) {
        $isOut = ($l['direction'] ?? '') === 'OUT';
        $values = $source === 'bank'
            ? [fmtD($l['txn_date']), $l['description'], $l['reference'], $l['direction'], $isOut ? (float)$l['amount'] : null, !$isOut ? (float)$l['amount'] : null, $l['running_balance'], $l['match_status'], $l['match_group'], $l['category_name'] ?: ($l['bank_only_type'] ?? ''), $l['recon_classification']]
            : [fmtD($l['txn_date']), $l['description'], $l['reference'], $l['ledger_name'], $l['direction'], $isOut ? (float)$l['amount'] : null, !$isOut ? (float)$l['amount'] : null, $l['running_balance'], $l['match_status'], $l['match_group'], $l['category_name'], $l['recon_classification']];
        foreach ($values as $c => $v) $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $v);
        $last = Coordinate::stringFromColumnIndex(count($headers));
        styleRange($sheet, "A{$row}:{$last}{$row}", ['bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB', 'border' => true, 'wrap' => true]);
        $row++;
    }
    $last = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->setCellValue("D{$row}", 'Totals');
    if ($source === 'bank') {
        $sheet->setCellValue("E{$row}", '=SUM(E6:E' . ($row - 1) . ')');
        $sheet->setCellValue("F{$row}", '=SUM(F6:F' . ($row - 1) . ')');
        moneyFmt($sheet, "E6:G{$row}");
    } else {
        $sheet->setCellValue("F{$row}", '=SUM(F6:F' . ($row - 1) . ')');
        $sheet->setCellValue("G{$row}", '=SUM(G6:G' . ($row - 1) . ')');
        moneyFmt($sheet, "F6:H{$row}");
    }
    styleRange($sheet, "A{$row}:{$last}{$row}", ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);
    $widths = $source === 'bank'
        ? ['A' => 13, 'B' => 56, 'C' => 18, 'D' => 11, 'E' => 16, 'F' => 16, 'G' => 18, 'H' => 14, 'I' => 20, 'J' => 22, 'K' => 28]
        : ['A' => 13, 'B' => 56, 'C' => 18, 'D' => 22, 'E' => 11, 'F' => 16, 'G' => 16, 'H' => 18, 'I' => 14, 'J' => 20, 'K' => 22, 'L' => 28];
    foreach ($widths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
    $sheet->freezePane('A6');
}
function writeCategorySheet($sheet, string $category, array $items, array $recon): void
{
    $sheet->setCellValue('A1', $recon['company_name']);
    $sheet->setCellValue('A2', $category . ' — Extract');
    $sheet->setCellValue('A3', fmtD($recon['period_from']) . ' to ' . fmtD($recon['period_to']));
    styleRange($sheet, 'A1:I1', ['bold' => true, 'size' => 13]);
    styleRange($sheet, 'A2:I2', ['bold' => true, 'size' => 11, 'color' => 'FF00B196']);
    $headers = ['Source', 'Date', 'Description', 'Reference', 'Direction', 'Amount', 'Recon Classification', 'Dr Ledger', 'Cr Ledger', 'Note'];
    writeHeaders($sheet, 5, $headers, 'FF0F766E');
    $row = 6;
    foreach ($items as $i => $l) {
        $values = [$l['_source'], fmtD($l['txn_date']), $l['description'], $l['reference'], $l['direction'], (float)$l['amount'], $l['recon_classification'], $l['suggested_dr_ledger'], $l['suggested_cr_ledger'], $l['journal_note']];
        foreach ($values as $c => $v) $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $v);
        styleRange($sheet, "A{$row}:J{$row}", ['bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB', 'border' => true, 'wrap' => true]);
        $row++;
    }
    $sheet->setCellValue("E{$row}", 'Total');
    $sheet->setCellValue("F{$row}", '=SUM(F6:F' . ($row - 1) . ')');
    moneyFmt($sheet, "F6:F{$row}");
    styleRange($sheet, "A{$row}:J{$row}", ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);
    foreach (['A' => 12, 'B' => 13, 'C' => 58, 'D' => 18, 'E' => 12, 'F' => 18, 'G' => 32, 'H' => 28, 'I' => 28, 'J' => 32] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
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
    $sheet->getColumnDimension('A')->setWidth(36);
    $sheet->getColumnDimension('B')->setWidth(10);
    $sheet->getColumnDimension('C')->setWidth(20);

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
    $matches = fetchAll($conn, "SELECT * FROM bank_recon_matches WHERE recon_id=$id ORDER BY matched_at,id");

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
    $diff = round($adjustedBank - $adjustedLedger, 2);

    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Smartbooks')->setTitle('Bank Reconciliation ' . $recon['recon_number']);

    // Sheet 1: Recon summary
    $s1 = $ss->getActiveSheet()->setTitle('Recon');
    $s1->setCellValue('B2', strtoupper($recon['company_name']));
    $s1->setCellValue('B3', 'BANK RECONCILIATION');
    $s1->setCellValue('B4', fmtD($recon['period_to']));
    $s1->setCellValue('A5', 'Acc #');
    $s1->setCellValue('B5', trim(($recon['bank_name'] ?: '') . ' A/C ' . ($recon['account_number'] ?: $recon['account_name'] ?: '')));
    styleRange($s1, 'B2:E2', ['bold' => true, 'size' => 13]);
    styleRange($s1, 'B3:E3', ['bold' => true, 'size' => 12, 'color' => 'FF00B196']);

    $s1->setCellValue('A7', 'Balance Per Ledger');
    $s1->mergeCells('A7:D7');
    styleRange($s1, 'A7:E7', ['bold' => true, 'bg' => 'FFE8F5F2']);
    $s1->setCellValue('A9', 'Balance Current Period');
    $s1->setCellValue('E9', (float)$recon['ledger_closing']);
    // ── Recon sheet: one row per reconciling class (clean ZBN style) ──────
    $s1->setCellValue('A11', "They Debit We DON'T Credit");
    $s1->setCellValue('E11', $theyDebitWeDontCredit);

    $s1->setCellValue('A13', "They Credit We DON'T Debit");
    $s1->setCellValue('E13', $theyCreditWeDontDebit);

    $s1->setCellValue('A17', 'Adjusted Ledger Balance');
    $s1->setCellValue('E17', '=E9-E11+E13');

    $s1->setCellValue('A20', 'Balance Per Bank');
    $s1->mergeCells('A20:D20');
    styleRange($s1, 'A20:E20', ['bold' => true, 'bg' => 'FFE8F5F2']);
    $s1->setCellValue('A22', 'Balance Current Period');
    $s1->setCellValue('E22', (float)$recon['bank_closing']);
    $s1->setCellValue('A24', "We Debit They DON'T Credit");
    $s1->setCellValue('E24', $weDebitTheyDontCredit);
    $s1->setCellValue('A26', "We Credit They DON'T Debit");
    $s1->setCellValue('E26', $weCreditTheyDontDebit);
    $s1->setCellValue('A28', 'Adjusted Bank Balance');
    $s1->setCellValue('E28', '=E22+E24-E26');
    $s1->setCellValue('A30', 'Difference');
    $s1->setCellValue('E30', '=E28-E17');

    // Prior Period memo row
    $priorTotal = amountSum($classes["Prior Period Item"]);
    if ($priorTotal > 0) {
        $s1->setCellValue('A32', 'Prior Period Items (memo)');
        $s1->setCellValue('E32', $priorTotal);
        moneyFmt($s1, 'E32');
        styleRange($s1, 'A32:E32', ['italic' => true, 'color' => 'FF64748B', 'border' => true]);
    }

    $s1->setCellValue('A33', 'Prepared by:');
    $s1->setCellValue('B33', $recon['created_by']);
    $s1->setCellValue('D33', 'DATE');
    $s1->setCellValue('E33', date('d/m/Y'));
    $s1->setCellValue('A35', 'Reviewed by:');
    $s1->setCellValue('D35', 'DATE');
    $s1->setCellValue('A37', 'Approved by:');
    $s1->setCellValue('D37', 'DATE');

    foreach ([9, 11, 13, 17, 22, 24, 26, 28, 30] as $r) {
        moneyFmt($s1, "E$r");
        styleRange($s1, "A$r:E$r", ['border' => true]);
    }
    styleRange($s1, 'A11:E11', ['color' => 'FFCA8A04', 'bold' => true, 'border' => true]);
    styleRange($s1, 'A13:E13', ['color' => 'FF16A34A', 'bold' => true, 'border' => true]);
    styleRange($s1, 'A24:E24', ['color' => 'FFDC2626', 'bold' => true, 'border' => true]);
    styleRange($s1, 'A26:E26', ['color' => 'FF6366F1', 'bold' => true, 'border' => true]);
    styleRange($s1, 'A17:E17', ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);
    styleRange($s1, 'A28:E28', ['bold' => true, 'bg' => 'FFD4F0EA', 'border' => true]);
    styleRange($s1, 'A30:E30', ['bold' => true, 'size' => 11, 'bg' => abs($diff) <= 0.01 ? 'FFD4F0EA' : 'FFFEE2E2', 'border' => true]);
    foreach (['A' => 34, 'B' => 26, 'C' => 12, 'D' => 12, 'E' => 20] as $col => $w) $s1->getColumnDimension($col)->setWidth($w);

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
    $usedSheetNames = ['Recon', 'Details', 'Bank', 'Ledger', 'Matched Items'];
    foreach ($byCat as $cat => $items) {
        $baseName = cleanSheetName($cat);
        $sheetName = $baseName;
        $suffix = 2;
        while (in_array(strtolower($sheetName), array_map('strtolower', $usedSheetNames))) {
            $sheetName = substr($baseName, 0, 28) . '_' . $suffix++;
        }
        $usedSheetNames[] = $sheetName;
        $newSheet = $ss->createSheet();
        $newSheet->setTitle($sheetName);
        writeCategorySheet($newSheet, $cat, $items, $recon);
    }

    // Matched items extract
    $sm = $ss->createSheet()->setTitle('Matched Items');
    $sm->setCellValue('A1', 'Matched Items');
    styleRange($sm, 'A1:H1', ['bold' => true, 'size' => 13, 'color' => 'FF00B196']);
    writeHeaders($sm, 3, ['Group', 'Type', 'Bank Line ID', 'Ledger Line ID', 'Amount Diff', 'Day Diff', 'Confidence', 'Matched At']);
    $r = 4;
    foreach ($matches as $i => $m) {
        foreach ([$m['match_group'], $m['match_type'], $m['bank_line_id'], $m['ledger_line_id'], (float)$m['amount_difference'], (int)$m['day_difference'], (int)$m['confidence'], $m['matched_at']] as $c => $v) {
            $sm->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $r, $v);
        }
        styleRange($sm, "A{$r}:H{$r}", ['border' => true, 'bg' => $i % 2 === 0 ? 'FFFFFFFF' : 'FFF8FCFB']);
        $r++;
    }
    foreach (['A' => 22, 'B' => 12, 'C' => 14, 'D' => 14, 'E' => 14, 'F' => 12, 'G' => 12, 'H' => 20] as $col => $w) $sm->getColumnDimension($col)->setWidth($w);

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