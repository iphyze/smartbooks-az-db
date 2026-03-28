<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

/**
 * Helper: fetch ledger by name
 */
function getLedgerDetails($conn, $identifier, $column = 'ledger_name') {
    $sql = "SELECT * FROM ledger_table WHERE $column = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $data   = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // ── Authenticate ──────────────────────────────────────────────────────────
    $userData       = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail      = $userData['email'];
    $userIntegrity  = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can update Journal Vouchers", 401);
    }

    // ── Decode JSON body ──────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ── Validate scalar / header fields ──────────────────────────────────────
    $requiredScalarFields = [
        'journal_id', 'journal_date', 'journal_type', 'journal_currency',
        'transaction_type', 'main_journal_description', 'cost_center',
    ];

    foreach ($requiredScalarFields as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // ── Validate array / line-item fields ────────────────────────────────────
    $arrayFields = [
        'ledger_name', 'amount', 'sides', 'jrate',
        'jcurrency', 'currency_rate', 'journal_description',
    ];

    foreach ($arrayFields as $field) {
        if (!isset($data[$field]) || !is_array($data[$field]) || empty($data[$field])) {
            throw new Exception(
                "Please ensure that you have at least added a line item with valid {$field}!", 400
            );
        }
    }

    $count = count($data['ledger_name']);
    foreach ($arrayFields as $field) {
        if (count($data[$field]) !== $count) {
            throw new Exception("Mismatch in line item data count for {$field}.", 400);
        }
    }

    // ── Clean header inputs ───────────────────────────────────────────────────
    $journal_id               = (int) $data['journal_id'];
    $journal_date             = trim($data['journal_date']);
    $journal_type             = trim($data['journal_type']);
    $journal_currency         = trim($data['journal_currency']);
    $transaction_type         = trim($data['transaction_type']);
    $main_journal_description = trim($data['main_journal_description']);
    $cost_center              = trim($data['cost_center']);

    // ── Grand-total balance check (mirrors create-journal logic) ─────────────
    // Frontend sends pre-calculated NGN totals and grand_total (debit - credit).
    $grand_total      = isset($data['grand_total'])      ? (float) $data['grand_total']      : null;
    $total_debit_ngn  = isset($data['total_debit_ngn'])  ? (float) $data['total_debit_ngn']  : 0;
    $total_credit_ngn = isset($data['total_credit_ngn']) ? (float) $data['total_credit_ngn'] : 0;
    $total_debit_usd  = isset($data['total_debit_usd'])  ? (float) $data['total_debit_usd']  : 0;
    $total_credit_usd = isset($data['total_credit_usd']) ? (float) $data['total_credit_usd'] : 0;

    if ($grand_total === null) {
        throw new Exception("grand_total is required.", 400);
    }

    // Mirror create-journal check: grand_total != 0 || grand_total < 0
    if ($grand_total != 0 || $grand_total < 0) {
        throw new Exception(
            "Grand total must be equal to zero. Please ensure that your total debit equals your total credit!", 400
        );
    }

    // ── Rate consistency check (all jrate values must be identical) ───────────
    $jrateList = $data['jrate'];
    $firstRate = $jrateList[0];
    foreach ($jrateList as $rate) {
        if ($rate != $firstRate) {
            throw new Exception(
                "Rate values are not all the same. Please correct this before continuing!", 400
            );
        }
    }

    // ── Begin DB transaction ──────────────────────────────────────────────────
    $conn->begin_transaction();

    try {

        // 1. Accounting period lock check
        $periodStmt = $conn->prepare("SELECT * FROM accounting_periods ORDER BY id DESC LIMIT 1");
        $periodStmt->execute();
        $periodData = $periodStmt->get_result()->fetch_assoc();
        $periodStmt->close();

        if ($periodData) {
            $end_date  = $periodData['end_date'];
            $is_locked = $periodData['is_locked'];

            if ($end_date >= $journal_date && $is_locked === 'Locked') {
                throw new Exception("This accounting period is locked!", 400);
            }
        }

        // 2. Verify the journal exists
        $checkStmt = $conn->prepare("SELECT journal_id FROM journal_table WHERE journal_id = ?");
        $checkStmt->bind_param("i", $journal_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows === 0) {
            throw new Exception("Journal ID {$journal_id} not found.", 404);
        }
        $checkStmt->close();

        // 3. Collect the IDs of line items sent from the frontend.
        //    Items with a numeric id > 0 are existing rows; id = 0 / null = new rows.
        $incomingIds = [];
        if (isset($data['db_id']) && is_array($data['db_id'])) {
            foreach ($data['db_id'] as $dbId) {
                $parsed = (int) $dbId;
                if ($parsed > 0) {
                    $incomingIds[] = $parsed;
                }
            }
        }

        // 4. Delete line items that belong to this journal but were NOT sent back
        //    (user removed them on the frontend – already confirmed via modal).
        if (!empty($incomingIds)) {
            $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
            $idTypes      = str_repeat('i', count($incomingIds));

            $deleteStmt = $conn->prepare(
                "DELETE FROM main_journal_table
                 WHERE journal_id = ? AND id NOT IN ($placeholders)"
            );
            // Bind: first param is journal_id (i), then each incomingId (i each)
            $deleteStmt->bind_param('i' . $idTypes, $journal_id, ...$incomingIds);
            $deleteStmt->execute();
            $deleteStmt->close();
        } else {
            // No existing rows kept — remove all line items for this journal
            $deleteAllStmt = $conn->prepare(
                "DELETE FROM main_journal_table WHERE journal_id = ?"
            );
            $deleteAllStmt->bind_param("i", $journal_id);
            $deleteAllStmt->execute();
            $deleteAllStmt->close();
        }

        // 5. Upsert line items
        $stmtMainJrnl = $conn->prepare("
            INSERT INTO main_journal_table
                (id, journal_id, journal_type, journal_date, journal_currency, transaction_type,
                 journal_description, debit, credit, rate, rate_date, debit_ngn, credit_ngn,
                 ngn_rate, usd_rate, eur_rate, gbp_rate,
                 cost_center, ledger_name, ledger_number, ledger_class, ledger_class_code,
                 ledger_sub_class, ledger_type, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                journal_id          = VALUES(journal_id),
                journal_type        = VALUES(journal_type),
                journal_currency    = VALUES(journal_currency),
                transaction_type    = VALUES(transaction_type),
                journal_date        = VALUES(journal_date),
                journal_description = VALUES(journal_description),
                debit               = VALUES(debit),
                credit              = VALUES(credit),
                rate                = VALUES(rate),
                rate_date           = VALUES(rate_date),
                debit_ngn           = VALUES(debit_ngn),
                credit_ngn          = VALUES(credit_ngn),
                ngn_rate            = VALUES(ngn_rate),
                usd_rate            = VALUES(usd_rate),
                eur_rate            = VALUES(eur_rate),
                gbp_rate            = VALUES(gbp_rate),
                cost_center         = VALUES(cost_center),
                ledger_name         = VALUES(ledger_name),
                ledger_number       = VALUES(ledger_number),
                ledger_class        = VALUES(ledger_class),
                ledger_class_code   = VALUES(ledger_class_code),
                ledger_sub_class    = VALUES(ledger_sub_class),
                ledger_type         = VALUES(ledger_type),
                updated_by          = VALUES(updated_by)
        ");

        $rate_date = isset($data['rate_date'][0]) ? $data['rate_date'][0] : null;
        $jv_rate   = (float) $data['currency_rate'][0];

        for ($i = 0; $i < $count; $i++) {

            // db_id: 0 or null = new row (MySQL will auto-increment), existing = upsert
            $db_id = (isset($data['db_id'][$i]) && (int)$data['db_id'][$i] > 0)
                     ? (int) $data['db_id'][$i]
                     : null;

            $ledger_name              = trim($data['ledger_name'][$i]);
            $ledger_number            = isset($data['ledger_number'][$i])     ? trim($data['ledger_number'][$i])     : '';
            $ledger_class             = isset($data['ledger_class'][$i])      ? trim($data['ledger_class'][$i])      : '';
            $ledger_class_code        = isset($data['ledger_class_code'][$i]) ? trim($data['ledger_class_code'][$i]) : '';
            $ledger_sub_class         = isset($data['ledger_sub_class'][$i])  ? trim($data['ledger_sub_class'][$i])  : '';
            $ledger_type              = isset($data['ledger_type'][$i])       ? trim($data['ledger_type'][$i])       : '';
            $journal_description_line = trim($data['journal_description'][$i]);

            $amount        = (float) $data['amount'][$i];
            $sides         = trim($data['sides'][$i]);
            $jcurrency     = trim($data['jcurrency'][$i]);
            $currency_rate = (float) $data['currency_rate'][$i];
            $jv_rate = (float) $data['currency_rate'][0];

            $rate_date = isset($data['rate_date'][0]) ? $data['rate_date'][0] : 0;
            $main_rate_date = isset($data['rate_date'][$i]) ? $data['rate_date'][$i] : 0;
            $ngn_rate = isset($data['ngn_rate'][$i]) ? (float) $data['ngn_rate'][$i] : 0;
            $usd_rate = isset($data['usd_rate'][$i]) ? (float) $data['usd_rate'][$i] : 0;
            $eur_rate = isset($data['eur_rate'][$i]) ? (float) $data['eur_rate'][$i] : 0;
            $gbp_rate = isset($data['gbp_rate'][$i]) ? (float) $data['gbp_rate'][$i] : 0;

            // ── Per-row validations (mirror create-journal) ───────────────────
            if (empty($sides) || !in_array($sides, ['Debit', 'Credit'])) {
                throw new Exception("Invalid side value on line " . ($i + 1) . ".", 400);
            }

            if (empty($jcurrency)) {
                throw new Exception(
                    "Please ensure that all currency fields are selected on line " . ($i + 1) . ".", 400
                );
            }

            if (empty($currency_rate) || $currency_rate == 0) {
                throw new Exception(
                    "Please ensure that all currency rate fields are selected on line " . ($i + 1) . ".", 400
                );
            }

            if (empty($amount) || $amount == 0) {
                throw new Exception(
                    "Please ensure that all amount fields are filled and non-zero on line " . ($i + 1) . ".", 400
                );
            }

            if (empty($journal_description_line)) {
                throw new Exception(
                    "Please ensure that all journal descriptions are filled on line " . ($i + 1) . ".", 400
                );
            }

            // Verify ledger exists
            $ledgerData = getLedgerDetails($conn, $ledger_name);
            if (!$ledgerData) {
                throw new Exception("{$ledger_name} does not exist in the database!", 404);
            }

            // Split debit / credit
            $debit  = ($sides === 'Debit')  ? $amount : 0;
            $credit = ($sides === 'Credit') ? $amount : 0;

            // NGN equivalents per row
            $debit_rate  = $debit  * $currency_rate;
            $credit_rate = $credit * $currency_rate;

            $stmtMainJrnl->bind_param(
                "iisssssddddsdddddsssssssss",
                $db_id,
                $journal_id,
                $journal_type,
                $journal_date,
                $jcurrency,
                $transaction_type,
                $journal_description_line,
                $debit,
                $credit,
                $currency_rate,
                $main_rate_date,
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
                $userEmail,
                $userEmail
            );

            if (!$stmtMainJrnl->execute()) {
                throw new Exception("Error upserting journal line item: " . $stmtMainJrnl->error, 500);
            }
        }

        $stmtMainJrnl->close();

        // 6. Update journal header (mirrors create-journal payload structure)
        $stmtJrnl = $conn->prepare("
            UPDATE journal_table SET
                journal_date        = ?,
                journal_type        = ?,
                journal_currency    = ?,
                transaction_type    = ?,
                journal_description = ?,
                debit               = ?,
                credit              = ?,
                rate_date           = ?,
                rate                = ?,
                debit_ngn           = ?,
                credit_ngn          = ?,
                debit_others        = ?,
                credit_others       = ?,
                cost_center         = ?,
                updated_by          = ?
            WHERE journal_id = ?
        ");

        $stmtJrnl->bind_param(
            "sssssddsdddddssi",
            $journal_date,
            $journal_type,
            $journal_currency,
            $transaction_type,
            $main_journal_description,
            $total_debit_ngn,
            $total_credit_ngn,
            $rate_date,
            $jv_rate,
            $total_debit_ngn,
            $total_credit_ngn,
            $total_debit_usd,
            $total_credit_usd,
            $cost_center,
            $userEmail,
            $journal_id
        );

        if (!$stmtJrnl->execute()) {
            throw new Exception("Error updating journal header: " . $stmtJrnl->error, 500);
        }
        $stmtJrnl->close();

        // 7. Log the action
        $logStmt   = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "{$userEmail} updated Journal Voucher #{$journal_id}";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Journal Voucher updated successfully!",
            "data"    => [
                "journal_id"   => $journal_id,
                "total_debit"  => $total_debit_ngn,
                "total_credit" => $total_credit_ngn,
            ],
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage(),
    ]);
}