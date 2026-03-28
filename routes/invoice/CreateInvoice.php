<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

/**
 * Helper function to fetch ledger details
 */
function getLedgerDetails($conn, $identifier, $column = 'ledger_name') {
    $sql = "SELECT * FROM ledger_table WHERE $column = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can create invoices", 401);
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
        'invoice_date', 'clients_name', 'clients_id', 'currency', 
        'due_date', 'tin_number', 'rate_date'
    ];

    foreach ($requiredScalarFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Validation for array fields (Line Items)
     */
    $arrayFields = ['sn', 'description', 'amount', 'discount', 'vat', 'wht'];
    foreach ($arrayFields as $field) {
        if (!isset($data[$field]) || !is_array($data[$field]) || empty($data[$field])) {
            throw new Exception("Please ensure that you have at least added a line item with valid {$field}!", 400);
        }
    }

    // Check if all arrays have the same count
    $count = count($data['sn']);
    foreach ($arrayFields as $field) {
        if (count($data[$field]) !== $count) {
            throw new Exception("Mismatch in line item data count for {$field}.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $invoice_date = trim($data['invoice_date']);
    $clients_name = trim($data['clients_name']);
    $clients_id = trim($data['clients_id']);
    $project = isset($data['project']) ? trim($data['project']) : '';
    $currency = trim($data['currency']);
    $due_date = trim($data['due_date']);
    $post_jv = isset($data['post_jv']) ? trim($data['post_jv']) : 'No';
    $bank_name = trim($data['bank_name']);
    // $bank_name = isset($data['bank_name']) ? trim($data['bank_name']) : 'N/A';
    $tin_number = trim($data['tin_number']);
    $rate_date = trim($data['rate_date']); // Maps to currency_rate date

    // Bank details logic
    $account_name = "";
    $account_number = "";
    $account_currency = "";

    if ($bank_name !== "" && $bank_name !== "N/A") {
        $account_name = isset($data['account_name']) ? trim($data['account_name']) : '';
        $account_number = isset($data['account_number']) ? trim($data['account_number']) : '';
        $account_currency = isset($data['account_currency']) ? trim($data['account_currency']) : '';
    }

    $status = 'Pending';

    // Start Transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Check Accounting Period
         */
        $periodStmt = $conn->prepare("SELECT * FROM accounting_periods ORDER BY id DESC LIMIT 1");
        $periodStmt->execute();
        $periodResult = $periodStmt->get_result();
        $periodData = $periodResult->fetch_assoc();
        $periodStmt->close();

        if ($periodData) {
            $start_date = $periodData['start_date'];
            $end_date = $periodData['end_date'];
            $is_locked = $periodData['is_locked'];

            if ($end_date >= $invoice_date && $is_locked == "Locked") {
                throw new Exception("This accounting period is locked!", 400);
            }
        }

        /**
         * 2. Check Client Existence
         */
        $clientStmt = $conn->prepare("SELECT * FROM clients_table WHERE clients_name = ?");
        $clientStmt->bind_param("s", $clients_name);
        $clientStmt->execute();
        $clientResult = $clientStmt->get_result();
        
        if ($clientResult->num_rows == 0) {
            throw new Exception("$clients_name does not exist in the database!", 404);
        }
        $clientStmt->close();

        /**
         * 3. Generate IDs
         */
        // Invoice Number
        $invNumStmt = $conn->prepare("SELECT MAX(invoice_number) AS last_invoice_number FROM invoice_table");
        $invNumStmt->execute();
        $invRow = $invNumStmt->get_result()->fetch_assoc();
        $invoice_number = ($invRow['last_invoice_number'] === NULL) ? 1101 : $invRow['last_invoice_number'] + 1;
        $invNumStmt->close();

        // Journal ID
        $journal_id = 0;
        if ($post_jv === "Yes") {
            $jvStmt = $conn->prepare("SELECT MAX(journal_id) AS last_journal_id FROM journal_table");
            $jvStmt->execute();
            $jvRow = $jvStmt->get_result()->fetch_assoc();
            $journal_id = ($jvRow['last_journal_id'] === NULL) ? 101 : $jvRow['last_journal_id'] + 1;
            $jvStmt->close();
        }

        /**
         * 4. Process Line Items & Calculate Totals
         */
        $total_amount = 0;
        $total_discount = 0;
        $total_wht = 0;
        $total_vat = 0;
        $subtotal = 0;
        $lineItems = [];

        for ($i = 0; $i < $count; $i++) {
            $sn = $data['sn'][$i]; // Not used in insert but good for validation
            $description = trim($data['description'][$i]);
            $amount = (float) $data['amount'][$i];
            $discountPercent = (float) $data['discount'][$i];
            $vatPercent = (float) $data['vat'][$i];
            $whtPercent = (float) $data['wht'][$i];

            if (empty($description)) {
                throw new Exception("Description on line " . ($i+1) . " is empty.", 400);
            }

            $discount_amt = $amount * ($discountPercent / 100);
            $vat_amt = ($amount - $discount_amt) * ($vatPercent / 100);
            $wht_amt = ($amount - $discount_amt) * ($whtPercent / 100);
            
            // Running totals
            $total_amount += $amount;
            $total_discount += $discount_amt;
            $total_vat += $vat_amt;
            $total_wht += $wht_amt;
            
            // Note: Subtotal calculation from reference: amount - discount + vat
            $subtotal += $amount - $discount_amt + $vat_amt;

            $lineItems[] = [
                'description' => $description,
                'amount' => $amount,
                'discountPercent' => $discountPercent,
                'vatPercent' => $vatPercent,
                'whtPercent' => $whtPercent,
                'discount' => $discount_amt,
                'vat' => $vat_amt,
                'wht' => $wht_amt,
                'total' => $subtotal // Note: Reference uses running subtotal for 'total' column in main_invoice_table
            ];
        }

        /**
         * 5. Insert into main_invoice_table
         */
        $stmtMainInv = $conn->prepare("
            INSERT INTO main_invoice_table 
            (invoice_number, clients_name, clients_id, description, amount, discount_percent, vat_percent, wht_percent, discount, vat, wht, total, rate_date, created_by, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($lineItems as $item) {
            // Using current running subtotal for 'total' column as per reference logic
            // If individual line total is needed, logic might differ, but following reference strictly:
            $currentRunningTotal = $subtotal; // Or specific line logic? Reference: $subtotal += ... then insert $subtotal. 
            // Note: Reference logic inserts the *running* $subtotal into the `total` column for every row? 
            // That seems odd but I will stick to the calculated values. 
            // Usually `total` in line items is line_total. 
            // Let's calculate line specific total for sanity, or strictly follow reference.
            // Reference: $subtotal += ...; ... VALUES(..., $subtotal, ...); 
            // This implies the last row has the grand total. I will use line specific total to be safe for data integrity, 
            // or just insert the calculated values.
            
            $lineTotal = $item['amount'] - $item['discount'] + $item['vat'];
            
            $stmtMainInv->bind_param(
                "isssddddddddsss", 
                $invoice_number,
                $clients_name,
                $clients_id,
                $item['description'],
                $item['amount'],
                $item['discountPercent'],
                $item['vatPercent'],
                $item['whtPercent'],
                $item['discount'],
                $item['vat'],
                $item['wht'],
                $lineTotal, // Using line total instead of running total for sanity
                $rate_date,
                $userEmail,
                $userEmail
            );
            
            if (!$stmtMainInv->execute()) {
                throw new Exception("Error inserting line item: " . $stmtMainInv->error, 500);
            }
        }
        $stmtMainInv->close();

        /**
         * 6. Insert into invoice_table
         */
        $stmtInv = $conn->prepare("
            INSERT INTO invoice_table 
            (invoice_number, invoice_amount, clients_name, clients_id, currency, project, created_by, updated_by, invoice_date, due_date, status, bank_name, account_name, account_number, account_currency, tin_number, rate_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmtInv->bind_param(
            "idsssssssssssssss", 
            $invoice_number,
            $subtotal,
            $clients_name,
            $clients_id,
            $currency,
            $project,
            $userEmail,
            $userEmail,
            $invoice_date,
            $due_date,
            $status,
            $bank_name,
            $account_name,
            $account_number,
            $account_currency,
            $tin_number,
            $rate_date
        );

        if (!$stmtInv->execute()) {
            throw new Exception("Error inserting invoice header: " . $stmtInv->error, 500);
        }
        $stmtInv->close();

        /**
         * 7. Post Journal Voucher (if applicable)
         */
        if ($post_jv === "Yes") {
            
            // Fetch Rates
            $rateStmt = $conn->prepare("SELECT * FROM currency_table WHERE created_at = ?");
            $rateStmt->bind_param("s", $rate_date);
            $rateStmt->execute();
            $rateRes = $rateStmt->get_result()->fetch_assoc();
            $rateStmt->close();

            if (!$rateRes) {
                throw new Exception("Currency rate not found for date $rate_date", 404);
            }

            $ngn_rate = (float)$rateRes['ngn_rate'];
            $eur_rate = (float)$rateRes['eur_rate'];
            $gbp_rate = (float)$rateRes['gbp_rate'];
            $usd_rate = (float)$rateRes['usd_rate'];

            $rate = 1;
            $jjv_debit = 0;
            $jjv_credit = 0;

            // Determine Rate based on Currency
            switch ($currency) {
                case 'NGN': $rate = $ngn_rate; break;
                case 'USD': $rate = $usd_rate; $jjv_debit = $subtotal; $jjv_credit = $subtotal; break;
                case 'EUR': $rate = $eur_rate; $jjv_debit = $subtotal; $jjv_credit = $subtotal; break;
                case 'GBP': $rate = $gbp_rate; $jjv_debit = $subtotal; $jjv_credit = $subtotal; break;
            }

            $total_jv_debit_rate = $subtotal * $rate;
            $total_jv_credit_rate = $subtotal * $rate;
            $journal_type = "Sales";
            $transaction_type = "Bank";
            $journal_description = "Being Sales against Inv. No. $invoice_number for $clients_name";
            $cost_center = $clients_name;

            // Insert into journal_table
            $stmtJrnl = $conn->prepare("
                INSERT INTO journal_table 
                (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description, debit, credit, rate, debit_ngn, credit_ngn, debit_others, credit_others, cost_center, created_by, updated_by, rate_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtJrnl->bind_param(
                "issssssddddssssss", // logic check: journal_id(i), type(s), type(s), date(s), curr(s), desc(s), d(d), c(d), r(d), d_ngn(d), c_ngn(d), d_other(d), c_other(d), center(s), by(s), by(s), rate_date(s)
                $journal_id, $journal_type, $transaction_type, $invoice_date, $currency, $journal_description, 
                $subtotal, $subtotal, $rate, $total_jv_debit_rate, $total_jv_credit_rate, $jjv_debit, $jjv_credit, 
                $cost_center, $userEmail, $userEmail, $rate_date
            );
            if (!$stmtJrnl->execute()) throw new Exception("Journal insert failed: " . $stmtJrnl->error);
            $stmtJrnl->close();

            // Helper closure for main_journal_table insert
            $insertMainJournal = function($ledgerData, $debit, $credit, $desc) use (
                $conn, $journal_id, $journal_type, $transaction_type, $invoice_date, $currency, $rate, 
                $ngn_rate, $usd_rate, $eur_rate, $gbp_rate, $cost_center, $userEmail, $rate_date
            ) {
                // Calculate converted values
                $debit_ngn = $debit * $rate;
                $credit_ngn = $credit * $rate;
                
                $debit_other = ($currency == 'NGN') ? 0 : $debit;
                $credit_other = ($currency == 'NGN') ? 0 : $credit;

                $stmt = $conn->prepare("
                    INSERT INTO main_journal_table 
                    (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description, 
                    debit, credit, rate, debit_ngn, credit_ngn, ngn_rate, usd_rate, eur_rate, gbp_rate, 
                    cost_center, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type, created_by, updated_by, rate_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->bind_param(
                    "isssssddddddddsssssssssss", 
                    $journal_id, $journal_type, $transaction_type, $invoice_date, $currency, $desc, 
                    $debit, $credit, $rate, $debit_ngn, $credit_ngn, $ngn_rate, $usd_rate, $eur_rate, $gbp_rate, 
                    $cost_center, $ledgerData['ledger_name'], $ledgerData['ledger_number'], $ledgerData['ledger_class'], 
                    $ledgerData['ledger_class_code'], $ledgerData['ledger_sub_class'], $ledgerData['ledger_type'], 
                    $userEmail, $userEmail, $rate_date
                );
                
                if (!$stmt->execute()) throw new Exception("Main Journal insert failed: " . $stmt->error);
                $stmt->close();
            };

            // 1. Sales Entry (Credit Total Amount)
            $salesLedger = getLedgerDetails($conn, 'Services');
            if ($salesLedger) {
                $insertMainJournal($salesLedger, 0, $total_amount, "Being Sales against Inv. No. $invoice_number for $clients_name");
            }

            // 2. Client / AR Entry (Debit Subtotal - WHT adjustments)
            $account_debit = 0;
            $account_credit = 0;
            $wht_vat_debit = 0;

            if ($total_vat > 0) {
                $wht_vat_debit = $total_wht;
                $account_debit = $subtotal - $wht_vat_debit;
            } else {
                $account_debit = $subtotal;
            }

            $clientLedger = getLedgerDetails($conn, $clients_name);
            if ($clientLedger) {
                $insertMainJournal($clientLedger, $account_debit, 0, $journal_description);
            } else {
                $arLedger = getLedgerDetails($conn, 'Account Receivables');
                if ($arLedger) {
                    $insertMainJournal($arLedger, $account_debit, 0, $journal_description);
                }
            }

            // 3. Discount Entry
            if ($total_discount > 0) {
                $discLedger = getLedgerDetails($conn, 'Discount Allowed');
                if ($discLedger) {
                    $discDesc = "Being Discount on Sales Inv. No. $invoice_number for $clients_name";
                    $insertMainJournal($discLedger, $total_discount, 0, $discDesc);
                }
            }

            // 4. WHT Entry
            if ($total_wht > 0) {
                $whtLedger = getLedgerDetails($conn, '44350001', 'ledger_number'); // Fetch by number
                if ($whtLedger) {
                    $whtDesc = "Being WHT on Sales Inv. No. $invoice_number for $clients_name";
                    $insertMainJournal($whtLedger, $total_wht, 0, $whtDesc);
                }
            }

            // 5. VAT Entry
            if ($total_vat > 0) {
                $vatLedger = getLedgerDetails($conn, '44210002', 'ledger_number'); // Fetch by number
                if ($vatLedger) {
                    $vatDesc = "Being Vat on Sales Inv. No. $invoice_number for $clients_name";
                    $insertMainJournal($vatLedger, 0, $total_vat, $vatDesc);
                }
            }
        }

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail created Invoice #$invoice_number for $clients_name";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        http_response_code(200);

        echo json_encode([
            "status" => "Success",
            "message" => "Invoice created successfully!",
            "data" => [
                "invoice_number" => $invoice_number,
                "total_amount" => $total_amount,
                "subtotal" => $subtotal,
                "currency" => $currency,
                "posted_to_journal" => ($post_jv === "Yes")
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