<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────
    $userData       = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail      = $userData['email'];
    $userIntegrity  = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can create ledgers", 401);
    }

    // ── Parse JSON body ───────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ── Validate ──────────────────────────────────────────────────────────────
    if (empty($data['ledger_name'])) {
        throw new Exception("Please ensure that the ledger name is filled!", 400);
    }
    if (empty($data['account_type'])) {
        throw new Exception("Please ensure that the account type is selected!", 400);
    }

    $ledger_name  = trim($data['ledger_name']);
    $account_type = trim($data['account_type']);
    $created_by   = $userEmail;
    $updated_by   = $userEmail;

    // ── Transaction ───────────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {

        // ── 1. Fetch account details ──────────────────────────────────────────
        //
        // account_table gives us all the metadata for the chosen type:
        //   category_id  → ledger_class_code  (e.g. 65000000 for Finance Cost)
        //   category     → ledger_class        (e.g. "Expense")
        //   sub_category → ledger_sub_class    (e.g. "Finance Cost")
        //   type         → ledger_type         (e.g. "Finance Cost")

        $stmtAccount = $conn->prepare("
            SELECT category_id, category, sub_category, type
            FROM account_table
            WHERE type = ?
            LIMIT 1
        ");
        if (!$stmtAccount) throw new Exception("DB Error (account lookup): " . $conn->error, 500);

        $stmtAccount->bind_param("s", $account_type);
        $stmtAccount->execute();
        $accountData = $stmtAccount->get_result()->fetch_assoc();
        $stmtAccount->close();

        if (!$accountData) {
            throw new Exception("Account type '$account_type' not found in account_table.", 404);
        }

        $category_id  = (int) $accountData['category_id'];  // ledger_class_code, e.g. 65000000
        $category     = $accountData['category'];            // ledger_class,      e.g. "Expense"
        $sub_category = $accountData['sub_category'];        // ledger_sub_class,  e.g. "Finance Cost"
        $type         = $accountData['type'];                // ledger_type,       e.g. "Finance Cost"

        // ── 2. Check duplicate ledger name ────────────────────────────────────
        $stmtCheck = $conn->prepare("
            SELECT id FROM ledger_table WHERE ledger_name = ? LIMIT 1
        ");
        if (!$stmtCheck) throw new Exception("DB Error (dup check): " . $conn->error, 500);

        $stmtCheck->bind_param("s", $ledger_name);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if ($resCheck->num_rows > 0) {
            throw new Exception("Ledger name '$ledger_name' already exists!", 400);
        }
        $stmtCheck->close();

        // ── 3. Generate ledger number ─────────────────────────────────────────
        //
        // FIX: scope MAX() to ledger_class_code, NOT ledger_class.
        //
        // WHY THE OLD CODE WAS WRONG:
        //   The old query used WHERE ledger_class = ? (e.g. "Expense").
        //   That finds the highest ledger_number across ALL Expense ledgers —
        //   e.g. 69000004 (Exchange Loss) — and increments it, producing
        //   69000005 for a Finance Cost ledger that should be 65000003.
        //
        // WHY THIS IS CORRECT:
        //   ledger_class_code is the parent account code from account_table
        //   (e.g. 65000000 for Finance Cost, 69000000 for Income & Other Taxes).
        //   All ledgers under a given account type share the same class_code
        //   and their ledger_numbers are sequential within that code's range
        //   (65000001, 65000002, 65000003 …).
        //   Scoping MAX() to ledger_class_code therefore finds the highest
        //   number within the correct group and increments it by 1.
        //
        // FALLBACK:
        //   If no ledger exists yet for this class_code, seed from class_code + 1
        //   (e.g. Finance Cost 65000000 → first ledger is 65000001).
        //   This matches the existing data exactly.

        $stmtMax = $conn->prepare("
            SELECT MAX(ledger_number) AS max_ledger_number
            FROM ledger_table
            WHERE ledger_class_code = ?
        ");
        if (!$stmtMax) throw new Exception("DB Error (max ledger): " . $conn->error, 500);

        $stmtMax->bind_param("i", $category_id);
        $stmtMax->execute();
        $rowMax            = $stmtMax->get_result()->fetch_assoc();
        $max_ledger_number = $rowMax['max_ledger_number'];
        $stmtMax->close();

        if (is_null($max_ledger_number)) {
            // No ledgers exist yet for this account type.
            // Seed: class_code + 1  (e.g. 65000000 → 65000001)
            $ledger_number = $category_id + 1;
        } else {
            $ledger_number = (int) $max_ledger_number + 1;
        }

        // ── 4. Insert ledger ──────────────────────────────────────────────────
        $stmtInsert = $conn->prepare("
            INSERT INTO ledger_table
                (ledger_name, ledger_number, ledger_class, ledger_class_code,
                 ledger_sub_class, ledger_type, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtInsert) throw new Exception("DB Error (insert prepare): " . $conn->error, 500);

        // Type string: s(name) i(number) s(class) i(class_code) s(sub) s(type) s(created) s(updated)
        $stmtInsert->bind_param(
            "sisissss",
            $ledger_name,
            $ledger_number,
            $category,
            $category_id,
            $sub_category,
            $type,
            $created_by,
            $updated_by
        );

        if (!$stmtInsert->execute()) {
            throw new Exception("Error inserting ledger: " . $stmtInsert->error, 500);
        }

        $insertedId = $stmtInsert->insert_id;
        $stmtInsert->close();

        // ── 5. Log ────────────────────────────────────────────────────────────
        $logStmt   = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail created Ledger: $ledger_name (Code: $ledger_number)";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            "status"  => "Success",
            "message" => "Ledger created successfully!",
            "data"    => [
                "id"               => $insertedId,
                "ledger_name"      => $ledger_name,
                "ledger_number"    => $ledger_number,
                "ledger_class"     => $category,
                "ledger_class_code"=> $category_id,
                "ledger_sub_class" => $sub_category,
                "ledger_type"      => $type,
            ],
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Create Ledger Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => publicErrorMessage($e),
    ]);
}