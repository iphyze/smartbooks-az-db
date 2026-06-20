<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

const JOURNAL_IMPORT_MAX_ROWS = 500;
const JOURNAL_IMPORT_TYPES = ['Payment', 'Receipt', 'Expenses', 'Sales', 'General', 'Journal'];
const JOURNAL_IMPORT_TRANSACTION_TYPES = ['Cash', 'Bank', 'Not Applicable'];
const JOURNAL_IMPORT_CURRENCIES = ['NGN', 'USD', 'GBP', 'EUR'];
const JOURNAL_IMPORT_SIDES = ['Debit', 'Credit'];

function journalImportFail(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'status' => 'Failed',
        'message' => $message,
    ], $extra));
    exit;
}

function journalImportText($value): string
{
    return trim((string) ($value ?? ''));
}

function journalImportDate($value, string $label): string
{
    $raw = journalImportText($value);
    if ($raw === '') {
        throw new RuntimeException("{$label} is required.", 400);
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
        $date = DateTime::createFromFormat('!Y-m-d', $raw);
        $errors = DateTime::getLastErrors();
        if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        throw new RuntimeException("{$label} must use a valid date such as 2026-06-20.", 400);
    }

    return date('Y-m-d', $timestamp);
}

function journalImportEnum($value, array $allowed, string $label): string
{
    $raw = journalImportText($value);
    foreach ($allowed as $candidate) {
        if (strcasecmp($raw, $candidate) === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        "{$label} must be one of: " . implode(', ', $allowed) . '.',
        400
    );
}

function journalImportRate(mysqli $conn, string $targetDate): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, ngn_rate, usd_rate, gbp_rate, eur_rate, created_at
         FROM currency_table
         WHERE STR_TO_DATE(created_at, '%Y-%m-%d') <= ?
         ORDER BY STR_TO_DATE(created_at, '%Y-%m-%d') DESC, id DESC
         LIMIT 1"
    );
    $stmt->bind_param('s', $targetDate);
    $stmt->execute();
    $rate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rate) {
        return $rate;
    }

    $fallback = $conn->prepare(
        "SELECT id, ngn_rate, usd_rate, gbp_rate, eur_rate, created_at
         FROM currency_table
         ORDER BY STR_TO_DATE(created_at, '%Y-%m-%d') ASC, id ASC
         LIMIT 1"
    );
    $fallback->execute();
    $rate = $fallback->get_result()->fetch_assoc();
    $fallback->close();

    return $rate ?: null;
}

function journalImportLedgers(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type FROM ledger_table'
    );

    $byName = [];
    $byNumber = [];
    while ($row = $result->fetch_assoc()) {
        $nameKey = mb_strtolower(trim((string) $row['ledger_name']));
        $numberKey = trim((string) $row['ledger_number']);
        if ($nameKey !== '') {
            $byName[$nameKey] = $row;
        }
        if ($numberKey !== '') {
            $byNumber[$numberKey] = $row;
        }
    }

    return ['by_name' => $byName, 'by_number' => $byNumber];
}

function journalImportResolveLedger(array $indexes, string $name, string $number): ?array
{
    $nameKey = mb_strtolower(trim($name));
    $numberKey = trim($number);

    $byName = $nameKey !== '' ? ($indexes['by_name'][$nameKey] ?? null) : null;
    $byNumber = $numberKey !== '' ? ($indexes['by_number'][$numberKey] ?? null) : null;

    if ($byName && $byNumber && (string) $byName['ledger_number'] !== (string) $byNumber['ledger_number']) {
        return null;
    }

    return $byName ?: $byNumber;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        journalImportFail('Route not found.', 405);
    }

    $user = authenticateUser();
    if (!in_array($user['integrity'] ?? '', ['Admin', 'Controller'], true)) {
        journalImportFail('Only Admin or Controller users can import journals.', 403);
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        journalImportFail('Invalid import request.', 400);
    }

    $headerInput = is_array($payload['header'] ?? null) ? $payload['header'] : [];
    $rowsInput = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

    if ($rowsInput === []) {
        journalImportFail('The import file does not contain any journal lines.', 422);
    }
    if (count($rowsInput) > JOURNAL_IMPORT_MAX_ROWS) {
        journalImportFail('A single journal import can contain at most ' . JOURNAL_IMPORT_MAX_ROWS . ' lines.', 422);
    }

    $headerErrors = [];
    $header = [];

    try {
        $header['journal_date'] = journalImportDate($headerInput['journal_date'] ?? '', 'Journal date');
    } catch (RuntimeException $error) {
        $headerErrors['journal_date'] = $error->getMessage();
    }

    try {
        $header['journal_type'] = journalImportEnum($headerInput['journal_type'] ?? '', JOURNAL_IMPORT_TYPES, 'Journal type');
    } catch (RuntimeException $error) {
        $headerErrors['journal_type'] = $error->getMessage();
    }

    try {
        $header['transaction_type'] = journalImportEnum($headerInput['transaction_type'] ?? '', JOURNAL_IMPORT_TRANSACTION_TYPES, 'Transaction type');
    } catch (RuntimeException $error) {
        $headerErrors['transaction_type'] = $error->getMessage();
    }

    try {
        $header['journal_currency'] = journalImportEnum($headerInput['journal_currency'] ?? 'NGN', JOURNAL_IMPORT_CURRENCIES, 'Journal currency');
    } catch (RuntimeException $error) {
        $headerErrors['journal_currency'] = $error->getMessage();
    }

    $header['main_journal_description'] = journalImportText($headerInput['main_journal_description'] ?? '');
    if ($header['main_journal_description'] === '') {
        $headerErrors['main_journal_description'] = 'Main journal description is required.';
    }

    $header['cost_center'] = journalImportText($headerInput['cost_center'] ?? 'Overhead');
    if ($header['cost_center'] === '') {
        $headerErrors['cost_center'] = 'Cost centre is required.';
    }

    $rateTarget = journalImportText($headerInput['rate_date'] ?? '');
    if ($rateTarget === '' && isset($header['journal_date'])) {
        $rateTarget = $header['journal_date'];
    }

    $rate = null;
    if ($rateTarget !== '') {
        try {
            $rateTarget = journalImportDate($rateTarget, 'Rate date');
            $rate = journalImportRate($conn, $rateTarget);
            if (!$rate) {
                $headerErrors['rate_date'] = 'No exchange-rate record is available for the imported journal date.';
            }
        } catch (RuntimeException $error) {
            $headerErrors['rate_date'] = $error->getMessage();
        }
    }

    $ledgerIndexes = journalImportLedgers($conn);

    $periodStmt = $conn->prepare('SELECT end_date, is_locked, lock_reason FROM accounting_periods ORDER BY id DESC LIMIT 1');
    $periodStmt->execute();
    $periodData = $periodStmt->get_result()->fetch_assoc();
    $periodStmt->close();

    if (
        $periodData
        && ($periodData['is_locked'] ?? '') === 'Locked'
        && isset($header['journal_date'])
        && journalImportText($periodData['end_date'] ?? '') >= $header['journal_date']
    ) {
        $headerErrors['journal_date'] = 'Journal date falls within a locked accounting period.';
    }

    $validatedRows = [];
    $rowErrors = [];
    $warnings = [];
    $totalDebitNgn = 0.0;
    $totalCreditNgn = 0.0;

    foreach ($rowsInput as $index => $rowInput) {
        $lineNumber = $index + 2;
        $row = is_array($rowInput) ? $rowInput : [];
        $errors = [];

        $ledgerName = journalImportText($row['ledger_name'] ?? '');
        $ledgerNumber = journalImportText($row['ledger_number'] ?? '');
        $ledger = journalImportResolveLedger($ledgerIndexes, $ledgerName, $ledgerNumber);
        if (!$ledger) {
            $errors['ledger'] = "Ledger was not found or the supplied ledger name and number do not match.";
        }

        $lineDescription = journalImportText($row['journal_description'] ?? '');
        if ($lineDescription === '') {
            $lineDescription = $header['main_journal_description'] ?? '';
        }
        if ($lineDescription === '') {
            $errors['journal_description'] = 'Line description is required.';
        }

        try {
            $lineDate = journalImportDate(
                $row['journal_date'] ?? ($header['journal_date'] ?? ''),
                'Line date'
            );
        } catch (RuntimeException $error) {
            $lineDate = $header['journal_date'] ?? '';
            $errors['journal_date'] = $error->getMessage();
        }

        if (
            $lineDate !== ''
            && $periodData
            && ($periodData['is_locked'] ?? '') === 'Locked'
            && journalImportText($periodData['end_date'] ?? '') >= $lineDate
        ) {
            $errors['journal_date'] = 'Line date falls within a locked accounting period.';
        }

        try {
            $side = journalImportEnum($row['sides'] ?? '', JOURNAL_IMPORT_SIDES, 'Side');
        } catch (RuntimeException $error) {
            $side = '';
            $errors['sides'] = $error->getMessage();
        }

        try {
            $currency = journalImportEnum(
                $row['jcurrency'] ?? ($header['journal_currency'] ?? 'NGN'),
                JOURNAL_IMPORT_CURRENCIES,
                'Line currency'
            );
        } catch (RuntimeException $error) {
            $currency = 'NGN';
            $errors['jcurrency'] = $error->getMessage();
        }

        $amountRaw = str_replace([',', ' '], '', journalImportText($row['amount'] ?? ''));
        $amount = is_numeric($amountRaw) ? (float) $amountRaw : 0.0;
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        }

        $rateColumn = strtolower($currency) . '_rate';
        $currencyRate = $rate && isset($rate[$rateColumn]) ? (float) $rate[$rateColumn] : 0.0;
        if ($currencyRate <= 0) {
            $errors['rate'] = "A valid {$currency} rate is not available.";
        }

        if ($errors === []) {
            $ngnAmount = $amount * $currencyRate;
            if ($side === 'Debit') {
                $totalDebitNgn += $ngnAmount;
            } else {
                $totalCreditNgn += $ngnAmount;
            }
        }

        $validatedRows[] = [
            'ledger_name' => $ledger['ledger_name'] ?? $ledgerName,
            'ledger_number' => $ledger['ledger_number'] ?? $ledgerNumber,
            'ledger_class' => $ledger['ledger_class'] ?? '',
            'ledger_class_code' => $ledger['ledger_class_code'] ?? '',
            'ledger_sub_class' => $ledger['ledger_sub_class'] ?? '',
            'ledger_type' => $ledger['ledger_type'] ?? '',
            'journal_description' => $lineDescription,
            'journal_date' => $lineDate,
            'sides' => $side,
            'jcurrency' => $currency,
            'amount' => $amount > 0 ? (string) $amount : '',
            'jrate' => $rate ? (string) $rate['id'] : '',
            'currencyRate' => $currencyRate > 0 ? $currencyRate : '',
            'rate_date' => $rate['created_at'] ?? '',
            'ngn_rate' => $rate['ngn_rate'] ?? '',
            'usd_rate' => $rate['usd_rate'] ?? '',
            'eur_rate' => $rate['eur_rate'] ?? '',
            'gbp_rate' => $rate['gbp_rate'] ?? '',
        ];

        if ($errors !== []) {
            $rowErrors[] = [
                'row' => $lineNumber,
                'errors' => $errors,
            ];
        }
    }

    $difference = $totalDebitNgn - $totalCreditNgn;
    $isBalanced = abs($difference) < 0.001;
    if (!$isBalanced && $rowErrors === [] && $headerErrors === []) {
        $warnings[] = 'The imported lines are not balanced yet. You can use Add balancing line after importing.';
    }

    $canImport = $headerErrors === [] && $rowErrors === [];

    http_response_code($canImport ? 200 : 422);
    echo json_encode([
        'status' => $canImport ? 'Success' : 'Failed',
        'message' => $canImport
            ? 'The journal file is ready to import into the form.'
            : 'The journal file contains validation errors.',
        'data' => [
            'can_import' => $canImport,
            'header' => array_merge($header, [
                'rate_date' => $rate['created_at'] ?? $rateTarget,
                'master_rate_id' => $rate ? (string) $rate['id'] : '',
            ]),
            'items' => $validatedRows,
            'header_errors' => $headerErrors,
            'row_errors' => $rowErrors,
            'warnings' => $warnings,
            'summary' => [
                'line_count' => count($validatedRows),
                'total_debit_ngn' => $totalDebitNgn,
                'total_credit_ngn' => $totalCreditNgn,
                'difference_ngn' => $difference,
                'is_balanced' => $isBalanced,
            ],
        ],
    ]);
} catch (Throwable $error) {
    error_log('Journal import validation error: ' . $error->getMessage());
    journalImportFail(publicErrorMessage($error), $error->getCode() >= 400 ? $error->getCode() : 500);
}
