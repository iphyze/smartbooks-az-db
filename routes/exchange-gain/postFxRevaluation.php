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
    $userData              = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUser          = $userData['username'] ?? $userData['email'] ?? 'system';

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can post FX adjustments", 401);
    }

    // ── Parse JSON body ───────────────────────────────────────────────────────
    $body = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON body.", 400);
    }

    // ── Validate required body fields ─────────────────────────────────────────
    $requiredFields = ['datefrom', 'dateto', 'currency', 'journal_date', 'journal_description'];
    foreach ($requiredFields as $field) {
        if (!isset($body[$field]) || empty(trim($body[$field]))) {
            throw new Exception("Missing required field: '$field' is required.", 400);
        }
    }

    $datefrom            = trim($body['datefrom']);
    $dateto              = trim($body['dateto']);
    $currency            = trim($body['currency']);
    $journalDate         = trim($body['journal_date']);        // Date to post the FX journal entry
    $journalDescription  = trim($body['journal_description']); // e.g. "FX Revaluation - Dec 2024"

    // Optional cost_center (defaults to null/empty if not supplied)
    $costCenter = isset($body['cost_center']) ? trim($body['cost_center']) : '';

    // ── Whitelist Currency ────────────────────────────────────────────────────
    $allowedCurrencies = [
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate',
    ];

    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency. FX revaluation only applies to USD, EUR, or GBP.", 400);
    }

    $rateCol = $allowedCurrencies[$currency];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 1 — Fetch the latest closing rate (same as GET endpoint)
    // ════════════════════════════════════════════════════════════════════════

    $rateStmt = $conn->prepare("
        SELECT $rateCol AS closing_rate, ngn_rate, usd_rate, eur_rate, gbp_rate, created_at
        FROM currency_table
        ORDER BY created_at DESC
        LIMIT 1
    ");
    if (!$rateStmt) throw new Exception("DB Error (rates): " . $conn->error, 500);
    $rateStmt->execute();
    $rateRow = $rateStmt->get_result()->fetch_assoc();
    $rateStmt->close();

    if (!$rateRow || (float)$rateRow['closing_rate'] == 0) {
        throw new Exception("No valid closing exchange rate found in currency_table for $currency.", 500);
    }

    $closingRate = (float) $rateRow['closing_rate'];

    // All four rate columns are needed for the journal insert
    $ngnRate = (float) $rateRow['ngn_rate'];
    $usdRate = (float) $rateRow['usd_rate'];
    $eurRate = (float) $rateRow['eur_rate'];
    $gbpRate = (float) $rateRow['gbp_rate'];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Define revaluable categories (identical to GET endpoint)
    // ════════════════════════════════════════════════════════════════════════

    $revaluableCategories = [
        'BankAccounts' => [
            'title'     => 'Bank Accounts',
            'sub_class' => 'Current Asset',
            'type'      => 'Bank Accounts',
            'is_asset'  => true,
        ],
        'OffshoreBankAccounts' => [
            'title'     => 'Offshore Bank Accounts',
            'sub_class' => 'Current Asset',
            'type'      => 'Offshore Bank Accounts',
            'is_asset'  => true,
        ],
        'ServiceCustomers' => [
            'title'     => 'Service Customers (Receivables)',
            'sub_class' => 'Current Asset',
            'type'      => 'Service Customers',
            'is_asset'  => true,
        ],
        'StrategicPartners' => [
            'title'     => 'Strategic Partners',
            'sub_class' => 'Current Asset',
            'type'      => 'Strategic Partners',
            'is_asset'  => true,
        ],
        'Agents' => [
            'title'     => 'Agents',
            'sub_class' => 'Current Asset',
            'type'      => 'Agents',
            'is_asset'  => true,
        ],
        'LoansAndSimilarDebts' => [
            'title'     => 'Loans and Similar Debts',
            'sub_class' => 'Non-Current Liability',
            'type'      => 'Loans and Similar Debts',
            'is_asset'  => false,
        ],
        'SuppliersCreditors' => [
            'title'     => 'Suppliers / Creditors',
            'sub_class' => 'Current Liability',
            'type'      => 'Suppliers / Creditors',
            'is_asset'  => false,
        ],
    ];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — Re-compute FX differences (mirror of GET logic)
    //
    // We recompute rather than accepting client-submitted numbers to prevent
    // manipulation. The source of truth is always the database.
    // ════════════════════════════════════════════════════════════════════════

    $periodYear = (int) date('Y', strtotime($dateto));

    $categoryConditions = [];
    foreach ($revaluableCategories as $config) {
        $sc = $conn->real_escape_string($config['sub_class']);
        $tp = $conn->real_escape_string($config['type']);
        $categoryConditions[] = "(ledger_sub_class = '$sc' AND ledger_type = '$tp')";
    }
    $categoryWhere = implode(' OR ', $categoryConditions);

    $balStmt = $conn->prepare("
        SELECT
            ledger_name,
            ledger_number,
            ledger_sub_class,
            ledger_type,
            ledger_class,
            ledger_class_code,
            journal_currency,
            SUM(debit_ngn)                                               AS total_debit_ngn,
            SUM(credit_ngn)                                              AS total_credit_ngn,
            SUM(debit)                                                   AS total_debit_fcy,
            SUM(credit)                                                  AS total_credit_fcy,
            SUM(debit_ngn - credit_ngn) / NULLIF(SUM(debit - credit), 0) AS avg_book_rate
        FROM main_journal_table
        WHERE YEAR(journal_date) <= ?
          AND journal_currency = ?
          AND ($categoryWhere)
        GROUP BY
            ledger_name, ledger_number, ledger_sub_class,
            ledger_type, ledger_class, ledger_class_code, journal_currency
        ORDER BY ledger_number ASC
    ");
    if (!$balStmt) throw new Exception("DB Error (balances): " . $conn->error, 500);
    $balStmt->bind_param("is", $periodYear, $currency);
    $balStmt->execute();
    $balRows = $balStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $balStmt->close();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 4 — Fetch Exchange Gain ledger details from ledger_table
    //
    // Ledger 72000002 = Exchange Gain
    // This ledger is on the P&L (Revenue sub_class) and is the contra account
    // for all FX revaluation adjustments.
    //
    // The P&L double-entry logic:
    //
    //   FX GAIN (asset increased in NGN value OR liability decreased):
    //     DR  Revalued ledger (asset) or CR Revalued ledger (liability)  |  fx_difference (abs)
    //     CR  Exchange Gain 72000002                                     |  fx_difference (abs)
    //
    //   FX LOSS (asset decreased in NGN value OR liability increased):
    //     DR  Exchange Gain 72000002                                     |  fx_difference (abs)
    //     CR  Revalued ledger (asset) or DR Revalued ledger (liability)  |  fx_difference (abs)
    //
    // In practice this means:
    //   - Net GAIN across all ledgers → 72000002 receives a net CREDIT
    //   - Net LOSS across all ledgers → 72000002 receives a net DEBIT
    //   - The revalued ledger is adjusted by the fx_difference amount
    // ════════════════════════════════════════════════════════════════════════

    $fxLedgerStmt = $conn->prepare("
        SELECT ledger_name, ledger_number, ledger_class, ledger_class_code,
               ledger_sub_class, ledger_type
        FROM ledger_table
        WHERE ledger_number = '72000002'
        LIMIT 1
    ");
    if (!$fxLedgerStmt) throw new Exception("DB Error (FX ledger lookup): " . $conn->error, 500);
    $fxLedgerStmt->execute();
    $fxLedger = $fxLedgerStmt->get_result()->fetch_assoc();
    $fxLedgerStmt->close();

    if (!$fxLedger) {
        throw new Exception("Exchange Gain ledger (72000002) not found in ledger_table.", 500);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 5 — Build journal lines
    //
    // Each revalued ledger gets one journal line (DR or CR depending on
    // whether the movement is a gain or loss and asset or liability).
    // A single net contra line is posted to Exchange Gain (72000002).
    //
    // All amounts are posted in NGN (debit_ngn / credit_ngn).
    // The `debit` and `credit` columns hold the FCY amount (0 here, since
    // this is an NGN revaluation adjustment — no new FCY movement).
    //
    // journal_type = 'FX Revaluation' for identification and reversals.
    // ════════════════════════════════════════════════════════════════════════

    // Generate a unique journal_id for this batch (same pattern used elsewhere)
    $journalId = 'FXRV-' . strtoupper($currency) . '-' . date('YmdHis');

    $journalLines   = [];   // Lines to INSERT
    $netFxGainNGN   = 0.0;  // Net credit to Exchange Gain (positive = gain, negative = loss)

    foreach ($balRows as $row) {
        $subClass = trim($row['ledger_sub_class']);
        $type     = trim($row['ledger_type']);

        // Find matching category
        $matchedConfig = null;
        foreach ($revaluableCategories as $config) {
            if ($config['sub_class'] === $subClass && $config['type'] === $type) {
                $matchedConfig = $config;
                break;
            }
        }
        if ($matchedConfig === null) continue;

        $isAsset         = $matchedConfig['is_asset'];
        $fcyNet          = (float)$row['total_debit_fcy']  - (float)$row['total_credit_fcy'];
        $ngnBookValue    = (float)$row['total_debit_ngn']  - (float)$row['total_credit_ngn'];
        $ngnClosingValue = $fcyNet * $closingRate;
        $fxDifference    = $ngnClosingValue - $ngnBookValue; // +ve = NGN value rose
        $absAmount       = abs($fxDifference);

        if (round($absAmount, 2) == 0) {
            continue; // No adjustment needed for this ledger
        }

        // ── Determine DR/CR for the revalued ledger ───────────────────────
        //
        // ASSET + GAIN (fxDifference > 0): Debit the asset (increase it)
        // ASSET + LOSS (fxDifference < 0): Credit the asset (decrease it)
        // LIABILITY + LOSS (fxDifference > 0): Debit the liability (increase it)
        // LIABILITY + GAIN (fxDifference < 0): Credit the liability (decrease it)
        //
        // In all cases the contra entry goes to Exchange Gain 72000002.

        if ($isAsset) {
            if ($fxDifference > 0) {
                // Asset Gain: DR revalued ledger / CR Exchange Gain
                $ledgerDebitNGN  = $absAmount;
                $ledgerCreditNGN = 0.0;
                $netFxGainNGN   += $absAmount; // Credit to Exchange Gain
            } else {
                // Asset Loss: DR Exchange Gain / CR revalued ledger
                $ledgerDebitNGN  = 0.0;
                $ledgerCreditNGN = $absAmount;
                $netFxGainNGN   -= $absAmount; // Debit to Exchange Gain
            }
        } else {
            // Liability
            if ($fxDifference > 0) {
                // Liability Loss: DR Exchange Gain / CR revalued ledger
                $ledgerDebitNGN  = 0.0;
                $ledgerCreditNGN = $absAmount;
                $netFxGainNGN   -= $absAmount; // Debit to Exchange Gain
            } else {
                // Liability Gain: DR revalued ledger / CR Exchange Gain
                $ledgerDebitNGN  = $absAmount;
                $ledgerCreditNGN = 0.0;
                $netFxGainNGN   += $absAmount; // Credit to Exchange Gain
            }
        }

        // Line for the revalued ledger
        $journalLines[] = [
            'journal_id'          => $journalId,
            'journal_type'        => 'FX Revaluation',
            'transaction_type'    => 'Journal',
            'journal_date'        => $journalDate,
            'journal_currency'    => 'NGN', // Adjustment is in NGN
            'journal_description' => $journalDescription,
            'debit'               => 0, // No FCY movement in a revaluation entry
            'credit'              => 0,
            'rate_date'           => $rateRow['created_at'],
            'rate'                => 1, // NGN to NGN
            'debit_ngn'           => round($ledgerDebitNGN, 2),
            'credit_ngn'          => round($ledgerCreditNGN, 2),
            'ngn_rate'            => $ngnRate,
            'usd_rate'            => $usdRate,
            'eur_rate'            => $eurRate,
            'gbp_rate'            => $gbpRate,
            'cost_center'         => $costCenter,
            'ledger_name'         => $row['ledger_name'],
            'ledger_number'       => $row['ledger_number'],
            'ledger_class'        => $row['ledger_class'],
            'ledger_class_code'   => $row['ledger_class_code'],
            'ledger_sub_class'    => $subClass,
            'ledger_type'         => $type,
            'created_by'          => $loggedInUser,
            'updated_by'          => $loggedInUser,
        ];
    }

    // ── Guard: nothing to post ─────────────────────────────────────────────
    if (empty($journalLines)) {
        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "No FX differences found. No journal entries were posted.",
            "posted"  => 0,
        ]);
        exit;
    }

    // ── Contra line: Exchange Gain 72000002 ───────────────────────────────
    // netFxGainNGN > 0 → net CREDIT to Exchange Gain (gain scenario)
    // netFxGainNGN < 0 → net DEBIT  to Exchange Gain (loss scenario)

    $fxContraDebit  = $netFxGainNGN < 0 ? abs($netFxGainNGN) : 0.0;
    $fxContraCredit = $netFxGainNGN > 0 ? $netFxGainNGN      : 0.0;

    $journalLines[] = [
        'journal_id'          => $journalId,
        'journal_type'        => 'FX Revaluation',
        'transaction_type'    => 'Journal',
        'journal_date'        => $journalDate,
        'journal_currency'    => 'NGN',
        'journal_description' => $journalDescription,
        'debit'               => 0,
        'credit'              => 0,
        'rate_date'           => $rateRow['created_at'],
        'rate'                => 1,
        'debit_ngn'           => round($fxContraDebit, 2),
        'credit_ngn'          => round($fxContraCredit, 2),
        'ngn_rate'            => $ngnRate,
        'usd_rate'            => $usdRate,
        'eur_rate'            => $eurRate,
        'gbp_rate'            => $gbpRate,
        'cost_center'         => $costCenter,
        'ledger_name'         => $fxLedger['ledger_name'],
        'ledger_number'       => $fxLedger['ledger_number'],
        'ledger_class'        => $fxLedger['ledger_class'],
        'ledger_class_code'   => $fxLedger['ledger_class_code'],
        'ledger_sub_class'    => $fxLedger['ledger_sub_class'],
        'ledger_type'         => $fxLedger['ledger_type'],
        'created_by'          => $loggedInUser,
        'updated_by'          => $loggedInUser,
    ];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 6 — Insert journal lines in a transaction
    //
    // We use a DB transaction so either ALL lines post or NONE do.
    // This preserves double-entry integrity.
    // ════════════════════════════════════════════════════════════════════════

    $conn->begin_transaction();

    $insertSQL = "
        INSERT INTO main_journal_table (
            journal_id, journal_type, transaction_type,
            journal_date, journal_currency, journal_description,
            debit, credit, rate_date, rate,
            debit_ngn, credit_ngn,
            ngn_rate, usd_rate, eur_rate, gbp_rate,
            cost_center,
            ledger_name, ledger_number, ledger_class, ledger_class_code,
            ledger_sub_class, ledger_type,
            created_by, updated_by
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?
        )
    ";

    $insertStmt = $conn->prepare($insertSQL);
    if (!$insertStmt) {
        $conn->rollback();
        throw new Exception("DB Error (prepare insert): " . $conn->error, 500);
    }

    $postedCount = 0;

    foreach ($journalLines as $line) {
        $insertStmt->bind_param(
            "ssssssddsdddddddsssssssss",
            $line['journal_id'],
            $line['journal_type'],
            $line['transaction_type'],
            $line['journal_date'],
            $line['journal_currency'],
            $line['journal_description'],
            $line['debit'],
            $line['credit'],
            $line['rate_date'],
            $line['rate'],
            $line['debit_ngn'],
            $line['credit_ngn'],
            $line['ngn_rate'],
            $line['usd_rate'],
            $line['eur_rate'],
            $line['gbp_rate'],
            $line['cost_center'],
            $line['ledger_name'],
            $line['ledger_number'],
            $line['ledger_class'],
            $line['ledger_class_code'],
            $line['ledger_sub_class'],
            $line['ledger_type'],
            $line['created_by'],
            $line['updated_by']
        );

        if (!$insertStmt->execute()) {
            $conn->rollback();
            throw new Exception("DB Error (insert line): " . $insertStmt->error, 500);
        }

        $postedCount++;
    }

    $insertStmt->close();
    $conn->commit();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 7 — Respond
    // ════════════════════════════════════════════════════════════════════════

    $netLabel = $netFxGainNGN >= 0 ? "Net Exchange Gain" : "Net Exchange Loss";

    http_response_code(201);

    echo json_encode([
        "status"     => "Success",
        "message"    => "FX Revaluation journal posted successfully",
        "journal_id" => $journalId,
        "posted"     => $postedCount,
        "summary"    => [
            "net_fx_ngn"  => round($netFxGainNGN, 2),
            "net_label"   => $netLabel,
            "contra_debit"  => round($fxContraDebit, 2),
            "contra_credit" => round($fxContraCredit, 2),
            "exchange_gain_ledger" => $fxLedger['ledger_number'] . ' - ' . $fxLedger['ledger_name'],
        ],
        "closing_rate_info" => [
            "currency"        => $currency,
            "closing_rate"    => $closingRate,
            "rate_record_date"=> $rateRow['created_at'],
        ],
        "meta" => [
            "currency"     => $currency,
            "journal_date" => $journalDate,
            "datefrom"     => $datefrom,
            "dateto"       => $dateto,
            "period_year"  => $periodYear,
            "posted_by"    => $loggedInUser,
        ],
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}