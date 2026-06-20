<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/invoice_helpers.php';
require_once 'utils/invoice_catalogue_helpers.php';
require_once 'utils/notification_helpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') { // Changed to PUT for update operations
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can update invoices", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Validation for scalar fields
     */
    $requiredScalarFields = [
        'invoice_number', 'invoice_date', 'clients_name', 'clients_id', 
        'currency', 'due_date', 'tin_number'
    ];

    foreach ($requiredScalarFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Validation for array fields (Line Items)
     * Reference expects arrays for: id, description, amount, discount, vat, wht
     */
    $arrayFields = ['id', 'description', 'amount', 'discount', 'vat', 'wht'];
    foreach ($arrayFields as $field) {
        if (!isset($data[$field]) || !is_array($data[$field]) || empty($data[$field])) {
            throw new Exception("Please ensure that you have added at least one line item with valid {$field}!", 400);
        }
    }

    // Check array counts match
    $count = count($data['id']);
    foreach ($arrayFields as $field) {
        if (count($data[$field]) !== $count) {
            throw new Exception("Mismatch in line item data count for {$field}.", 400);
        }
    }

    $serviceCatalogueIds = isset($data['service_catalogue_id']) && is_array($data['service_catalogue_id'])
        ? $data['service_catalogue_id']
        : array_fill(0, $count, null);
    if (count($serviceCatalogueIds) !== $count) {
        throw new Exception('Mismatch in service catalogue line count.', 400);
    }

    /**
     * Clean inputs
     */
    $invoice_number = trim($data['invoice_number']);
    $invoice_date = trim($data['invoice_date']);
    $clients_name = trim($data['clients_name']);
    $clients_id = trim($data['clients_id']);
    $project = trim($data['project']);
    $currency = trim($data['currency']);
    $due_date = trim($data['due_date']);
    // $status = trim($data['status']);
    $bank_name = trim($data['bank_name']);
    $tin_number = trim($data['tin_number']);
    $draft_uuid = trim((string) ($data['draft_uuid'] ?? ''));
    $payment_terms_days = normalizePaymentTermsDays($data['payment_terms_days'] ?? null);
    $payment_terms_label = paymentTermsLabel($payment_terms_days, $data['payment_terms_label'] ?? null);
    $save_client_preferences = filter_var($data['save_client_preferences'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $bank_id = isset($data['bank_id']) && $data['bank_id'] !== '' ? (int) $data['bank_id'] : null;
    $post_jv = trim((string) ($data['post_jv'] ?? 'No'));
    $client_preferences = is_array($data['client_preferences'] ?? null) ? $data['client_preferences'] : [];

    $invoiceDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $invoice_date);
    $dueDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $due_date);
    if (!$invoiceDateObject || $invoiceDateObject->format('Y-m-d') !== $invoice_date) {
        throw new Exception('Enter a valid invoice date.', 422);
    }
    if (!$dueDateObject || $dueDateObject->format('Y-m-d') !== $due_date) {
        throw new Exception('Enter a valid due date.', 422);
    }
    if ($payment_terms_days !== null) {
        $due_date = $invoiceDateObject->modify("+{$payment_terms_days} days")->format('Y-m-d');
    } elseif ($dueDateObject < $invoiceDateObject) {
        throw new Exception('Due date cannot be earlier than the invoice date.', 422);
    }

    // Bank details logic
    $account_name = "";
    $account_number = "";
    $account_currency = "";

    if ($bank_name !== "" && $bank_name !== "N/A") {
        $account_name = isset($data['account_name']) ? trim($data['account_name']) : '';
        $account_number = isset($data['account_number']) ? trim($data['account_number']) : '';
        $account_currency = isset($data['account_currency']) ? trim($data['account_currency']) : '';
    }else{
        $account_name = "";
        $account_number = "";
        $account_currency = "";
    }

    // Start Transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Check Accounting Period Lock
         */
        $periodStmt = $conn->prepare("SELECT * FROM accounting_periods ORDER BY id DESC LIMIT 1");
        $periodStmt->execute();
        $periodResult = $periodStmt->get_result();
        $periodData = $periodResult->fetch_assoc();
        $periodStmt->close();

        if ($periodData) {
            $end_date = $periodData['end_date'];
            $is_locked = $periodData['is_locked'];

            if ($end_date >= $invoice_date && $is_locked == "Locked") {
                throw new Exception("This accounting period is locked!", 400);
            }
        }

        /**
         * 2. Check if Invoice Exists
         */
        $checkInv = $conn->prepare("SELECT invoice_number, status, workflow_status, currency FROM invoice_table WHERE invoice_number = ?");
        $checkInv->bind_param("s", $invoice_number);
        $checkInv->execute();
        $existingInvoice = $checkInv->get_result()->fetch_assoc();
        if (!$existingInvoice) {
            throw new Exception("Invoice number {$invoice_number} not found.", 404);
        }
        $previousStatus = (string) ($existingInvoice['status'] ?? '');
        $workflowStatus = (string) ($existingInvoice['workflow_status'] ?? 'Issued');
        $existingCurrency = strtoupper((string) ($existingInvoice['currency'] ?? ''));
        $checkInv->close();

        if (in_array($workflowStatus, ['Cancelled', 'Void'], true)) {
            throw new Exception("A {$workflowStatus} invoice cannot be edited. Restore it to Issued first.", 409);
        }

        /**
         * 3. Process Line Items & Update/Insert
         */
        $subtotal = 0;
        $maintotal = 0;
        
        // Prepare statement for Upsert (Insert on Duplicate Key Update)
        // Note: This assumes 'id' is the Primary Key or Unique Key in main_invoice_table
        $stmtItem = $conn->prepare("
            INSERT INTO main_invoice_table 
            (id, invoice_number, service_catalogue_id, clients_name, clients_id, description, amount, discount_percent, vat_percent, wht_percent, discount, vat, wht, total, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                service_catalogue_id = VALUES(service_catalogue_id),
                description = VALUES(description), 
                amount = VALUES(amount), 
                discount_percent = VALUES(discount_percent), 
                vat_percent = VALUES(vat_percent), 
                wht_percent = VALUES(wht_percent), 
                discount = VALUES(discount), 
                vat = VALUES(vat), 
                wht = VALUES(wht), 
                total = VALUES(total), 
                clients_name = VALUES(clients_name), 
                clients_id = VALUES(clients_id),
                updated_by = VALUES(updated_by)
        ");

        for ($i = 0; $i < $count; $i++) {
            $sn = (int) $data['id'][$i]; // ID from input
            $description = trim($data['description'][$i]);
            $amount = (float) $data['amount'][$i];
            $discountPercent = (float) $data['discount'][$i];
            $vatPercent = (float) $data['vat'][$i];
            $whtPercent = (float) $data['wht'][$i];
            $serviceCatalogueId = isset($serviceCatalogueIds[$i]) && $serviceCatalogueIds[$i] !== ''
                ? (int) $serviceCatalogueIds[$i]
                : null;

            if ($serviceCatalogueId !== null && $serviceCatalogueId > 0) {
                $catalogueService = fetchInvoiceServiceById($conn, $serviceCatalogueId);
                if (!$catalogueService) {
                    throw new Exception('A selected reusable service is no longer available.', 422);
                }
                if (strtoupper((string) $catalogueService['currency']) !== strtoupper($currency)) {
                    throw new Exception('Reusable service currency must match the invoice currency.', 422);
                }
            }

            // Validation
            if (empty($description)) {
                throw new Exception("Description on line " . ($i + 1) . " is empty.", 400);
            }
            if ($amount === 0.0 && $data['amount'][$i] !== '0') { // Allow 0 amount explicitly
                 // Basic check if it was supposed to be required
            }

            // Calculations
            $discount_amt = $amount * ($discountPercent / 100);
            $vat_amt = ($amount - $discount_amt) * ($vatPercent / 100);
            $wht_amt = ($amount - $discount_amt) * ($whtPercent / 100);
            
            // Reference logic: Subtotal is running total
            $subtotal = $amount - $discount_amt + $vat_amt;
            $maintotal += $amount - $discount_amt + $vat_amt;


            // Bind parameters
            // id(i), invoice_number(s), clients_name(s), clients_id(s), description(s), amount(d), 
            // disc_pct(d), vat_pct(d), wht_pct(d), disc_amt(d), vat_amt(d), wht_amt(d), total(d), updated_by(s)
            $stmtItem->bind_param(
                "isisisdddddddds", 
                $sn,
                $invoice_number,
                $serviceCatalogueId,
                $clients_name,
                $clients_id,
                $description,
                $amount,
                $discountPercent,
                $vatPercent,
                $whtPercent,
                $discount_amt,
                $vat_amt,
                $wht_amt,
                $subtotal, // Note: Reference uses running subtotal here
                $userEmail
            );

            if (!$stmtItem->execute()) {
                throw new Exception("Error updating line item: " . $stmtItem->error, 500);
            }
        }
        $stmtItem->close();

        $paymentSummary = invoicePaymentSummary($conn, (string) $invoice_number, (float) $maintotal);
        $paid = (float) $paymentSummary['amount_paid'];

        if ($paid > $maintotal + 0.009) {
            throw new Exception(
                'The invoice total cannot be reduced below the amount already received ('
                . number_format($paid, 2) . ' ' . $existingCurrency . '). Reverse or adjust the payment first.',
                409
            );
        }

        if ((int) $paymentSummary['active_payment_count'] > 0 && strtoupper($currency) !== $existingCurrency) {
            throw new Exception('Invoice currency cannot be changed after a payment has been recorded.', 409);
        }

        $status = invoicePaymentStatus((float) $maintotal, $paid, $due_date);

        /**
         * 4. Update Invoice Header
         */
        $stmtInv = $conn->prepare("
            UPDATE invoice_table SET 
                invoice_date = ?,
                invoice_amount = ?,
                clients_name = ?,
                clients_id = ?,
                project = ?,
                currency = ?,
                due_date = ?,
                payment_terms_days = ?,
                payment_terms_label = ?,
                account_name = ?,
                account_number = ?,
                account_currency = ?,
                tin_number = ?,
                paid = ?,
                bank_name = ?,
                status = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE invoice_number = ?
        ");

        $stmtInv->bind_param(
            "sdsisssisssssdssss", 
            $invoice_date,
            $maintotal,
            $clients_name,
            $clients_id,
            $project,
            $currency,
            $due_date,
            $payment_terms_days,
            $payment_terms_label,
            $account_name,
            $account_number,
            $account_currency,
            $tin_number,
            $paid,
            $bank_name,
            $status,
            $userEmail,
            $invoice_number
        );

        if (!$stmtInv->execute()) {
            throw new Exception("Error updating invoice header: " . $stmtInv->error, 500);
        }
        $stmtInv->close();

        if ($previousStatus !== $status) {
            recordInvoiceStatusHistory(
                $conn,
                (string) $invoice_number,
                'payment',
                $previousStatus,
                $status,
                'Payment status recalculated after invoice update.',
                $userData
            );
        }

        if ($save_client_preferences) {
            saveClientInvoicePreferences(
                $conn,
                (int) $clients_id,
                array_merge([
                    'default_currency' => $currency,
                    'payment_terms_days' => $payment_terms_days,
                    'default_bank_id' => $bank_id,
                    'display_tin' => $tin_number,
                    'post_journal_entry' => $post_jv,
                    'default_project' => $project,
                    'default_discount_percent' => $data['discount'][0] ?? 0,
                    'default_vat_percent' => $data['vat'][0] ?? 0,
                    'default_wht_percent' => $data['wht'][0] ?? 0,
                ], $client_preferences),
                $userEmail
            );
        }

        if ($draft_uuid !== '') {
            $draftStmt = $conn->prepare('DELETE FROM invoice_drafts WHERE draft_uuid = ? AND created_by_user_id = ?');
            $draftStmt->bind_param('si', $draft_uuid, $loggedInUserId);
            $draftStmt->execute();
            $draftStmt->close();
        }

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail updated Invoice #$invoice_number";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        notifyAccountingUsers(
            $conn,
            'invoice_updated',
            'invoice',
            "Invoice #{$invoice_number} was updated",
            "{$userEmail} updated the invoice for {$clients_name}, now totalling " . number_format((float) $maintotal, 2) . " {$currency}.",
            'info',
            'invoice',
            (string) $invoice_number,
            '/invoice/view/' . rawurlencode((string) $invoice_number),
            [
                'client_name' => $clients_name,
                'amount' => (float) $maintotal,
                'currency' => $currency,
                'due_date' => $due_date,
            ],
            (int) $loggedInUserId
        );

        // Commit Transaction
        $conn->commit();

        echo json_encode([
            "status" => "Success",
            "message" => "Invoice updated successfully!",
            "data" => [
                "invoice_number" => $invoice_number,
                "total" => $maintotal
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => publicErrorMessage($e)
    ]);
}