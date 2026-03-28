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
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can update journals", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Validation for scalar fields (Header Data)
     */
    $requiredScalarFields = [
        'journal_id', 'journal_date', 'journal_type', 'journal_currency', 
        'transaction_type', 'main_journal_description', 'cost_center'
    ];

    foreach ($requiredScalarFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Validation for array fields (Line Items)
     */
    $arrayFields = ['id', 'ledger_name', 'amount', 'sides', 'jrate', 'jcurrency', 'currency_rate', 'journal_description'];
    foreach ($arrayFields as $field) {
        if (!isset($data[$field]) || !is_array($data[$field]) || empty($data[$field])) {
            throw new Exception("Please ensure that you have at least added a line item with valid {$field}!", 400);
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
     * Clean Header Inputs
     */
    $journal_id = (int) $data['journal_id'];
    $journal_date = trim($data['journal_date']);
    $journal_type = trim($data['journal_type']);
    $journal_currency = trim($data['journal_currency']);
    $transaction_type = trim($data['transaction_type']);
    $main_journal_description = trim($data['main_journal_description']);
    $cost_center = trim($data['cost_center']);

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

            if ($end_date >= $journal_date && $is_locked == "Locked") {
                throw new Exception("This accounting period is locked!", 400);
            }
        }

        /**
         * 2. Check if Journal Exists
         */
        $checkJrnl = $conn->prepare("SELECT journal_id FROM journal_table WHERE journal_id = ?");
        $checkJrnl->bind_param("i", $journal_id);
        $checkJrnl->execute();
        $res = $checkJrnl->get_result();
        if ($res->num_rows === 0) {
            throw new Exception("Journal ID {$journal_id} not found.", 404);
        }
        $checkJrnl->close();

        /**
         * 3. Validations & Totals Calculation
         */
        $calculated_total_debit = 0;
        $calculated_total_credit = 0;

        // Check if all rates are the same
        $jrateList = $data['jrate'];
        $firstRate = $jrateList[0];
        foreach ($jrateList as $rate) {
            if ($rate != $firstRate) {
                throw new Exception("Rate values are not all the same. Please correct this before continuing.", 400);
            }
        }

        /**
         * 4. Process Line Items & Update/Insert
         */
        $stmtItem = $conn->prepare("
            INSERT INTO main_journal_table 
            (id, journal_id, journal_type, journal_date, journal_currency, transaction_type, journal_description, 
            debit, credit, rate, debit_ngn, credit_ngn, ngn_rate, usd_rate, eur_rate, gbp_rate, 
            cost_center, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type, 
            updated_by, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                journal_id = VALUES(journal_id), 
                journal_type = VALUES(journal_type), 
                journal_currency = VALUES(journal_currency), 
                transaction_type = VALUES(transaction_type), 
                journal_date = VALUES(journal_date), 
                journal_description = VALUES(journal_description), 
                debit = VALUES(debit), 
                credit = VALUES(credit), 
                rate = VALUES(rate), 
                debit_ngn = VALUES(debit_ngn), 
                credit_ngn = VALUES(credit_ngn), 
                ngn_rate = VALUES(ngn_rate), 
                usd_rate = VALUES(usd_rate), 
                eur_rate = VALUES(eur_rate), 
                gbp_rate = VALUES(gbp_rate), 
                cost_center = VALUES(cost_center), 
                ledger_name = VALUES(ledger_name), 
                ledger_number = VALUES(ledger_number), 
                ledger_class = VALUES(ledger_class), 
                ledger_class_code = VALUES(ledger_class_code), 
                ledger_sub_class = VALUES(ledger_sub_class), 
                ledger_type = VALUES(ledger_type), 
                updated_by = VALUES(updated_by)
        ");

        for ($i = 0; $i < $count; $i++) {
            $sn = (int) $data['id'][$i]; // ID from input
            $ledger_name = trim($data['ledger_name'][$i]);
            $ledger_number = isset($data['ledger_number'][$i]) ? trim($data['ledger_number'][$i]) : '';
            $ledger_class = isset($data['ledger_class'][$i]) ? trim($data['ledger_class'][$i]) : '';
            $ledger_class_code = isset($data['ledger_class_code'][$i]) ? trim($data['ledger_class_code'][$i]) : '';
            $ledger_sub_class = isset($data['ledger_sub_class'][$i]) ? trim($data['ledger_sub_class'][$i]) : '';
            $ledger_type = isset($data['ledger_type'][$i]) ? trim($data['ledger_type'][$i]) : '';
            $journal_description_line = trim($data['journal_description'][$i]);
            
            $amount = (float) $data['amount'][$i];
            $sides = trim($data['sides'][$i]);
            $jcurrency = trim($data['jcurrency'][$i]);
            $currency_rate = (float) $data['currency_rate'][$i];
            
            // Daily rates
            $ngn_rate = isset($data['ngn_rate'][$i]) ? (float)$data['ngn_rate'][$i] : 0;
            $usd_rate = isset($data['usd_rate'][$i]) ? (float)$data['usd_rate'][$i] : 0;
            $eur_rate = isset($data['eur_rate'][$i]) ? (float)$data['eur_rate'][$i] : 0;
            $gbp_rate = isset($data['gbp_rate'][$i]) ? (float)$data['gbp_rate'][$i] : 0;

            // Validation
            if (empty($sides) || !in_array($sides, ['Debit', 'Credit'])) {
                throw new Exception("Invalid side value on line " . ($i + 1) . ".", 400);
            }
            if (empty($amount)) {
                throw new Exception("Amount cannot be empty on line " . ($i + 1) . ".", 400);
            }

            // Check Ledger Existence
            $ledgerData = getLedgerDetails($conn, $ledger_name);
            if (!$ledgerData) {
                throw new Exception("$ledger_name does not exist in the database!", 404);
            }

            // Determine Debit/Credit
            $debit = 0;
            $credit = 0;
            
            if ($sides == "Debit") {
                $debit = $amount;
                $calculated_total_debit += $amount;
            } else {
                $credit = $amount;
                $calculated_total_credit += $amount;
            }

            $debit_rate = $debit * $currency_rate;
            $credit_rate = $credit * $currency_rate;

            // Bind parameters
            $stmtItem->bind_param(
                "iissssssddddddddsssssssss", // 25 types
                $sn,
                $journal_id,
                $journal_type,
                $journal_date,
                $jcurrency,
                $transaction_type,
                $journal_description_line,
                $debit,
                $credit,
                $currency_rate,
                $debit_rate,
                $credit_rate,
                $ngn_rate,
                $usd_rate,
                $eur_rate,
                $gbp_rate,
                $cost_center,
                $ledger_name,
                $ledger_number,
                $ledger_class,
                $ledger_class_code,
                $ledger_sub_class,
                $ledger_type,
                $userEmail, // updated_by
                $userEmail  // created_by (for insert)
            );

            if (!$stmtItem->execute()) {
                throw new Exception("Error updating line item: " . $stmtItem->error, 500);
            }
        }
        $stmtItem->close();

        /**
         * 5. Validate Balanced Entry
         */
        if ($calculated_total_debit != $calculated_total_credit) {
            throw new Exception("Unbalanced Entry: Total Debit ($calculated_total_debit) does not equal Total Credit ($calculated_total_credit).", 400);
        }

        /**
         * 6. Update Journal Header
         */
        $stmtJrnl = $conn->prepare("
            UPDATE journal_table SET 
                journal_date = ?,
                journal_type = ?,
                journal_currency = ?,
                transaction_type = ?,
                journal_description = ?,
                debit = ?,
                credit = ?,
                debit_ngn = ?,
                credit_ngn = ?,
                cost_center = ?,
                updated_by = ?
            WHERE journal_id = ?
        ");

        $stmtJrnl->bind_param(
            "sssssddddssi", 
            $journal_date,
            $journal_type,
            $journal_currency,
            $transaction_type,
            $main_journal_description,
            $calculated_total_debit, // debit
            $calculated_total_credit, // credit
            $calculated_total_debit, // debit_ngn (assuming NGN base for simplicity as per reference)
            $calculated_total_credit, // credit_ngn
            $cost_center,
            $userEmail,
            $journal_id
        );

        if (!$stmtJrnl->execute()) {
            throw new Exception("Error updating journal header: " . $stmtJrnl->error, 500);
        }
        $stmtJrnl->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail updated Journal Voucher #$journal_id";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        echo json_encode([
            "status" => "Success",
            "message" => "Journal Voucher updated successfully!",
            "data" => [
                "journal_id" => $journal_id,
                "total_debit" => $calculated_total_debit,
                "total_credit" => $calculated_total_credit
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