<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/invoice_payment_journal_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can prepare invoice payment journals.'
);

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
$bankId = (int) ($_GET['bank_id'] ?? 0);
$paymentDate = trim((string) ($_GET['payment_date'] ?? date('Y-m-d')));
if ($invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required.', 422);
}
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
if (!$date || $date->format('Y-m-d') !== $paymentDate) {
    throw new RuntimeException('Enter a valid payment date.', 422);
}

$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
$customerLedger = invoicePaymentCustomerLedger($conn, (string) ($invoice['clients_name'] ?? ''));
$rateData = invoicePaymentCurrencyRate($conn, (string) ($invoice['currency'] ?? ''), $paymentDate);

$bankAccount = null;
$accountNumber = '';
if ($bankId > 0) {
    $bankStmt = $conn->prepare(
        'SELECT id, bank_name, account_name, account_number, account_currency
         FROM bank_table
         WHERE id = ?
         LIMIT 1'
    );
    if (!$bankStmt) {
        throw new RuntimeException('Unable to load the selected bank account.', 500);
    }
    $bankStmt->bind_param('i', $bankId);
    $bankStmt->execute();
    $bankAccount = $bankStmt->get_result()->fetch_assoc() ?: null;
    $bankStmt->close();
    if ($bankAccount) {
        $accountNumber = trim((string) $bankAccount['account_number']);
    }
}

$sql = "SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
        FROM ledger_table
        ORDER BY ledger_number ASC
        LIMIT 500";
$result = $conn->query($sql);
$allLedgers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$bankLedgers = $allLedgers;

$suggestedBankLedger = null;
if ($accountNumber !== '') {
    foreach ($bankLedgers as $ledger) {
        if (stripos((string) $ledger['ledger_name'], $accountNumber) !== false) {
            $suggestedBankLedger = $ledger;
            break;
        }
    }
}

jsonResponse([
    'status' => 'Success',
    'data' => [
        'customer_ledger' => $customerLedger,
        'suggested_credit_ledger' => $customerLedger,
        'bank_account' => $bankAccount,
        'bank_ledgers' => $bankLedgers,
        'credit_ledgers' => $allLedgers,
        'suggested_bank_ledger' => $suggestedBankLedger,
        'rate' => $rateData,
    ],
]);
