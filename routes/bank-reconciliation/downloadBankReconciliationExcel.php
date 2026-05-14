<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function fail($m, $c = 400)
{
    throw new Exception($m, $c);
}
function cleanSheet($name)
{
    $name = preg_replace('/[\\\/\?\*\[\]:]/', ' ', (string)$name);
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return substr($name ?: 'Sheet', 0, 31);
}
function writeRows($ss, $title, $rows)
{
    $s = $ss->createSheet();
    $s->setTitle(cleanSheet($title));
    if (!count($rows)) {
        $s->setCellValue('A1', 'No records');
        return $s;
    }
    $headers = array_keys($rows[0]);
    $s->fromArray($headers, null, 'A1');
    $s->fromArray(array_map('array_values', $rows), null, 'A2');
    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $s->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $s->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF009E87');
    $s->getStyle("A1:{$lastCol}" . (count($rows) + 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    for ($i = 1; $i <= count($headers); $i++) $s->getColumnDimensionByColumn($i)->setAutoSize(true);
    return $s;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) fail('Unauthorized', 401);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id is required');
    $r = $conn->prepare('SELECT * FROM bank_reconciliations WHERE id=? LIMIT 1');
    $r->bind_param('i', $id);
    $r->execute();
    $recon = $r->get_result()->fetch_assoc();
    $r->close();
    if (!$recon) fail('Reconciliation not found', 404);

    $bank = $conn->query("SELECT transaction_date, reference, description, debit, credit, amount, running_balance, match_status, suggested_type, confidence, match_group FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $ledger = $conn->query("SELECT transaction_date, reference, ledger_number, ledger_name, description, debit, credit, amount, match_status, suggested_type, confidence, match_group FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $matches = $conn->query("SELECT match_group, match_type, confidence, notes, created_by, created_at FROM bank_reconciliation_matches WHERE reconciliation_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $adjustments = $conn->query("SELECT adjustment_type, source_line_type, source_line_id, amount, recommended_debit_ledger, recommended_credit_ledger, journal_status, narration, created_by, created_at FROM bank_reconciliation_adjustments WHERE reconciliation_id=$id ORDER BY adjustment_type,id")->fetch_all(MYSQLI_ASSOC);
    $openBank = array_values(array_filter($bank, fn($x) => !in_array($x['match_status'], ['Matched', 'Adjustment'])));
    $openLedger = array_values(array_filter($ledger, fn($x) => !in_array($x['match_status'], ['Matched', 'Adjustment'])));

    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Smartbooks')->setTitle('Bank Reconciliation');
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Summary');
    $sheet->fromArray([
        ['Bank Reconciliation'],
        ['Reference', $recon['reconciliation_number']],
        ['Company / Client', $recon['company_name'] ?? ''],
        ['Bank', $recon['bank_name'] ?? ''],
        ['Account', trim(($recon['account_name'] ?? '') . ' - ' . ($recon['account_number'] ?? ''), ' -')],
        ['Currency', $recon['currency']],
        ['Period', $recon['date_from'] . ' to ' . $recon['date_to']],
        ['Status', $recon['status']],
        [],
        ['Bank Lines', count($bank)],
        ['Ledger Lines', count($ledger)],
        ['Matched Groups', count($matches)],
        ['Open Bank Lines', count($openBank)],
        ['Open Ledger Lines', count($openLedger)],
        ['Classified Items', count($adjustments)],
        ['Open Difference', $recon['unreconciled_difference']],
    ], null, 'A1');
    $sheet->mergeCells('A1:B1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1:B16')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getColumnDimension('A')->setWidth(24);
    $sheet->getColumnDimension('B')->setWidth(52);

    writeRows($ss, 'Matched Items', $matches);
    writeRows($ss, 'Open Bank Lines', $openBank);
    writeRows($ss, 'Open Ledger Lines', $openLedger);
    writeRows($ss, 'Posting Schedule', $adjustments);
    writeRows($ss, 'All Bank Lines', $bank);
    writeRows($ss, 'All Ledger Lines', $ledger);

    $byCategory = [];
    foreach ($adjustments as $a) $byCategory[$a['adjustment_type'] ?: 'Unclassified'][] = $a;
    foreach ($byCategory as $category => $rows) writeRows($ss, $category, $rows);

    $ss->setActiveSheetIndex(0);

    $file = 'Bank_Reconciliation_' . $recon['reconciliation_number'] . '.xlsx';

    // CLEAN OUTPUT BUFFER
    if (ob_get_length()) {
        ob_end_clean();
    }

    // HEADERS
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    // SAVE
    $writer = new Xlsx($ss);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');

    exit;
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
