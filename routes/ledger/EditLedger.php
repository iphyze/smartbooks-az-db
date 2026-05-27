<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────
    $userData       = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail      = $userData['email'];
    $userIntegrity  = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can update ledgers", 401);
    }

    // ── Parse JSON body ───────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ── Validate ──────────────────────────────────────────────────────────────
    if (empty($data['ledger_number'])) {
        throw new Exception("Ledger Number is required.", 400);
    }
    if (empty($data['ledger_name'])) {
        throw new Exception("Please ensure that the ledger name is filled!", 400);
    }
    if (empty($data['account_type'])) {
        throw new Exception("Please ensure that the account type is selected!", 400);
    }

    $current_ledger_number = (int) trim($data['ledger_number']); // cast to int — it's int(11) in schema
    $ledger_name           = trim($data['ledger_name']);
    $account_type          = trim($data['account_type']);
    $updated_by            = $userEmail;

    // ── Transaction ───────────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {

        // ── 1. Fetch account type details from account_table ──────────────────
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

        $category_id  = (int) $accountData['category_id']; // ledger_class_code — int(11)
        $category     = $accountData['category'];           // ledger_class
        $sub_category = $accountData['sub_category'];       // ledger_sub_class
        $type         = $accountData['type'];               // ledger_type

        // ── 2. Fetch current ledger to detect a type change ───────────────────
        //
        // FIX: ledger_number is int(11) — was bound as "s", now "i".

        $stmtCurrent = $conn->prepare("
            SELECT ledger_type FROM ledger_table
            WHERE ledger_number = ?
            LIMIT 1
        ");
        if (!$stmtCurrent) throw new Exception("DB Error (current ledger): " . $conn->error, 500);

        $stmtCurrent->bind_param("i", $current_ledger_number); // FIX: i not s
        $stmtCurrent->execute();
        $currentLedgerData = $stmtCurrent->get_result()->fetch_assoc();
        $stmtCurrent->close();

        if (!$currentLedgerData) {
            throw new Exception("Ledger with number $current_ledger_number not found.", 404);
        }

        $current_type = $currentLedgerData['ledger_type'];

        // ── 3. Determine new ledger number ────────────────────────────────────
        //
        // If the account type is unchanged, keep the existing ledger_number.
        // If it changed, generate the next number in the new type's range
        // (same logic as createLedger: MAX within ledger_class_code + 1).
        //
        // FIX: ledger_class_code is int(11) — was bound as "s", now "i".

        $new_ledger_number = $current_ledger_number;

        if ($current_type !== $account_type) {
            $stmtMax = $conn->prepare("
                SELECT MAX(ledger_number) AS max_ledger_number
                FROM ledger_table
                WHERE ledger_class_code = ?
            ");
            if (!$stmtMax) throw new Exception("DB Error (max ledger): " . $conn->error, 500);

            $stmtMax->bind_param("i", $category_id); // FIX: i not s
            $stmtMax->execute();
            $rowMax            = $stmtMax->get_result()->fetch_assoc();
            $max_ledger_number = $rowMax['max_ledger_number'];
            $stmtMax->close();

            $new_ledger_number = is_null($max_ledger_number)
                ? $category_id + 1
                : (int) $max_ledger_number + 1;
        }

        // ── 4. Check for duplicate name (excluding this ledger) ───────────────
        //
        // FIX: second param (ledger_number) is int(11) — was "ss", now "si".

        $stmtCheck = $conn->prepare("
            SELECT id FROM ledger_table
            WHERE ledger_name = ? AND ledger_number != ?
            LIMIT 1
        ");
        if (!$stmtCheck) throw new Exception("DB Error (dup check): " . $conn->error, 500);

        $stmtCheck->bind_param("si", $ledger_name, $current_ledger_number); // FIX: si not ss
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if ($resCheck->num_rows > 0) {
            throw new Exception("Ledger name '$ledger_name' already exists!", 400);
        }
        $stmtCheck->close();

        // ── 5. Update ledger_table ────────────────────────────────────────────
        //
        // FIX: type string was "ssssssss" — three of those 's' wrap integers:
        //   new_ledger_number    → i  (int(11))
        //   category_id          → i  (int(11))
        //   current_ledger_number→ i  (int(11), WHERE clause)
        // Correct string: "sisisssi" (8 chars)
        //
        // Breakdown:
        //   s  ledger_name
        //   i  new_ledger_number
        //   s  category (ledger_class)
        //   i  category_id (ledger_class_code)
        //   s  sub_category (ledger_sub_class)
        //   s  type (ledger_type)
        //   s  updated_by
        //   i  current_ledger_number (WHERE)

        $stmtUpdateLedger = $conn->prepare("
            UPDATE ledger_table
            SET
                ledger_name       = ?,
                ledger_number     = ?,
                ledger_class      = ?,
                ledger_class_code = ?,
                ledger_sub_class  = ?,
                ledger_type       = ?,
                updated_by        = ?
            WHERE ledger_number   = ?
        ");
        if (!$stmtUpdateLedger) throw new Exception("DB Error (ledger update prepare): " . $conn->error, 500);

        $stmtUpdateLedger->bind_param(
            "sisisssi",              // FIX: was "ssssssss"
            $ledger_name,            // s
            $new_ledger_number,      // i  ← was s
            $category,               // s
            $category_id,            // i  ← was s
            $sub_category,           // s
            $type,                   // s
            $updated_by,             // s
            $current_ledger_number   // i  ← was s (WHERE)
        );

        if (!$stmtUpdateLedger->execute()) {
            throw new Exception("Error updating ledger_table: " . $stmtUpdateLedger->error, 500);
        }
        $stmtUpdateLedger->close();

        // ── 6. Cascade to main_journal_table ─────────────────────────────────
        //
        // Propagates name, number, and classification changes to all historical
        // journal lines that reference this ledger.
        //
        // FIX: same type string correction as stmtUpdateLedger — "sisisssi".
        // main_journal_table.ledger_number     is int(11)
        // main_journal_table.ledger_class_code is int(11)

        $stmtUpdateJournal = $conn->prepare("
            UPDATE main_journal_table
            SET
                ledger_name       = ?,
                ledger_number     = ?,
                ledger_class      = ?,
                ledger_class_code = ?,
                ledger_sub_class  = ?,
                ledger_type       = ?,
                updated_by        = ?
            WHERE ledger_number   = ?
        ");
        if (!$stmtUpdateJournal) throw new Exception("DB Error (journal update prepare): " . $conn->error, 500);

        $stmtUpdateJournal->bind_param(
            "sisisssi",              // FIX: was "ssssssss"
            $ledger_name,            // s
            $new_ledger_number,      // i  ← was s
            $category,               // s
            $category_id,            // i  ← was s
            $sub_category,           // s
            $type,                   // s
            $updated_by,             // s
            $current_ledger_number   // i  ← was s (WHERE)
        );

        if (!$stmtUpdateJournal->execute()) {
            throw new Exception("Error updating main_journal_table: " . $stmtUpdateJournal->error, 500);
        }
        $stmtUpdateJournal->close();

        // ── 7. Log ────────────────────────────────────────────────────────────
        $logStmt   = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail updated Ledger: $ledger_name ($current_ledger_number → $new_ledger_number)";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Ledger updated successfully!",
            "data"    => [
                "old_ledger_number" => $current_ledger_number,
                "new_ledger_number" => $new_ledger_number,
                "ledger_name"       => $ledger_name,
                "ledger_class"      => $category,
                "ledger_class_code" => $category_id,
                "ledger_sub_class"  => $sub_category,
                "ledger_type"       => $type,
            ],
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Update Ledger Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => publicErrorMessage($e),
    ]);
}