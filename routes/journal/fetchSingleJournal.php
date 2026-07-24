<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    /**
     * Validate journal_id input
     */
    if (!isset($_GET['journal_id']) || empty($_GET['journal_id'])) {
        throw new Exception("Missing required parameter: 'journal_id'.", 400);
    }

    $journal_id = (int) $_GET['journal_id'];

    if ($journal_id <= 0) {
        throw new Exception("Invalid 'journal_id' provided.", 400);
    }

    /**
     * 1. Fetch Journal Header
     */
    $stmtHeader = $conn->prepare("
        SELECT 
            id,
            journal_id,
            journal_date,
            journal_type,
            journal_currency,
            transaction_type,
            journal_description,
            debit,
            credit,
            debit_ngn,
            credit_ngn,
            debit_others,
            credit_others,
            cost_center,
            rate_date,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM journal_table 
        WHERE journal_id = ?
    ");

    if (!$stmtHeader) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtHeader->bind_param("i", $journal_id);
    $stmtHeader->execute();
    $resultHeader = $stmtHeader->get_result();
    $headerData = $resultHeader->fetch_assoc();
    $stmtHeader->close();

    if (!$headerData) {
        throw new Exception("Journal with ID {$journal_id} not found.", 404);
    }

    /**
     * 2. Fetch Journal Line Items
     */
    $stmtItems = $conn->prepare("
        SELECT 
            id,
            journal_id,
            journal_date,
            journal_currency,
            transaction_type,
            journal_description,
            debit,
            credit,
            rate,
            rate_date,
            debit_ngn,
            credit_ngn,
            ngn_rate,
            usd_rate,
            eur_rate,
            gbp_rate,
            cost_center,
            ledger_name,
            ledger_number,
            ledger_class,
            ledger_class_code,
            ledger_sub_class,
            ledger_type,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM main_journal_table 
        WHERE journal_id = ?
    ");

    if (!$stmtItems) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmtItems->bind_param("i", $journal_id);
    $stmtItems->execute();
    $resultItems = $stmtItems->get_result();
    
    $items = [];
    while ($row = $resultItems->fetch_assoc()) {
        $items[] = $row;
    }
    $stmtItems->close();

    /**
     * 3. Include any controlled invoice-payment journal relationship.
     */
    $paymentLinkStmt = $conn->prepare(
        "SELECT p.id, p.payment_code, p.invoice_number, p.status, p.post_journal,
                p.journal_id, p.reversal_journal_id, p.journal_origin,
                p.journal_validation_status, p.journal_linked_at,
                p.journal_linked_by_email, p.payment_method,
                p.transaction_reference, p.notes, p.invoice_currency,
                p.invoice_amount_settled, p.payment_currency,
                p.payment_amount_received, p.realized_fx_gain_ngn,
                p.realized_fx_loss_ngn, p.updated_at,
                a.id AS allocation_id, a.allocated_amount,
                a.allocation_currency, i.clients_name,
                (SELECT COUNT(*) FROM invoice_payment_allocations ax WHERE ax.payment_id = p.id) AS allocation_count
         FROM invoice_payments p
         LEFT JOIN invoice_payment_allocations a ON a.payment_id = p.id
         LEFT JOIN invoice_table i ON i.invoice_number = p.invoice_number
         WHERE p.journal_id = ? OR p.reversal_journal_id = ?
         ORDER BY a.id ASC
         LIMIT 1"
    );
    if (!$paymentLinkStmt) {
        throw new Exception('Unable to load the payment-journal relationship.', 500);
    }
    $paymentLinkStmt->bind_param('ii', $journal_id, $journal_id);
    $paymentLinkStmt->execute();
    $paymentLink = $paymentLinkStmt->get_result()->fetch_assoc() ?: null;
    $paymentLinkStmt->close();
    if ($paymentLink) {
        $paymentLink['id'] = (int) $paymentLink['id'];
        $paymentLink['journal_id'] = $paymentLink['journal_id'] !== null ? (int) $paymentLink['journal_id'] : null;
        $paymentLink['reversal_journal_id'] = $paymentLink['reversal_journal_id'] !== null
            ? (int) $paymentLink['reversal_journal_id']
            : null;
        $paymentLink['post_journal'] = (bool) $paymentLink['post_journal'];
        foreach ([
            'invoice_amount_settled',
            'payment_amount_received',
            'realized_fx_gain_ngn',
            'realized_fx_loss_ngn',
            'allocated_amount',
        ] as $amountField) {
            $paymentLink[$amountField] = $paymentLink[$amountField] !== null
                ? (float) $paymentLink[$amountField]
                : null;
        }
        $paymentLink['allocation_id'] = $paymentLink['allocation_id'] !== null
            ? (int) $paymentLink['allocation_id']
            : null;
        $paymentLink['allocation_count'] = (int) ($paymentLink['allocation_count'] ?? 0);
        $paymentLink['invoice_currency'] = strtoupper(trim((string) ($paymentLink['invoice_currency'] ?? '')));
        $paymentLink['payment_currency'] = strtoupper(trim((string) ($paymentLink['payment_currency'] ?? '')));
        $paymentLink['allocation_currency'] = strtoupper(trim((string) ($paymentLink['allocation_currency'] ?? '')));
        $paymentLink['relationship'] = $paymentLink['reversal_journal_id'] === $journal_id
            ? 'Payment Journal Reversal'
            : 'Payment Journal';
        $paymentLink['can_manage'] = $paymentLink['relationship'] === 'Payment Journal'
            && strcasecmp((string) ($paymentLink['status'] ?? ''), 'Active') === 0
            && strcasecmp((string) ($paymentLink['journal_validation_status'] ?? ''), 'Validated') === 0
            && $paymentLink['reversal_journal_id'] === null
            && $paymentLink['allocation_count'] === 1;
    }

    /**
     * 4. Combine Data
     */
    $responseData = $headerData;
    $responseData['items'] = $items;
    $responseData['payment_link'] = $paymentLink;
    $responseData['is_protected'] = $paymentLink !== null;

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Journal fetched successfully",
        "data" => $responseData
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => publicErrorMessage($e)
    ]);
}