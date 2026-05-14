<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Route not found', 404);
    $user = authenticateUser();
    if (!in_array($user['integrity'], ['Admin', 'Controller'])) throw new Exception('Unauthorized', 401);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('id is required', 400);
    $r = $conn->prepare('SELECT * FROM bank_reconciliations WHERE id=? LIMIT 1');
    $r->bind_param('i', $id);
    $r->execute();
    $recon = $r->get_result()->fetch_assoc();
    $r->close();
    if (!$recon) throw new Exception('Reconciliation not found', 404);
    $bank = $conn->query("SELECT transaction_date, reference, description, debit, credit, amount, running_balance, match_status, suggested_type, confidence FROM bank_reconciliation_bank_lines WHERE reconciliation_id=$id ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $ledger = $conn->query("SELECT transaction_date, reference, ledger_number, ledger_name, description, debit, credit, amount, match_status, suggested_type, confidence FROM bank_reconciliation_ledger_lines WHERE reconciliation_id=$id ORDER BY transaction_date,id")->fetch_all(MYSQLI_ASSOC);
    $adj = $conn->query("SELECT adjustment_type, amount, recommended_debit_ledger, recommended_credit_ledger, journal_status, narration FROM bank_reconciliation_adjustments WHERE reconciliation_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Smartbooks')->setTitle('Bank Reconciliation');
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Summary');
    $sheet->fromArray([['Bank Reconciliation'], ['Reference', $recon['reconciliation_number']], ['Company / Client', $recon['company_name'] ?? ''], ['Bank', $recon['bank_name'] ?? ''], ['Account', trim(($recon['account_name'] ?? '') . ' - ' . ($recon['account_number'] ?? ''), ' -')], ['Currency', $recon['currency']], ['Period', $recon['date_from'] . ' to ' . $recon['date_to']], ['Status', $recon['status']], [], ['Statement Closing', $recon['statement_closing_balance']], ['Ledger Closing', $recon['ledger_closing_balance']], ['Adjusted Bank', $recon['adjusted_bank_balance']], ['Adjusted Ledger', $recon['adjusted_ledger_balance']], ['Difference', $recon['unreconciled_difference']]], null, 'A1');
    $sheet->mergeCells('A1:B1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1:B14')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getColumnDimension('A')->setWidth(24);
    $sheet->getColumnDimension('B')->setWidth(44);
    foreach ([['Bank Lines', $bank], ['Ledger Lines', $ledger], ['Suggested Adjustments', $adj]] as $idx => $pack) {
        $s = $idx === 0 ? $ss->createSheet() : $ss->createSheet();
        $s->setTitle(substr($pack[0], 0, 31));
        $rows = $pack[1];
        if (count($rows)) {
            $headers = array_keys($rows[0]);
            $s->fromArray($headers, null, 'A1');
            $s->fromArray(array_map('array_values', $rows), null, 'A2');
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
            $s->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $s->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF009E87');
            for ($i = 1; $i <= count($headers); $i++) $s->getColumnDimensionByColumn($i)->setAutoSize(true);
        } else {
            $s->setCellValue('A1', 'No records');
        }
    }
    $file = 'Bank_Reconciliation_' . $recon['reconciliation_number'] . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
