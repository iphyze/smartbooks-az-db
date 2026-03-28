<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────
    $userData              = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can access this resource", 401);
    }

    // ── Validate Inputs ───────────────────────────────────────────────────────
    $requiredParams = ['datefrom', 'dateto', 'currency'];
    foreach ($requiredParams as $param) {
        if (!isset($_GET[$param]) || empty(trim($_GET[$param]))) {
            throw new Exception("Missing required parameter: '$param' is required.", 400);
        }
    }

    $datefrom = trim($_GET['datefrom']);
    $dateto   = trim($_GET['dateto']);
    $currency = trim($_GET['currency']);

    // ── Whitelist Currency ────────────────────────────────────────────────────
    // NGN-to-NGN has no FX exposure, so only foreign currencies are valid here.
    $allowedCurrencies = [
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate',
    ];

    if (!array_key_exists($currency, $allowedCurrencies)) {
        throw new Exception("Invalid currency. FX revaluation only applies to USD, EUR, or GBP.", 400);
    }

    $rateCol = $allowedCurrencies[$currency]; // e.g. 'usd_rate'

    // ════════════════════════════════════════════════════════════════════════
    // STEP 1 — Fetch the latest closing rate from currency_table
    //
    // The most recently created record is treated as the period closing rate.
    // This matches how currency_table is queried across all other endpoints.
    // ════════════════════════════════════════════════════════════════════════

    $rateStmt = $conn->prepare("
        SELECT $rateCol AS closing_rate, created_at
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

    $closingRate = (float) $rateRow['closing_rate']; // How many NGN per 1 FCY unit

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Define revaluable ledger categories
    //
    // Only balance sheet items with foreign currency exposure are revalued.
    // P&L items are excluded — they are recognised at the transaction-date rate.
    //
    // is_asset = true  → FX gain when NGN weakens (foreign asset worth more NGN)
    // is_asset = false → FX loss when NGN weakens (foreign liability costs more NGN)
    // ════════════════════════════════════════════════════════════════════════

    $revaluableCategories = [
        // ── Current Assets ──────────────────────────────────────────────────
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
        // ── Non-Current Liabilities ─────────────────────────────────────────
        'LoansAndSimilarDebts' => [
            'title'     => 'Loans and Similar Debts',
            'sub_class' => 'Non-Current Liability',
            'type'      => 'Loans and Similar Debts',
            'is_asset'  => false,
        ],
        // ── Current Liabilities ─────────────────────────────────────────────
        'SuppliersCreditors' => [
            'title'     => 'Suppliers / Creditors',
            'sub_class' => 'Current Liability',
            'type'      => 'Suppliers / Creditors',
            'is_asset'  => false,
        ],
    ];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — Fetch cumulative FCY balances per ledger
    //
    // We use YEAR(journal_date) <= periodYear for cumulative balance sheet
    // values, consistent with the Balance Sheet endpoint pattern.
    //
    // We filter by journal_currency = selected currency so only FCY-
    // denominated lines are revalued. NGN lines on the same ledger are
    // already at face value and need no revaluation.
    //
    // Book rate per ledger = implied weighted-average:
    //   avg_book_rate = SUM(debit_ngn - credit_ngn) / NULLIF(SUM(debit - credit), 0)
    //
    // The `debit` and `credit` columns hold the original FCY amounts.
    // The `debit_ngn` and `credit_ngn` hold the NGN equivalent at posting.
    // ════════════════════════════════════════════════════════════════════════

    $periodYear = (int) date('Y', strtotime($dateto));

    // Build the category filter dynamically (same pattern as Balance Sheet)
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
            ledger_type, ledger_class, journal_currency
        ORDER BY ledger_number ASC
    ");
    if (!$balStmt) throw new Exception("DB Error (balances): " . $conn->error, 500);
    $balStmt->bind_param("is", $periodYear, $currency);
    $balStmt->execute();
    $balRows = $balStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $balStmt->close();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 4 — Calculate FX Gain / Loss per ledger line
    //
    // FCY Net Balance   = total_debit_fcy  - total_credit_fcy
    // NGN Book Value    = total_debit_ngn  - total_credit_ngn  (as posted)
    // NGN Closing Value = FCY Net Balance  × closing_rate
    // FX Difference     = NGN Closing Value - NGN Book Value
    //
    // ASSETS:
    //   +ve difference → the asset is worth more NGN → FX GAIN
    //   -ve difference → the asset is worth less NGN → FX LOSS
    //
    // LIABILITIES:
    //   +ve difference → the liability costs more NGN → FX LOSS
    //   -ve difference → the liability costs less NGN → FX GAIN
    //
    // Exchange Gain / Loss ledger: 72000002
    // Journal entries are generated but NOT posted here (POST endpoint does that).
    // ════════════════════════════════════════════════════════════════════════

    // Initialise grouped report structure
    $reportData = [];
    foreach ($revaluableCategories as $key => $config) {
        $reportData[$key] = [
            'title'         => $config['title'],
            'is_asset'      => $config['is_asset'],
            'records'       => [],
            'subtotal_gain' => 0.0,
            'subtotal_loss' => 0.0,
            'subtotal_net'  => 0.0,
        ];
    }

    $grandTotalGain   = 0.0;
    $grandTotalLoss   = 0.0;
    $grandTotalNet    = 0.0;
    $pendingJournals  = []; // Preview of what the POST will create

    foreach ($balRows as $row) {
        $subClass = trim($row['ledger_sub_class']);
        $type     = trim($row['ledger_type']);

        // Match to a revaluable category
        $matchedKey = null;
        foreach ($revaluableCategories as $key => $config) {
            if ($config['sub_class'] === $subClass && $config['type'] === $type) {
                $matchedKey = $key;
                break;
            }
        }
        if ($matchedKey === null) continue;

        $isAsset         = $revaluableCategories[$matchedKey]['is_asset'];
        $fcyNet          = (float)$row['total_debit_fcy']  - (float)$row['total_credit_fcy'];
        $ngnBookValue    = (float)$row['total_debit_ngn']  - (float)$row['total_credit_ngn'];
        $ngnClosingValue = $fcyNet * $closingRate;
        $fxDifference    = $ngnClosingValue - $ngnBookValue; // +ve = NGN value rose

        // Interpret gain vs loss based on asset / liability nature
        if ($isAsset) {
            $fxGain = $fxDifference > 0 ? $fxDifference  : 0.0;
            $fxLoss = $fxDifference < 0 ? abs($fxDifference) : 0.0;
            $fxNet  = $fxDifference;         // +ve = gain, -ve = loss
        } else {
            // For a liability: rising NGN value = more you owe = LOSS
            $fxGain = $fxDifference < 0 ? abs($fxDifference) : 0.0;
            $fxLoss = $fxDifference > 0 ? $fxDifference      : 0.0;
            $fxNet  = $fxDifference * -1;    // flip: costing less = gain
        }

        // Implied avg book rate (absolute, for display)
        $avgBookRate = ($fcyNet != 0) ? abs($ngnBookValue / $fcyNet) : 0.0;

        // Build the record
        $record = [
            'ledger_name'       => $row['ledger_name'],
            'ledger_number'     => $row['ledger_number'],
            'ledger_sub_class'  => $subClass,
            'ledger_type'       => $type,
            'ledger_class'      => $row['ledger_class'],
            'journal_currency'  => $row['journal_currency'],
            'fcy_net_balance'   => round($fcyNet, 4),
            'avg_book_rate'     => round($avgBookRate, 6),
            'ngn_book_value'    => round($ngnBookValue, 2),
            'closing_rate'      => round($closingRate, 6),
            'ngn_closing_value' => round($ngnClosingValue, 2),
            'fx_difference'     => round($fxDifference, 2),
            'fx_gain'           => round($fxGain, 2),
            'fx_loss'           => round($fxLoss, 2),
            'fx_net'            => round($fxNet, 2),
        ];

        $reportData[$matchedKey]['records'][]       = $record;
        $reportData[$matchedKey]['subtotal_gain']   += $fxGain;
        $reportData[$matchedKey]['subtotal_loss']   += $fxLoss;
        $reportData[$matchedKey]['subtotal_net']    += $fxNet;

        $grandTotalGain += $fxGain;
        $grandTotalLoss += $fxLoss;
        $grandTotalNet  += $fxNet;

        // Only include ledgers with an actual FX difference in the pending journal preview
        if (round($fxDifference, 2) != 0) {
            $pendingJournals[] = [
                'ledger_name'   => $row['ledger_name'],
                'ledger_number' => $row['ledger_number'],
                'ledger_class'  => $row['ledger_class'],
                'ledger_sub_class' => $subClass,
                'ledger_type'   => $type,
                'is_asset'      => $isAsset,
                'fcy_net'       => round($fcyNet, 4),
                'fx_net'        => round($fxNet, 2),         // +ve = gain, -ve = loss
                'fx_difference' => round($fxDifference, 2),  // raw NGN movement
            ];
        }
    }

    http_response_code(200);

    echo json_encode([
        "status"  => "Success",
        "message" => "FX Revaluation report fetched successfully",
        "data"    => $reportData,
        "summary" => [
            "grand_total_gain" => round($grandTotalGain, 2),
            "grand_total_loss" => round($grandTotalLoss, 2),
            "grand_total_net"  => round($grandTotalNet, 2),
            "net_label"        => $grandTotalNet >= 0 ? "Net Exchange Gain" : "Net Exchange Loss",
        ],
        "pending_journals" => $pendingJournals,
        "closing_rate_info" => [
            "currency"        => $currency,
            "closing_rate"    => $closingRate,
            "rate_record_date"=> $rateRow['created_at'],
        ],
        "meta" => [
            "currency"    => $currency,
            "datefrom"    => $datefrom,
            "dateto"      => $dateto,
            "period_year" => $periodYear,
        ],
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}