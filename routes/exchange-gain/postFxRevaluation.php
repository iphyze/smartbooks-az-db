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

    $requiredFields = ['datefrom', 'dateto', 'currency', 'journal_date', 'journal_description'];
    foreach ($requiredFields as $field) {
        if (!isset($body[$field]) || empty(trim($body[$field]))) {
            throw new Exception("Missing required field: '$field' is required.", 400);
        }
    }

    $datefrom           = trim($body['datefrom']);
    $dateto             = trim($body['dateto']);
    $currency           = trim($body['currency']);
    $journalDate        = trim($body['journal_date']);
    $journalDescription = trim($body['journal_description']);
    $costCenter         = isset($body['cost_center']) ? trim($body['cost_center']) : '';

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
    // STEP 1 — Check if accounting period is locked (hard block)
    // ════════════════════════════════════════════════════════════════════════

    $lockStmt = $conn->prepare("
        SELECT id, lock_reason
        FROM accounting_periods
        WHERE is_locked = '1'
          AND start_date <= ?
          AND end_date   >= ?
        LIMIT 1
    ");
    if (!$lockStmt) throw new Exception("DB Error (lock check): " . $conn->error, 500);
    $lockStmt->bind_param("ss", $dateto, $datefrom);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if ($lockRow) {
        $reason = $lockRow['lock_reason'] ?? 'Period is locked';
        throw new Exception("Cannot post: accounting period is locked. Reason: $reason", 403);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Duplicate revaluation guard (hard block)
    //
    // Check both journal tables to be thorough.
    // ════════════════════════════════════════════════════════════════════════

    $dupStmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CAST(debit_ngn  AS DECIMAL(20,6))), 0) -
        COALESCE(SUM(CAST(credit_ngn AS DECIMAL(20,6))), 0) AS net_balance
    FROM main_journal_table
    WHERE journal_type     = 'Journal'
      AND journal_currency = 'NGN'
      AND journal_date    BETWEEN ? AND ?
      AND ledger_number   IN (72000002, 69000004)
");
    if (!$dupStmt) throw new Exception("DB Error (dup check): " . $conn->error, 500);
    $dupStmt->bind_param("ss", $datefrom, $dateto);
    $dupStmt->execute();
    $dupRow = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if (abs((float)$dupRow['net_balance']) > 0.01) {
        throw new Exception(
            "An FX Revaluation for $currency has already been posted for the period $datefrom to $dateto. " .
                "Please reverse the existing entries before re-posting.",
            409
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — Fetch the closing rate
    // ════════════════════════════════════════════════════════════════════════

    $rateDate = isset($body['rate_date']) ? trim($body['rate_date']) : null;

    if ($rateDate) {
        $rateStmt = $conn->prepare("
            SELECT $rateCol AS closing_rate, ngn_rate, usd_rate, eur_rate, gbp_rate, created_at
            FROM currency_table
            WHERE created_at = ?
            LIMIT 1
        ");
        if (!$rateStmt) throw new Exception("DB Error (rates): " . $conn->error, 500);
        $rateStmt->bind_param("s", $rateDate);
    } else {
        $rateStmt = $conn->prepare("
            SELECT $rateCol AS closing_rate, ngn_rate, usd_rate, eur_rate, gbp_rate, created_at
            FROM currency_table
            ORDER BY created_at DESC
            LIMIT 1
        ");
        if (!$rateStmt) throw new Exception("DB Error (rates): " . $conn->error, 500);
    }

    $rateStmt->execute();
    $rateRow = $rateStmt->get_result()->fetch_assoc();
    $rateStmt->close();

    if (!$rateRow || (float)$rateRow['closing_rate'] == 0) {
        throw new Exception("No valid closing exchange rate found in currency_table for $currency.", 500);
    }

    $closingRate = (float) $rateRow['closing_rate'];
    $ngnRate     = $rateRow['ngn_rate'];   // kept as string — all rate cols are VARCHAR
    $usdRate     = $rateRow['usd_rate'];
    $eurRate     = $rateRow['eur_rate'];
    $gbpRate     = $rateRow['gbp_rate'];
    $rateRecordDate = $rateRow['created_at'];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 4 — Define revaluable categories
    // Must be IDENTICAL to getFxRevaluation.php so preview = post always.
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
        'PettyCash' => [
            'title'     => 'Petty Cash (FCY)',
            'sub_class' => 'Current Asset',
            'type'      => 'Petty Cash',
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
        'OutsourcingAgent' => [
            'title'     => 'Outsourcing Agents',
            'sub_class' => 'Current Liability',
            'type'      => 'Outsourcing Agent',
            'is_asset'  => false,
        ],
    ];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 5 — Re-compute FCY balances server-side
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
            SUM(CAST(debit_ngn  AS DECIMAL(20,6)))                         AS total_debit_ngn,
            SUM(CAST(credit_ngn AS DECIMAL(20,6)))                         AS total_credit_ngn,
            SUM(CAST(debit      AS DECIMAL(20,6)))                         AS total_debit_fcy,
            SUM(CAST(credit     AS DECIMAL(20,6)))                         AS total_credit_fcy
        FROM main_journal_table
        WHERE YEAR(journal_date) <= ?
          AND journal_currency   = ?
          AND CAST(debit  AS DECIMAL(20,6)) + CAST(credit AS DECIMAL(20,6)) > 0
          AND ($categoryWhere)
        GROUP BY
            ledger_name, ledger_number, ledger_sub_class,
            ledger_type, ledger_class, ledger_class_code, journal_currency
        HAVING (SUM(CAST(debit AS DECIMAL(20,6))) - SUM(CAST(credit AS DECIMAL(20,6)))) <> 0
        ORDER BY ledger_number ASC
    ");
    if (!$balStmt) throw new Exception("DB Error (balances): " . $conn->error, 500);
    $balStmt->bind_param("is", $periodYear, $currency);
    $balStmt->execute();
    $balRows = $balStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $balStmt->close();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 6 — Fetch contra ledger details
    //
    //   Exchange Gain  72000002  (Revenue / Other Income)  → CREDIT on gain
    //   Exchange Loss  65000003  (Expense / Taxation)      → DEBIT on loss
    // ════════════════════════════════════════════════════════════════════════

    $gainLedgerStmt = $conn->prepare("
        SELECT ledger_name, ledger_number, ledger_class, ledger_class_code,
               ledger_sub_class, ledger_type
        FROM ledger_table
        WHERE ledger_number = 72000002
        LIMIT 1
    ");
    if (!$gainLedgerStmt) throw new Exception("DB Error (gain ledger): " . $conn->error, 500);
    $gainLedgerStmt->execute();
    $gainLedger = $gainLedgerStmt->get_result()->fetch_assoc();
    $gainLedgerStmt->close();
    if (!$gainLedger) throw new Exception("Exchange Gain ledger (72000002) not found.", 500);

    $lossLedgerStmt = $conn->prepare("
        SELECT ledger_name, ledger_number, ledger_class, ledger_class_code,
               ledger_sub_class, ledger_type
        FROM ledger_table
        WHERE ledger_number = 65000003
        LIMIT 1
    ");
    if (!$lossLedgerStmt) throw new Exception("DB Error (loss ledger): " . $conn->error, 500);
    $lossLedgerStmt->execute();
    $lossLedger = $lossLedgerStmt->get_result()->fetch_assoc();
    $lossLedgerStmt->close();
    if (!$lossLedger) throw new Exception("Exchange Loss ledger (65000003) not found in ledger_table.", 500);

    // ════════════════════════════════════════════════════════════════════════
    // STEP 7 — Generate the next journal_id
    //
    // PATTERN FROM DATA:
    // Both journal_table and main_journal_table share the same integer
    // journal_id. It is NOT the auto-increment PK of either table —
    // it is a shared sequential integer (102, 103, 105 ... 1309).
    //
    // The correct approach: SELECT MAX(journal_id) from journal_table,
    // add 1, and use that integer for BOTH the journal_table header row
    // AND all main_journal_table detail lines.
    //
    // This must happen INSIDE the transaction (with a lock) so concurrent
    // requests cannot claim the same journal_id. We use SELECT ... FOR UPDATE
    // to lock the max row while inside the transaction.
    // ════════════════════════════════════════════════════════════════════════

    $conn->begin_transaction();

    $maxIdStmt = $conn->prepare("
        SELECT COALESCE(MAX(journal_id), 100) AS max_id
        FROM journal_table
        FOR UPDATE
    ");
    if (!$maxIdStmt) {
        $conn->rollback();
        throw new Exception("DB Error (journal_id lock): " . $conn->error, 500);
    }
    $maxIdStmt->execute();
    $maxIdRow   = $maxIdStmt->get_result()->fetch_assoc();
    $maxIdStmt->close();

    $journalId = (int)$maxIdRow['max_id'] + 1;  // e.g. 1310

    // ════════════════════════════════════════════════════════════════════════
    // STEP 8 — Build journal lines and running totals
    //
    // Two main_journal_table lines per revalued ledger:
    //   Line A — the revalued ledger (DR or CR)
    //   Line B — contra account (Exchange Gain or Exchange Loss)
    //
    // Double-entry logic (all amounts in NGN):
    //   ASSET GAIN  (fxDifference > 0): DR revalued ledger / CR Exchange Gain
    //   ASSET LOSS  (fxDifference < 0): DR Exchange Loss   / CR revalued ledger
    //   LIABILITY LOSS (fxDiff > 0):    DR Exchange Loss   / CR revalued ledger
    //   LIABILITY GAIN (fxDiff < 0):    DR revalued ledger / CR Exchange Gain
    // ════════════════════════════════════════════════════════════════════════

    $journalLines  = [];  // rows for main_journal_table
    $netFxGainNGN  = 0.0;
    $totalGainNGN  = 0.0;
    $totalLossNGN  = 0.0;
    $totalDebitNGN = 0.0;  // running total for journal_table header
    $totalCreditNGN = 0.0;

    foreach ($balRows as $row) {
        $subClass = trim($row['ledger_sub_class']);
        $type     = trim($row['ledger_type']);

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
        $fxDifference    = $ngnClosingValue - $ngnBookValue;
        $absAmount       = round(abs($fxDifference), 2);

        if ($absAmount == 0) continue;

        // Determine DR/CR directions
        if ($isAsset) {
            if ($fxDifference > 0) {
                // ASSET GAIN: DR revalued / CR Exchange Gain
                $lDebit = $absAmount;
                $lCredit = 0;
                $cDebit = 0;
                $cCredit = $absAmount;
                $contraLedger = $gainLedger;
                $totalGainNGN += $absAmount;
                $netFxGainNGN += $absAmount;
            } else {
                // ASSET LOSS: DR Exchange Loss / CR revalued
                $lDebit = 0;
                $lCredit = $absAmount;
                $cDebit = $absAmount;
                $cCredit = 0;
                $contraLedger = $lossLedger;
                $totalLossNGN += $absAmount;
                $netFxGainNGN -= $absAmount;
            }
        } else {
            if ($fxDifference > 0) {
                // LIABILITY LOSS: DR Exchange Loss / CR revalued
                $lDebit = 0;
                $lCredit = $absAmount;
                $cDebit = $absAmount;
                $cCredit = 0;
                $contraLedger = $lossLedger;
                $totalLossNGN += $absAmount;
                $netFxGainNGN -= $absAmount;
            } else {
                // LIABILITY GAIN: DR revalued / CR Exchange Gain
                $lDebit = $absAmount;
                $lCredit = 0;
                $cDebit = 0;
                $cCredit = $absAmount;
                $contraLedger = $gainLedger;
                $totalGainNGN += $absAmount;
                $netFxGainNGN += $absAmount;
            }
        }

        $totalDebitNGN  += $lDebit  + $cDebit;
        $totalCreditNGN += $lCredit + $cCredit;

        // ── Line A: revalued ledger ───────────────────────────────────────────
        // All monetary columns are VARCHAR in schema — store as formatted strings
        $journalLines[] = [
            (int) $journalId,
            'Journal',
            'Journal',
            $journalDate,
            'NGN',
            $journalDescription,
            '0',                        // debit  (FCY — no FCY movement)
            '0',                        // credit (FCY — no FCY movement)
            $rateRecordDate,            // rate_date
            '1',                        // rate
            (string) $lDebit,           // debit_ngn
            (string) $lCredit,          // credit_ngn
            (string) $ngnRate,          // ngn_rate
            (string) $usdRate,          // usd_rate
            (string) $eurRate,          // eur_rate
            (string) $gbpRate,          // gbp_rate
            $costCenter,                // cost_center
            $row['ledger_name'],
            (int) $row['ledger_number'],
            $row['ledger_class'],
            (int) $row['ledger_class_code'],
            $subClass,
            $type,
            $loggedInUser,
            $loggedInUser,
        ];

        // ── Line B: contra ledger ─────────────────────────────────────────────
        $journalLines[] = [
            (int) $journalId,
            'Journal',
            'Journal',
            $journalDate,
            'NGN',
            $journalDescription,
            '0',
            '0',
            $rateRecordDate,
            '1',
            (string) $cDebit,
            (string) $cCredit,
            (string) $ngnRate,
            (string) $usdRate,
            (string) $eurRate,
            (string) $gbpRate,
            $costCenter,
            $contraLedger['ledger_name'],
            (int) $contraLedger['ledger_number'],
            $contraLedger['ledger_class'],
            (int) $contraLedger['ledger_class_code'],
            $contraLedger['ledger_sub_class'],
            $contraLedger['ledger_type'],
            $loggedInUser,
            $loggedInUser,
        ];
    }

    // ── Guard: nothing to post ────────────────────────────────────────────────
    if (empty($journalLines)) {
        $conn->rollback();
        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "No FX differences found. No journal entries were posted.",
            "posted"  => 0,
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 9 — Insert ONE header row into journal_table
    //
    // PATTERN FROM DATA:
    // journal_table has exactly ONE row per journal_id.
    // It is the summary/header that represents the whole journal batch.
    //
    // Columns:
    //   journal_id       — the integer we generated in STEP 7
    //   journal_type     — 'FX Revaluation'
    //   transaction_type — 'Journal'
    //   journal_date     — posting date
    //   journal_currency — 'NGN' (revaluation adjustments are in NGN)
    //   journal_description
    //   debit            — total NGN debited  across all lines (balanced = credit)
    //   credit           — total NGN credited across all lines
    //   rate_date        — rate record date
    //   rate             — '1' (NGN journal)
    //   debit_ngn        — same as debit (NGN journal, no conversion needed)
    //   credit_ngn       — same as credit
    //   debit_others     — '0' (no FCY movement)
    //   credit_others    — '0'
    //   cost_center      — '' (no single counterparty for a revaluation)
    //   created_by / updated_by
    //
    // TYPE STRING: 'issssssssssssssss' (journal_id=i, all others=s, 17 params)
    // All monetary columns in journal_table are VARCHAR — store as strings.
    // ════════════════════════════════════════════════════════════════════════

    $totalDebitStr  = (string) round($totalDebitNGN,  2);
    $totalCreditStr = (string) round($totalCreditNGN, 2);

    $jInsertStmt = $conn->prepare("
        INSERT INTO journal_table (
            journal_id, journal_type, transaction_type,
            journal_date, journal_currency, journal_description,
            debit, credit, rate_date, rate,
            debit_ngn, credit_ngn,
            debit_others, credit_others,
            cost_center,
            created_by, updated_by
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?,
            ?, ?
        )
    ");
    if (!$jInsertStmt) {
        $conn->rollback();
        throw new Exception("DB Error (journal_table prepare): " . $conn->error, 500);
    }

    // 17 params: i + 16×s
    $jType         = 'Journal';
    $jTxType       = 'Journal';
    $jCurrency     = 'NGN';
    $jRate         = '1';
    $jDebitOthers  = '0';
    $jCreditOthers = '0';
    $jCostCenter   = '';  // No specific counterparty for a revaluation batch

    $jInsertStmt->bind_param(
        "issssssssssssssss",
        $journalId,       // i  journal_id
        $jType,           // s  journal_type
        $jTxType,         // s  transaction_type
        $journalDate,     // s  journal_date
        $jCurrency,       // s  journal_currency
        $journalDescription, // s journal_description
        $totalDebitStr,   // s  debit
        $totalCreditStr,  // s  credit
        $rateRecordDate,  // s  rate_date
        $jRate,           // s  rate
        $totalDebitStr,   // s  debit_ngn  (= debit, NGN journal)
        $totalCreditStr,  // s  credit_ngn (= credit, NGN journal)
        $jDebitOthers,    // s  debit_others
        $jCreditOthers,   // s  credit_others
        $jCostCenter,     // s  cost_center
        $loggedInUser,    // s  created_by
        $loggedInUser     // s  updated_by
    );

    if (!$jInsertStmt->execute()) {
        $conn->rollback();
        throw new Exception("DB Error (journal_table insert): " . $jInsertStmt->error, 500);
    }
    $jInsertStmt->close();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 10 — Insert all detail lines into main_journal_table
    //
    // TYPE STRING: "ssssssddssddddddssisissss" — NO LONGER USED.
    //
    // CORRECTION: All monetary columns (debit, credit, rate, debit_ngn,
    // credit_ngn, ngn_rate, usd_rate, eur_rate, gbp_rate) are VARCHAR in
    // the actual schema. We store them as strings, matching every other
    // endpoint in the codebase.
    //
    // Revised type string: "issssssssssssssssisissss" (25 params)
    //   journal_id(i), journal_type(s), transaction_type(s),
    //   journal_date(s), journal_currency(s), journal_description(s),
    //   debit(s), credit(s), rate_date(s), rate(s),
    //   debit_ngn(s), credit_ngn(s),
    //   ngn_rate(s), usd_rate(s), eur_rate(s), gbp_rate(s),
    //   cost_center(s),
    //   ledger_name(s), ledger_number(i), ledger_class(s), ledger_class_code(i),
    //   ledger_sub_class(s), ledger_type(s),
    //   created_by(s), updated_by(s)
    // ════════════════════════════════════════════════════════════════════════

    $mInsertSQL = "
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

    $mInsertStmt = $conn->prepare($mInsertSQL);
    if (!$mInsertStmt) {
        $conn->rollback();
        throw new Exception("DB Error (main_journal prepare): " . $conn->error, 500);
    }

    $postedCount = 0;

    foreach ($journalLines as $l) {
        // Type string breakdown (25 params):
        // [0]  journal_id           i  integer
        // [1]  journal_type         s  string
        // [2]  transaction_type     s  string
        // [3]  journal_date         s  string
        // [4]  journal_currency     s  string
        // [5]  journal_description  s  string
        // [6]  debit                s  VARCHAR column — store as string '0'
        // [7]  credit               s  VARCHAR column — store as string '0'
        // [8]  rate_date            s  string
        // [9]  rate                 s  VARCHAR column — store as string '1'
        // [10] debit_ngn            s  VARCHAR column — store as string
        // [11] credit_ngn           s  VARCHAR column — store as string
        // [12] ngn_rate             s  VARCHAR column
        // [13] usd_rate             s  VARCHAR column
        // [14] eur_rate             s  VARCHAR column
        // [15] gbp_rate             s  VARCHAR column
        // [16] cost_center          s  string
        // [17] ledger_name          s  string
        // [18] ledger_number        i  integer
        // [19] ledger_class         s  string
        // [20] ledger_class_code    i  integer
        // [21] ledger_sub_class     s  string
        // [22] ledger_type          s  string
        // [23] created_by           s  string
        // [24] updated_by           s  string
        $mInsertStmt->bind_param(
            "isssssssssssssssssisissss",
            $l[0],   // journal_id           i
            $l[1],   // journal_type         s
            $l[2],   // transaction_type     s
            $l[3],   // journal_date         s
            $l[4],   // journal_currency     s
            $l[5],   // journal_description  s
            $l[6],   // debit                s
            $l[7],   // credit               s
            $l[8],   // rate_date            s
            $l[9],   // rate                 s
            $l[10],  // debit_ngn            s
            $l[11],  // credit_ngn           s
            $l[12],  // ngn_rate             s
            $l[13],  // usd_rate             s
            $l[14],  // eur_rate             s
            $l[15],  // gbp_rate             s
            $l[16],  // cost_center          s
            $l[17],  // ledger_name          s
            $l[18],  // ledger_number        i
            $l[19],  // ledger_class         s
            $l[20],  // ledger_class_code    i
            $l[21],  // ledger_sub_class     s
            $l[22],  // ledger_type          s
            $l[23],  // created_by           s
            $l[24]   // updated_by           s
        );

        if (!$mInsertStmt->execute()) {
            $conn->rollback();
            throw new Exception("DB Error (main_journal insert): " . $mInsertStmt->error, 500);
        }
        $postedCount++;
    }

    $mInsertStmt->close();
    $conn->commit();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 11 — Respond
    // ════════════════════════════════════════════════════════════════════════

    $netLabel = $netFxGainNGN >= 0 ? "Net Exchange Gain" : "Net Exchange Loss";

    http_response_code(201);
    echo json_encode([
        "status"     => "Success",
        "message"    => "FX Revaluation journal posted successfully",
        "journal_id" => $journalId,   // integer, e.g. 1310
        "posted"     => $postedCount, // number of main_journal_table lines inserted
        "summary" => [
            "total_gain_ngn" => round($totalGainNGN, 2),
            "total_loss_ngn" => round($totalLossNGN, 2),
            "net_fx_ngn"     => round($netFxGainNGN, 2),
            "net_label"      => $netLabel,
            "gain_posted_to" => $totalGainNGN > 0
                ? $gainLedger['ledger_number'] . ' — ' . $gainLedger['ledger_name']
                : null,
            "loss_posted_to" => $totalLossNGN > 0
                ? $lossLedger['ledger_number'] . ' — ' . $lossLedger['ledger_name']
                : null,
        ],
        "closing_rate_info" => [
            "currency"         => $currency,
            "closing_rate"     => $closingRate,
            "rate_record_date" => $rateRecordDate,
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
    error_log("FX Revaluation POST Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => publicErrorMessage($e)]);
}
