<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

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
        'currency', 'due_date', 'status', 'tin_number'
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
    $status = trim($data['status']);
    $bank_name = trim($data['bank_name']);
    $tin_number = trim($data['tin_number']);

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
        $checkInv = $conn->prepare("SELECT invoice_number FROM invoice_table WHERE invoice_number = ?");
        $checkInv->bind_param("s", $invoice_number);
        $checkInv->execute();
        $res = $checkInv->get_result();
        if ($res->num_rows === 0) {
            throw new Exception("Invoice number {$invoice_number} not found.", 404);
        }
        $checkInv->close();

        /**
         * 3. Process Line Items & Update/Insert
         */
        $subtotal = 0;
        $maintotal = 0;
        
        // Prepare statement for Upsert (Insert on Duplicate Key Update)
        // Note: This assumes 'id' is the Primary Key or Unique Key in main_invoice_table
        $stmtItem = $conn->prepare("
            INSERT INTO main_invoice_table 
            (id, invoice_number, clients_name, clients_id, description, amount, discount_percent, vat_percent, wht_percent, discount, vat, wht, total, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
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
                "issssdddddddds", 
                $sn,
                $invoice_number,
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
                account_name = ?,
                account_number = ?,
                account_currency = ?,
                tin_number = ?,
                bank_name = ?,
                status = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE invoice_number = ?
        ");

        $stmtInv->bind_param(
            "sdsssssssssssss", 
            $invoice_date,
            $subtotal,
            $clients_name,
            $clients_id,
            $project,
            $currency,
            $due_date,
            $account_name,
            $account_number,
            $account_currency,
            $tin_number,
            $bank_name,
            $status,
            $userEmail,
            $invoice_number
        );

        if (!$stmtInv->execute()) {
            throw new Exception("Error updating invoice header: " . $stmtInv->error, 500);
        }
        $stmtInv->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail updated Invoice #$invoice_number";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

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
        "message" => $e->getMessage()
    ]);
}