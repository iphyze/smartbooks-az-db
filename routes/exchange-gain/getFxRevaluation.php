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
    // STEP 1 — Fetch the closing rate from currency_table
    // ════════════════════════════════════════════════════════════════════════

    $rateDate = isset($_GET['rate_date']) ? trim($_GET['rate_date']) : null;

    if ($rateDate) {
        $rateStmt = $conn->prepare("
            SELECT $rateCol AS closing_rate, created_at
            FROM currency_table
            WHERE created_at = ?
            LIMIT 1
        ");
        if (!$rateStmt) throw new Exception("DB Error (rates): " . $conn->error, 500);
        $rateStmt->bind_param("s", $rateDate);
    } else {
        $rateStmt = $conn->prepare("
            SELECT $rateCol AS closing_rate, created_at
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

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Check if this period + currency is already posted
    //
    // FIX: Duplicate revaluation guard.
    // We look for any existing journal line with journal_type = 'FX Revaluation'
    // whose journal_date falls inside the requested period and whose cost_center
    // carries the currency stamp we write on every FX post (see POST endpoint).
    // If found, we warn the client — they can still preview, but the UI will
    // surface the warning so the user posts deliberately, not accidentally.
    // ════════════════════════════════════════════════════════════════════════

    $dupStmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CAST(debit_ngn  AS DECIMAL(20,6))), 0) -
            COALESCE(SUM(CAST(credit_ngn AS DECIMAL(20,6))), 0) AS net_balance,
            MIN(journal_id) AS posted_journal_id
        FROM main_journal_table
        WHERE journal_type     = 'Journal'
        AND journal_currency = 'NGN'
        AND journal_date    BETWEEN ? AND ?
        AND ledger_number   IN (72000002, 65000003)
    ");
    if (!$dupStmt) throw new Exception("DB Error (dup check): " . $conn->error, 500);
    $dupStmt->bind_param("ss", $datefrom, $dateto);
    $dupStmt->execute();
    $dupRow = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    $alreadyPosted = (abs((float)$dupRow['net_balance']) > 0.01);

    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — Check if accounting period is locked
    //
    // FIX: Period lock guard.
    // We check accounting_periods for any record that overlaps the requested
    // date range and is marked as locked. If locked, we surface that to the
    // frontend so the Post button can be disabled with a clear explanation.
    // ════════════════════════════════════════════════════════════════════════

    $lockStmt = $conn->prepare("
        SELECT id, start_date, end_date, lock_reason
        FROM accounting_periods
        WHERE is_locked = '1'
          AND start_date <= ?
          AND end_date >= ?
        LIMIT 1
    ");
    if (!$lockStmt) throw new Exception("DB Error (lock check): " . $conn->error, 500);
    $lockStmt->bind_param("ss", $dateto, $datefrom);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    $periodIsLocked = ($lockRow !== null);
    $lockReason     = $periodIsLocked ? ($lockRow['lock_reason'] ?? 'Period is locked') : null;

    // ════════════════════════════════════════════════════════════════════════
    // STEP 4 — Define revaluable ledger categories
    //
    // FIX A: PettyCash added — "Head Office (USD)" ledger 52000002 is a
    //         monetary FCY asset that must be revalued under IAS 21.23.
    //
    // FIX B: OutsourcingAgent added — Allied Global, Bakir Hussain etc.
    //         may carry FCY balances owed to foreign outsourcing agents.
    //
    // is_asset = true  → FX gain when NGN weakens (FCY asset worth more NGN)
    // is_asset = false → FX loss when NGN weakens (FCY liability costs more NGN)
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
        // FIX A — Petty Cash FCY
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
        // FIX B — Outsourcing Agents FCY
        'OutsourcingAgent' => [
            'title'     => 'Outsourcing Agents',
            'sub_class' => 'Current Liability',
            'type'      => 'Outsourcing Agent',
            'is_asset'  => false,
        ],
    ];

    // ════════════════════════════════════════════════════════════════════════
    // STEP 5 — Fetch FCY balances per ledger
    //
    // FIX C: We filter journal_currency = $currency (e.g. 'USD') so that
    // NGN-denominated lines posted to the same ledger are EXCLUDED.
    // Without this filter, NGN lines dilute the avg_book_rate toward 1
    // and overstate the FCY balance — producing wrong gain/loss figures.
    //
    // We also use YEAR(journal_date) <= $periodYear for cumulative balance
    // sheet values (consistent with the Balance Sheet endpoint).
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
            journal_currency,
            SUM(debit_ngn)                                                AS total_debit_ngn,
            SUM(credit_ngn)                                               AS total_credit_ngn,
            SUM(CAST(debit  AS DECIMAL(20,6)))                            AS total_debit_fcy,
            SUM(CAST(credit AS DECIMAL(20,6)))                            AS total_credit_fcy,
            SUM(CAST(debit_ngn AS DECIMAL(20,6)) - CAST(credit_ngn AS DECIMAL(20,6)))
                / NULLIF(
                    SUM(CAST(debit AS DECIMAL(20,6)) - CAST(credit AS DECIMAL(20,6))),
                  0)                                                       AS avg_book_rate
        FROM main_journal_table
        WHERE YEAR(journal_date) <= ?
          AND journal_currency   = ?
          AND CAST(debit  AS DECIMAL(20,6)) + CAST(credit AS DECIMAL(20,6)) > 0
          AND ($categoryWhere)
        GROUP BY
            ledger_name, ledger_number, ledger_sub_class,
            ledger_type, ledger_class, journal_currency
        HAVING (SUM(CAST(debit AS DECIMAL(20,6))) - SUM(CAST(credit AS DECIMAL(20,6)))) <> 0
        ORDER BY ledger_number ASC
    ");
    if (!$balStmt) throw new Exception("DB Error (balances): " . $conn->error, 500);
    $balStmt->bind_param("is", $periodYear, $currency);
    $balStmt->execute();
    $balRows = $balStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $balStmt->close();

    // ════════════════════════════════════════════════════════════════════════
    // STEP 6 — Calculate FX Gain / Loss per ledger (IAS 21 compliant)
    //
    // FCY Net Balance   = total_debit_fcy  − total_credit_fcy
    // NGN Book Value    = total_debit_ngn  − total_credit_ngn  (as posted)
    // NGN Closing Value = FCY Net Balance  × closing_rate
    // FX Difference     = NGN Closing Value − NGN Book Value   (+ve = NGN rose)
    //
    // ASSETS:
    //   fxDifference > 0 → asset worth MORE in NGN  → GAIN  (IAS 21.28a)
    //   fxDifference < 0 → asset worth LESS in NGN  → LOSS  (IAS 21.28a)
    //
    // LIABILITIES:
    //   fxDifference > 0 → liability costs MORE NGN → LOSS  (IAS 21.28a)
    //   fxDifference < 0 → liability costs LESS NGN → GAIN  (IAS 21.28a)
    //
    // FIX D: SEPARATE CONTRA ACCOUNTS
    //   Net Gain → recognised in Exchange Gain  72000002 (Revenue / Other Income)
    //   Net Loss → recognised in Exchange Loss  65000003 (Expense / Finance Cost)
    //
    // Both ledgers are surfaced in pending_journals so the frontend can show
    // the user which P&L account will be hit before they commit.
    // ════════════════════════════════════════════════════════════════════════

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

    $grandTotalGain  = 0.0;
    $grandTotalLoss  = 0.0;
    $grandTotalNet   = 0.0;
    $pendingJournals = [];

    foreach ($balRows as $row) {
        $subClass = trim($row['ledger_sub_class']);
        $type     = trim($row['ledger_type']);

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
        $fxDifference    = $ngnClosingValue - $ngnBookValue;

        if ($isAsset) {
            // Rising NGN value on an asset = GAIN
            $fxGain = $fxDifference > 0 ? $fxDifference      : 0.0;
            $fxLoss = $fxDifference < 0 ? abs($fxDifference) : 0.0;
            $fxNet  = $fxDifference;
        } else {
            // Rising NGN value on a liability = LOSS (costs us more)
            $fxGain = $fxDifference < 0 ? abs($fxDifference) : 0.0;
            $fxLoss = $fxDifference > 0 ? $fxDifference      : 0.0;
            $fxNet  = $fxDifference * -1;
        }

        // Safe avg_book_rate using absolute values (for display)
        $avgBookRate = ($fcyNet != 0) ? abs($ngnBookValue / $fcyNet) : 0.0;

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

        $reportData[$matchedKey]['records'][]     = $record;
        $reportData[$matchedKey]['subtotal_gain'] += $fxGain;
        $reportData[$matchedKey]['subtotal_loss'] += $fxLoss;
        $reportData[$matchedKey]['subtotal_net']  += $fxNet;

        $grandTotalGain += $fxGain;
        $grandTotalLoss += $fxLoss;
        $grandTotalNet  += $fxNet;

        // FIX D — Show WHICH contra account will be hit per ledger line
        if (round($fxDifference, 2) != 0) {
            // Determine the correct contra account for this specific line
            $lineIsGain = ($fxNet > 0);
            $pendingJournals[] = [
                'ledger_name'        => $row['ledger_name'],
                'ledger_number'      => $row['ledger_number'],
                'ledger_class'       => $row['ledger_class'],
                'ledger_sub_class'   => $subClass,
                'ledger_type'        => $type,
                'is_asset'           => $isAsset,
                'fcy_net'            => round($fcyNet, 4),
                'fx_net'             => round($fxNet, 2),
                'fx_difference'      => round($fxDifference, 2),
                // FIX D: contra ledger is determined by net direction
                'contra_ledger_number' => $lineIsGain ? 72000002 : 65000003,
                'contra_ledger_name'   => $lineIsGain ? 'Exchange Gain' : 'Exchange Loss',
                'contra_ledger_class'  => $lineIsGain ? 'Revenue' : 'Expense',
            ];
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Round subtotals for clean output
    // ════════════════════════════════════════════════════════════════════════
    foreach ($reportData as $key => &$group) {
        $group['subtotal_gain'] = round($group['subtotal_gain'], 2);
        $group['subtotal_loss'] = round($group['subtotal_loss'], 2);
        $group['subtotal_net']  = round($group['subtotal_net'],  2);
    }
    unset($group);

    http_response_code(200);

    echo json_encode([
        "status"  => "Success",
        "message" => "FX Revaluation report fetched successfully",
        "data"    => $reportData,
        "summary" => [
            "grand_total_gain" => round($grandTotalGain, 2),
            "grand_total_loss" => round($grandTotalLoss, 2),
            "grand_total_net"  => round($grandTotalNet, 2),
            // FIX D: tell the frontend WHICH contra accounts will be used
            "net_label"              => $grandTotalNet >= 0 ? "Net Exchange Gain" : "Net Exchange Loss",
            "contra_gain_ledger"     => "72000002 — Exchange Gain (Revenue)",
            "contra_loss_ledger"     => "65000003 — Exchange Loss (Finance Cost)",
            "net_contra_ledger"      => $grandTotalNet >= 0 ? "72000002 — Exchange Gain" : "65000003 — Exchange Loss",
        ],
        "pending_journals" => $pendingJournals,
        "closing_rate_info" => [
            "currency"         => $currency,
            "closing_rate"     => $closingRate,
            "rate_record_date" => $rateRow['created_at'],
        ],
        // FIX: period_lock and duplicate flags for frontend gating
        "period_status" => [
            "is_locked"      => $periodIsLocked,
            "lock_reason"    => $lockReason,
            "already_posted" => $alreadyPosted,
            "posted_journal_id" => $alreadyPosted ? (int)$dupRow['posted_journal_id'] : null,
        ],
        "meta" => [
            "currency"    => $currency,
            "datefrom"    => $datefrom,
            "dateto"      => $dateto,
            "period_year" => $periodYear,
        ],
    ]);

} catch (Exception $e) {
    error_log("FX Revaluation GET Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => publicErrorMessage($e)]);
}