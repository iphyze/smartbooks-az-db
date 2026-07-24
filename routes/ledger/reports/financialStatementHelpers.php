<?php

declare(strict_types=1);

/**
 * Shared financial-statement presentation rules.
 *
 * Journal storage remains debit/credit based. These helpers only convert posted
 * journal balances into conventional report signs and keep translated Balance
 * Sheets balanced after fiscal-year closing.
 */

function smartbooksFinancialStatementPnlCategories(): array
{
    return [
        'Revenue' => [
            'title' => 'Revenue',
            'sub_class' => 'Revenue',
            'type' => 'Revenue',
            'nature' => 'income',
        ],
        'CostOfServices' => [
            'title' => 'Cost of Services',
            'sub_class' => 'Cost of Services',
            'type' => 'Cost of Services',
            'nature' => 'expense',
        ],
        'Administrative' => [
            'title' => 'Administrative Expenses',
            'sub_class' => 'Administrative Expenses',
            'type' => 'Administrative Expenses',
            'nature' => 'expense',
        ],
        'Selling' => [
            'title' => 'Selling Expenses',
            'sub_class' => 'Selling Expenses',
            'type' => 'Selling Expenses',
            'nature' => 'expense',
        ],
        'OtherIncome' => [
            'title' => 'Other Income',
            'sub_class' => 'Revenue',
            'type' => 'Other Income',
            'nature' => 'income',
        ],
        'Depreciation' => [
            'title' => 'Depreciation & Amortization',
            'sub_class' => 'Depreciation Expenses',
            'type' => 'Depreciation, Amortization & Impairment (Expenses)',
            'nature' => 'expense',
        ],
        'FinanceCost' => [
            'title' => 'Finance Cost',
            'sub_class' => 'Finance Cost',
            'type' => 'Finance Cost',
            'nature' => 'expense',
        ],
        'Taxation' => [
            'title' => 'Income & Other Taxes',
            'sub_class' => 'Taxation',
            'type' => 'Income & Other Taxes',
            'nature' => 'expense',
        ],
    ];
}

function smartbooksFinancialStatementPnlSqlCondition(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias supplied for the financial statement query.');
    }

    $prefix = $alias === '' ? '' : $alias . '.';
    $parts = [];
    foreach (smartbooksFinancialStatementPnlCategories() as $category) {
        $subClass = str_replace("'", "''", (string) $category['sub_class']);
        $type = str_replace("'", "''", (string) $category['type']);
        $parts[] = "({$prefix}ledger_sub_class = '{$subClass}' AND {$prefix}ledger_type = '{$type}')";
    }

    return '(' . implode(' OR ', $parts) . ')';
}

function smartbooksFinancialStatementPnlCategoryKey(string $subClass, string $type): ?string
{
    $subClass = trim($subClass);
    $type = trim($type);

    foreach (smartbooksFinancialStatementPnlCategories() as $key => $category) {
        if ($subClass === $category['sub_class'] && $type === $category['type']) {
            return $key;
        }
    }

    return null;
}

function smartbooksFinancialStatementPnlAmount(float $debit, float $credit, string $nature): float
{
    return $nature === 'income'
        ? $credit - $debit
        : $debit - $credit;
}

function smartbooksFinancialStatementPnlSummary(array $totals): array
{
    $value = static fn (string $key): float => (float) ($totals[$key] ?? 0.0);

    $ebitda = $value('Revenue')
        - $value('CostOfServices')
        - $value('Administrative')
        - $value('Selling')
        + $value('OtherIncome');
    $operatingProfit = $ebitda - $value('Depreciation');
    $profitBeforeTax = $operatingProfit - $value('FinanceCost');
    $profitAfterTax = $profitBeforeTax - $value('Taxation');

    return [
        'ebitda' => $ebitda,
        'operating_profit' => $operatingProfit,
        'profit_before_tax' => $profitBeforeTax,
        'profit_after_tax' => $profitAfterTax,
    ];
}

/**
 * Convert debit-minus-credit storage into the normal Balance Sheet presentation.
 * Assets stay debit-positive; contra assets stay naturally negative; credit-nature
 * equity and liability balances are presented as positive amounts.
 */
function smartbooksFinancialStatementBalanceSheetValue(
    string $subClass,
    string $type,
    float $rawDebitLessCredit
): float {
    unset($type);

    if (in_array(trim($subClass), ['Equity', 'Current Liability', 'Non-Current Liability', 'Taxation'], true)) {
        return $rawDebitLessCredit * -1;
    }

    return $rawDebitLessCredit;
}

function smartbooksFinancialStatementValidateRateColumn(string $rateColumn): void
{
    if (!in_array($rateColumn, ['ngn_rate', 'usd_rate', 'eur_rate', 'gbp_rate'], true)) {
        throw new InvalidArgumentException('Invalid financial statement rate column.');
    }
}

/**
 * Fail explicitly instead of silently omitting a posted journal line whose
 * stored display-currency rate is blank, non-numeric, or zero.
 */
function smartbooksFinancialStatementAssertStoredRates(
    mysqli $conn,
    string $rateColumn,
    string $dateTo,
    ?string $dateFrom = null
): void {
    smartbooksFinancialStatementValidateRateColumn($rateColumn);

    $where = [
        'm.journal_date <= ?',
        "(TRIM(CAST(m.{$rateColumn} AS CHAR)) = ''
          OR TRIM(CAST(m.{$rateColumn} AS CHAR)) NOT REGEXP '^[0-9]+([.][0-9]+)?$'
          OR CAST(m.{$rateColumn} AS DECIMAL(24,8)) <= 0)",
    ];
    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 'm.journal_date >= ?';
    }

    $sql = 'SELECT COUNT(*) AS invalid_count, MIN(m.id) AS first_invalid_line_id
            FROM main_journal_table m
            WHERE ' . implode(' AND ', $where);
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB Error (stored-rate validation): ' . $conn->error);
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $stmt->bind_param('ss', $dateTo, $dateFrom);
    } else {
        $stmt->bind_param('s', $dateTo);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $invalidCount = (int) ($row['invalid_count'] ?? 0);
    if ($invalidCount > 0) {
        $currency = strtoupper(substr($rateColumn, 0, 3));
        $lineId = (int) ($row['first_invalid_line_id'] ?? 0);
        throw new RuntimeException(
            "{$invalidCount} posted journal line(s) contain an invalid stored {$currency} rate" .
            ($lineId > 0 ? "; first affected line ID: {$lineId}" : '') .
            '. Correct the journal rate snapshot before running this report.',
            422
        );
    }
}

function smartbooksFinancialStatementLatestActiveClosureEnd(mysqli $conn, string $asOfDate): ?string
{
    $stmt = $conn->prepare(
        "SELECT period_end
         FROM fiscal_year_closures
         WHERE status = 'Posted'
           AND reversal_journal_id IS NULL
           AND period_end <= ?
         ORDER BY period_end DESC, id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('DB Error (fiscal-year closure lookup): ' . $conn->error);
    }

    $stmt->bind_param('s', $asOfDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string) $row['period_end'] : null;
}

/**
 * Return the translated net P&L credit balance for a date range.
 * Positive values are profits; negative values are losses.
 */
function smartbooksFinancialStatementTranslatedPnlNet(
    mysqli $conn,
    string $rateColumn,
    string $dateTo,
    ?string $dateFrom = null,
    bool $excludeFiscalCloseJournals = false
): float {
    smartbooksFinancialStatementValidateRateColumn($rateColumn);

    $where = [
        'm.journal_date <= ?',
        smartbooksFinancialStatementPnlSqlCondition('m'),
    ];
    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 'm.journal_date >= ?';
    }

    if ($excludeFiscalCloseJournals) {
        $where[] = 'NOT EXISTS (
            SELECT 1
            FROM fiscal_year_closures c
            WHERE c.journal_id = m.journal_id
               OR c.reversal_journal_id = m.journal_id
        )';
    }

    $sql = "
        SELECT COALESCE(SUM(
            (m.credit_ngn / NULLIF(m.{$rateColumn}, 0))
            - (m.debit_ngn / NULLIF(m.{$rateColumn}, 0))
        ), 0) AS net_profit_loss
        FROM main_journal_table m
        WHERE " . implode("\n          AND ", $where);

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB Error (translated P&L): ' . $conn->error);
    }

    if ($dateFrom !== null && $dateFrom !== '') {
        $stmt->bind_param('ss', $dateTo, $dateFrom);
    } else {
        $stmt->bind_param('s', $dateTo);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float) ($row['net_profit_loss'] ?? 0.0);
}

/**
 * Return the display-currency debit/credit difference created by translating
 * otherwise balanced functional-currency journals with their stored line rates.
 *
 * Journals that are already unbalanced in NGN are deliberately excluded so a
 * translation adjustment can never conceal a broken source journal.
 */
function smartbooksFinancialStatementTranslatedJournalDifference(
    mysqli $conn,
    string $rateColumn,
    string $asOfDate
): float {
    smartbooksFinancialStatementValidateRateColumn($rateColumn);

    if ($rateColumn === 'ngn_rate') {
        return 0.0;
    }

    $sql = "
        SELECT COALESCE(SUM(j.translated_difference), 0) AS translated_difference
        FROM (
            SELECT
                m.journal_id,
                SUM(m.debit_ngn - m.credit_ngn) AS functional_difference,
                SUM(
                    (m.debit_ngn / NULLIF(m.{$rateColumn}, 0))
                    - (m.credit_ngn / NULLIF(m.{$rateColumn}, 0))
                ) AS translated_difference
            FROM main_journal_table m
            WHERE m.journal_date <= ?
            GROUP BY m.journal_id
            HAVING ABS(functional_difference) <= 0.01
        ) j";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB Error (translated journal difference): ' . $conn->error);
    }
    $stmt->bind_param('s', $asOfDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float) ($row['translated_difference'] ?? 0.0);
}

/**
 * Split translated P&L balances between actual unclosed earnings and the
 * translation residual created when historical P&L and closing journals use
 * different stored rates. This prevents closed profit from appearing again as
 * Current Year Earnings while preserving a balanced foreign-currency report.
 */
function smartbooksFinancialStatementEquityBridge(
    mysqli $conn,
    string $rateColumn,
    string $asOfDate
): array {
    $latestClosureEnd = smartbooksFinancialStatementLatestActiveClosureEnd($conn, $asOfDate);
    $currentEarningsFrom = null;

    if ($latestClosureEnd !== null) {
        $nextDay = DateTimeImmutable::createFromFormat('!Y-m-d', $latestClosureEnd);
        if (!$nextDay) {
            throw new RuntimeException('Stored fiscal-year closure date is invalid.');
        }
        $currentEarningsFrom = $nextDay->modify('+1 day')->format('Y-m-d');
    }

    $translatedPnlResidual = smartbooksFinancialStatementTranslatedPnlNet(
        $conn,
        $rateColumn,
        $asOfDate,
        null,
        false
    );
    $currentYearEarnings = smartbooksFinancialStatementTranslatedPnlNet(
        $conn,
        $rateColumn,
        $asOfDate,
        $currentEarningsFrom,
        true
    );
    $closedPnlTranslationResidual = $translatedPnlResidual - $currentYearEarnings;
    $translatedJournalDifference = smartbooksFinancialStatementTranslatedJournalDifference(
        $conn,
        $rateColumn,
        $asOfDate
    );
    $currencyTranslationAdjustment = $closedPnlTranslationResidual + $translatedJournalDifference;

    foreach ([
        'translatedPnlResidual',
        'currentYearEarnings',
        'closedPnlTranslationResidual',
        'translatedJournalDifference',
        'currencyTranslationAdjustment',
    ] as $name) {
        if (abs($$name) < 0.005) {
            $$name = 0.0;
        }
    }

    return [
        'current_year_earnings' => $currentYearEarnings,
        'currency_translation_adjustment' => $currencyTranslationAdjustment,
        'translated_pnl_residual' => $translatedPnlResidual,
        'closed_pnl_translation_residual' => $closedPnlTranslationResidual,
        'translated_journal_difference' => $translatedJournalDifference,
        'latest_active_closure_end' => $latestClosureEnd,
        'current_earnings_from' => $currentEarningsFrom,
    ];
}
