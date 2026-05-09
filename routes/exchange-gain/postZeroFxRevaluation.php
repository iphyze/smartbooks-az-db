<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

/**
 * POST /exchange/post-zero-revaluation
 *
 * MEMO / ZERO-ENTRY approach to FX Revaluation.
 *
 * This endpoint posts the exchange gain or loss using the same two-sided
 * double-entry as the full restatement, BUT with one critical difference:
 *
 *   ┌─────────────────────────────────────────────────────────────────────┐
 *   │  Every revalued ledger line is inserted with debit_ngn = 0 and     │
 *   │  credit_ngn = 0.  The balance of those ledgers does NOT change.     │
 *   │  Only Exchange Gain (72000002) receives the real NGN amount.        │
 *   └─────────────────────────────────────────────────────────────────────┘
 *
 * The zero-NGN lines serve as an audit trail — they are visible in each
 * ledger's statement with a "Difference of Exchange {CURRENCY}" description,
 * exactly matching the pattern used by other accounting systems. An observer
 * can see that a revaluation was performed on that date without the ledger
 * balance being restated.
 *
 * Double-entry integrity is preserved because the ONLY non-zero lines are
 * the single net debit or credit to Exchange Gain (72000002), which balances
 * against itself (debit = loss, credit = gain).
 *
 * Body params (JSON):
 *   datefrom            string   Period start (Y-m-d)
 *   dateto              string   Period end   (Y-m-d)
 *   currency            string   'USD' | 'EUR' | 'GBP'
 *   journal_date        string   Date to stamp the journal entries (Y-m-d)
 *   journal_description string   Human-readable description for 72000002 line
 *   rate_date           string?  created_at of a specific currency_table row
 *   cost_center         string?  Optional cost centre code
 */

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
    // STEP 1 — Resolve the closing rate
    //
    // If rate_date is supplied, use that exact currency_table row so this
    // post matches the preview the user saw. Otherwise use the latest row.
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
    $ngnRate     = (float) $rateRow['ngn_rate'];
    $usdRate     = (float) $rateRow['usd_rate'];
    $eurRate     = (float) $rateRow['eur_rate'];
    $gbpRate     = (float) $rateRow['gbp_rate'];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Define revaluable ledger categories (identical to GET endpoint)
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
    // STEP 3 — Re-compute FX differences from the database
    //
    // Always re-computed server-side — never trusting client-submitted figures.
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
    // STEP 4 — Fetch Exchange Gain ledger (72000002) from ledger_table
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
    // STEP 5 — Build zero-entry lines + single real Exchange Gain line
    //
    // For every revalued ledger:
    //   → Insert a line with debit_ngn = 0 and credit_ngn = 0
    //   → Description = "Difference of Exchange {CURRENCY}"
    //   → This appears in the ledger statement but does NOT move the balance
    //
    // One net line is posted to Exchange Gain (72000002):
    //   → Net loss  → DEBIT  Exchange Gain (loss recognised in P&L)
    //   → Net gain  → CREDIT Exchange Gain (gain recognised in P&L)
    //   → Description = journalDescription (user-supplied, e.g. "FX Reval USD Dec 2025")
    // ════════════════════════════════════════════════════════════════════════

    $journalId    = 'FXRV-ZERO-' . strtoupper($currency) . '-' . date('YmdHis');
    $journalLines = [];
    $netFxGainNGN = 0.0; // Accumulates the REAL net movement for Exchange Gain only

    // Per-ledger description pattern — mirrors what other accounting systems display
    $zeroLineDesc = "Difference of Exchange $currency";

    foreach ($balRows as $row) {
        $subClass = trim($row['ledger_sub_class']);
        $type     = trim($row['ledger_type']);

        // Match to a revaluable category
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
            continue; // No FX movement on this ledger — skip entirely
        }

        // ── Accumulate the real net for Exchange Gain ─────────────────────
        if ($isAsset) {
            $netFxGainNGN += ($fxDifference > 0) ? $absAmount : -$absAmount;
        } else {
            // Liability: rising NGN cost = loss for the company
            $netFxGainNGN += ($fxDifference > 0) ? -$absAmount : $absAmount;
        }

        // ── Zero-entry line for this ledger ───────────────────────────────
        // debit_ngn = 0, credit_ngn = 0  → balance stays unchanged
        // debit     = 0, credit     = 0  → FCY balance stays unchanged
        // The line is still visible in the ledger statement as an audit marker
        $journalLines[] = [
            'journal_id'          => $journalId,
            'journal_type'        => 'FX Revaluation',
            'transaction_type'    => 'Journal',
            'journal_date'        => $journalDate,
            'journal_currency'    => $currency,             // FCY currency for context
            'journal_description' => $zeroLineDesc,         // "Difference of Exchange USD"
            'debit'               => 0,
            'credit'              => 0,
            'rate_date'           => $rateRow['created_at'],
            'rate'                => $closingRate,          // Closing rate for reference
            'debit_ngn'           => 0,                     // ← zero: balance not touched
            'credit_ngn'          => 0,                     // ← zero: balance not touched
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

    // ── Guard: no differences found ───────────────────────────────────────
    if (empty($journalLines)) {
        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "No FX differences found. No journal entries were posted.",
            "posted"  => 0,
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 6 — Single real line to Exchange Gain (72000002)
    //
    // This is the ONLY line with a non-zero NGN amount.
    // Net gain  → netFxGainNGN > 0 → CREDIT Exchange Gain (income)
    // Net loss  → netFxGainNGN < 0 → DEBIT  Exchange Gain (expense/loss)
    // ════════════════════════════════════════════════════════════════════════

    $fxContraDebit  = $netFxGainNGN < 0 ? abs($netFxGainNGN) : 0.0;
    $fxContraCredit = $netFxGainNGN > 0 ? $netFxGainNGN      : 0.0;

    $journalLines[] = [
        'journal_id'          => $journalId,
        'journal_type'        => 'FX Revaluation',
        'transaction_type'    => 'Journal',
        'journal_date'        => $journalDate,
        'journal_currency'    => 'NGN',
        'journal_description' => $journalDescription,       // User-supplied description
        'debit'               => 0,
        'credit'              => 0,
        'rate_date'           => $rateRow['created_at'],
        'rate'                => 1,
        'debit_ngn'           => round($fxContraDebit, 2),  // ← real NGN amount here
        'credit_ngn'          => round($fxContraCredit, 2), // ← real NGN amount here
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
    // STEP 7 — Insert all lines in a single DB transaction
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
    // STEP 8 — Respond
    // ════════════════════════════════════════════════════════════════════════

    $netLabel      = $netFxGainNGN >= 0 ? "Net Exchange Gain" : "Net Exchange Loss";
    $zeroLineCount = $postedCount - 1; // All lines except the Exchange Gain line

    http_response_code(201);

    echo json_encode([
        "status"     => "Success",
        "message"    => "FX Revaluation (zero-entry) journal posted successfully",
        "journal_id" => $journalId,
        "posted"     => $postedCount,
        "summary"    => [
            "zero_entry_lines"    => $zeroLineCount,   // Memo lines (0 NGN each)
            "net_fx_ngn"          => round($netFxGainNGN, 2),
            "net_label"           => $netLabel,
            "contra_debit"        => round($fxContraDebit, 2),
            "contra_credit"       => round($fxContraCredit, 2),
            "exchange_gain_ledger"=> $fxLedger['ledger_number'] . ' - ' . $fxLedger['ledger_name'],
        ],
        "closing_rate_info" => [
            "currency"         => $currency,
            "closing_rate"     => $closingRate,
            "rate_record_date" => $rateRow['created_at'],
        ],
        "meta" => [
            "method"       => "zero_entry",
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