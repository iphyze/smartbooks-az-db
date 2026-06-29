<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function duplicateJournalVoucherCode(string $type): string
{
    switch ($type) {
        case 'Sales':
            return 'SV';
        case 'Payment':
            return 'PV';
        case 'Journal':
            return 'JV';
        case 'Receipt':
            return 'RV';
        case 'Expenses':
            return 'EV';
        case 'General':
            return 'GV';
        default:
            return 'V';
    }
}

function fetchEffectiveDuplicateRate(mysqli $conn, string $effectiveDate): ?array
{
    $sql = "
        SELECT id, ngn_rate, usd_rate, gbp_rate, eur_rate, created_at
        FROM currency_table
        WHERE STR_TO_DATE(created_at, '%Y-%m-%d') <= ?
        ORDER BY STR_TO_DATE(created_at, '%Y-%m-%d') DESC, id DESC
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare the exchange-rate lookup.', 500);
    }

    $stmt->bind_param('s', $effectiveDate);
    $stmt->execute();
    $rate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rate) {
        return $rate;
    }

    $fallback = $conn->prepare("
        SELECT id, ngn_rate, usd_rate, gbp_rate, eur_rate, created_at
        FROM currency_table
        ORDER BY STR_TO_DATE(created_at, '%Y-%m-%d') ASC, id ASC
        LIMIT 1
    ");

    if (!$fallback) {
        throw new Exception('Unable to prepare the fallback exchange-rate lookup.', 500);
    }

    $fallback->execute();
    $rate = $fallback->get_result()->fetch_assoc() ?: null;
    $fallback->close();

    return $rate;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Route not found.', 405);
    }

    $user = authenticateUser();
    $userIntegrity = (string) ($user['integrity'] ?? '');

    if (!in_array($userIntegrity, ['Admin', 'Controller'], true)) {
        throw new Exception('Unauthorized: Only Admins or Controllers can duplicate Journal Vouchers.', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected a JSON object.', 400);
    }

    $sourceJournalId = (int) ($data['journal_id'] ?? 0);
    if ($sourceJournalId <= 0) {
        throw new Exception('A valid journal ID is required.', 400);
    }

    $headerStmt = $conn->prepare("
        SELECT
            journal_id,
            journal_date,
            journal_type,
            journal_currency,
            transaction_type,
            journal_description,
            cost_center
        FROM journal_table
        WHERE journal_id = ?
        LIMIT 1
    ");

    if (!$headerStmt) {
        throw new Exception('Unable to prepare the journal lookup.', 500);
    }

    $headerStmt->bind_param('i', $sourceJournalId);
    $headerStmt->execute();
    $header = $headerStmt->get_result()->fetch_assoc();
    $headerStmt->close();

    if (!$header) {
        throw new Exception("Journal #{$sourceJournalId} was not found.", 404);
    }

    $lineStmt = $conn->prepare("
        SELECT
            journal_currency,
            journal_description,
            debit,
            credit,
            rate,
            rate_date,
            ngn_rate,
            usd_rate,
            eur_rate,
            gbp_rate,
            ledger_name,
            ledger_number,
            ledger_class,
            ledger_class_code,
            ledger_sub_class,
            ledger_type
        FROM main_journal_table
        WHERE journal_id = ?
        ORDER BY id ASC
    ");

    if (!$lineStmt) {
        throw new Exception('Unable to prepare the journal-line lookup.', 500);
    }

    $lineStmt->bind_param('i', $sourceJournalId);
    $lineStmt->execute();
    $lineResult = $lineStmt->get_result();
    $sourceLines = $lineResult->fetch_all(MYSQLI_ASSOC);
    $lineStmt->close();

    if (!$sourceLines) {
        throw new Exception("Journal #{$sourceJournalId} has no posting lines to duplicate.", 400);
    }

    $duplicateDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $effectiveRate = fetchEffectiveDuplicateRate($conn, $duplicateDate);

    if (!$effectiveRate) {
        throw new Exception('No exchange-rate record is available for the duplicated journal.', 400);
    }

    $items = [];
    foreach ($sourceLines as $line) {
        $currency = strtoupper(trim((string) ($line['journal_currency'] ?? 'NGN')));
        $rateColumn = strtolower($currency) . '_rate';
        if (!array_key_exists($rateColumn, $effectiveRate)) {
            throw new Exception("The currency '{$currency}' is not supported by the journal rate table.", 400);
        }

        $debit = (float) ($line['debit'] ?? 0);
        $credit = (float) ($line['credit'] ?? 0);
        $side = $debit > 0 ? 'Debit' : 'Credit';
        $amount = $debit > 0 ? $debit : $credit;

        if ($amount <= 0) {
            throw new Exception('The source journal contains a line without a valid debit or credit amount.', 400);
        }

        $items[] = [
            'ledger_name' => (string) ($line['ledger_name'] ?? ''),
            'ledger_number' => (string) ($line['ledger_number'] ?? ''),
            'ledger_class' => (string) ($line['ledger_class'] ?? ''),
            'ledger_class_code' => (string) ($line['ledger_class_code'] ?? ''),
            'ledger_sub_class' => (string) ($line['ledger_sub_class'] ?? ''),
            'ledger_type' => (string) ($line['ledger_type'] ?? ''),
            'journal_description' => (string) ($line['journal_description'] ?? ''),
            'journal_date' => $duplicateDate,
            'sides' => $side,
            'jcurrency' => $currency,
            'jrate' => (string) $effectiveRate['id'],
            'currencyRate' => (string) $effectiveRate[$rateColumn],
            'amount' => (string) $amount,
            'rate_date' => (string) ($effectiveRate['created_at'] ?? $duplicateDate),
            'ngn_rate' => (string) ($effectiveRate['ngn_rate'] ?? ''),
            'usd_rate' => (string) ($effectiveRate['usd_rate'] ?? ''),
            'eur_rate' => (string) ($effectiveRate['eur_rate'] ?? ''),
            'gbp_rate' => (string) ($effectiveRate['gbp_rate'] ?? ''),
        ];
    }

    $nextStmt = $conn->prepare('SELECT COALESCE(MAX(journal_id), 100) + 1 AS next_journal_id FROM journal_table');
    if (!$nextStmt) {
        throw new Exception('Unable to prepare the next journal reference.', 500);
    }
    $nextStmt->execute();
    $nextJournalId = (int) ($nextStmt->get_result()->fetch_assoc()['next_journal_id'] ?? 101);
    $nextStmt->close();

    $journalType = (string) ($header['journal_type'] ?? 'Journal');
    $sourceReference = duplicateJournalVoucherCode($journalType) . '-' . $sourceJournalId;
    $expectedReference = duplicateJournalVoucherCode($journalType) . '-' . $nextJournalId;

    $userId = (int) ($user['id'] ?? 0);
    $userEmail = (string) ($user['email'] ?? '');
    $logAction = "{$userEmail} prepared a duplicate from Journal Voucher #{$sourceJournalId}";
    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if ($logStmt) {
        $logStmt->bind_param('iss', $userId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'A duplicate journal has been prepared for review.',
        'data' => [
            'source_journal_id' => $sourceJournalId,
            'source_reference' => $sourceReference,
            'expected_journal_id' => $nextJournalId,
            'expected_reference' => $expectedReference,
            'reference_is_provisional' => true,
            'journalDetails' => [
                'journal_date' => $duplicateDate,
                'journal_type' => $journalType,
                'journal_currency' => (string) ($header['journal_currency'] ?? 'NGN'),
                'transaction_type' => (string) ($header['transaction_type'] ?? ''),
                'main_journal_description' => (string) ($header['journal_description'] ?? ''),
                'cost_center' => (string) ($header['cost_center'] ?? 'Overhead'),
            ],
            'journalItems' => $items,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Duplicate journal error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($e),
    ]);
}
