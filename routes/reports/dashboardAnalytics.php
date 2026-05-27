<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authorization.php';

header('Content-Type: application/json');

/**
 * Executive Dashboard Analytics
 *
 * This route deliberately performs only simple, schema-safe SQL reads.
 * Monetary fields in the supplied database are stored as VARCHAR in several tables;
 * numeric conversions and analytical groupings are therefore completed in PHP to
 * avoid SQL conversion/grouping failures terminating the entire dashboard request.
 */

function dashboardFetchRows(mysqli $conn, string $section, string $sql, string $types = '', array $params = []): array
{
    try {
        $statement = $conn->prepare($sql);
        if ($types !== '') {
            $statement->bind_param($types, ...$params);
        }
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        return $rows;
    } catch (Throwable $exception) {
        error_log('[Smartbooks Dashboard Analytics][' . $section . '] ' . $exception->getMessage());
        throw $exception;
    }
}

function dashboardOptionalRows(mysqli $conn, string $section, string $sql, string $types = '', array $params = []): array
{
    try {
        return dashboardFetchRows($conn, $section, $sql, $types, $params);
    } catch (Throwable $exception) {
        // Ancillary control/status cards must never make the financial dashboard unusable.
        return [];
    }
}

function dashboardDate(string $value, string $field): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Invalid ' . $field . '. Use YYYY-MM-DD.', 400);
    }
    return $date;
}

function dashboardMoney($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($clean === null || $clean === '' || $clean === '-' || !is_numeric($clean)) {
        return 0.0;
    }
    return round((float) $clean, 2);
}

function dashboardIsTrue($value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'locked', 'active'], true);
}

function dashboardCurrency(string $currency): string
{
    $normalised = strtoupper(trim($currency));
    return in_array($normalised, ['NGN', 'USD', 'GBP', 'EUR'], true) ? $normalised : 'NGN';
}

function dashboardRateFor(string $currency, array $rates): float
{
    $currency = dashboardCurrency($currency);
    if ($currency === 'USD') {
        return max(1.0, dashboardMoney($rates['usd_rate'] ?? 1));
    }
    if ($currency === 'GBP') {
        return max(1.0, dashboardMoney($rates['gbp_rate'] ?? 1));
    }
    if ($currency === 'EUR') {
        return max(1.0, dashboardMoney($rates['eur_rate'] ?? 1));
    }
    return 1.0;
}

function dashboardJournalDebit(array $row, bool $isConsolidated): float
{
    return dashboardMoney($isConsolidated ? ($row['debit_ngn'] ?? 0) : ($row['debit'] ?? 0));
}

function dashboardJournalCredit(array $row, bool $isConsolidated): float
{
    return dashboardMoney($isConsolidated ? ($row['credit_ngn'] ?? 0) : ($row['credit'] ?? 0));
}

function dashboardMatchesBasis(string $currency, bool $isConsolidated, string $basis): bool
{
    return $isConsolidated || dashboardCurrency($currency) === $basis;
}

function dashboardInvoiceValues(array $invoice, bool $isConsolidated, array $rates): array
{
    $nativeAmount = dashboardMoney($invoice['invoice_amount'] ?? 0);
    $status = strtolower(trim((string) ($invoice['status'] ?? '')));
    $nativePaid = $status === 'paid'
        ? $nativeAmount
        : min($nativeAmount, max(0.0, dashboardMoney($invoice['paid'] ?? 0)));
    $factor = $isConsolidated ? dashboardRateFor((string) ($invoice['currency'] ?? 'NGN'), $rates) : 1.0;
    $amount = round($nativeAmount * $factor, 2);
    $paid = round($nativePaid * $factor, 2);
    return [
        'amount' => $amount,
        'paid' => $paid,
        'outstanding' => round(max(0.0, $amount - $paid), 2),
    ];
}

function dashboardMonthKey(string $date): string
{
    return strlen($date) >= 7 ? substr($date, 0, 7) : '';
}

function dashboardSortMonthRows(array $rows): array
{
    ksort($rows);
    return array_values($rows);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    requireRole(
        $user,
        [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
        'Dashboard analytics is available to Admin and Controller users only.'
    );

    $today = date('Y-m-d');
    $dateFromText = trim((string) ($_GET['date_from'] ?? (date('Y') . '-01-01')));
    $dateToText = trim((string) ($_GET['date_to'] ?? $today));
    $fromDate = dashboardDate($dateFromText, 'date_from');
    $toDate = dashboardDate($dateToText, 'date_to');

    if ($toDate < $fromDate) {
        throw new InvalidArgumentException('The end date must be on or after the start date.', 400);
    }
    if ($fromDate->diff($toDate)->days > 1096) {
        throw new InvalidArgumentException('Dashboard analysis is limited to a maximum three-year date range.', 400);
    }

    $basis = strtoupper(trim((string) ($_GET['basis'] ?? 'NGN_EQUIVALENT')));
    $allowedBasis = ['NGN_EQUIVALENT', 'NGN', 'USD', 'GBP', 'EUR'];
    if (!in_array($basis, $allowedBasis, true)) {
        throw new InvalidArgumentException('Invalid dashboard currency view.', 400);
    }

    $isConsolidated = $basis === 'NGN_EQUIVALENT';
    $displayCurrency = $isConsolidated ? 'NGN' : $basis;
    $basisLabel = $isConsolidated ? 'NGN Equivalent · consolidated' : $basis . ' · native currency';

    // The selected closing rate set is used only for invoice exposures in consolidated mode.
    $rates = dashboardFetchRows(
        $conn,
        'rates-as-at-date',
        'SELECT id, ngn_rate, usd_rate, gbp_rate, eur_rate, created_at '
        . 'FROM currency_table '
        . 'WHERE created_at <= ? '
        . 'ORDER BY created_at DESC, id DESC '
        . 'LIMIT 1',
        's',
        [$dateToText]
    )[0] ?? [];
    if (!$rates) {
        $rates = dashboardFetchRows(
            $conn,
            'rates-latest-fallback',
            'SELECT id, ngn_rate, usd_rate, gbp_rate, eur_rate, created_at '
            . 'FROM currency_table '
            . 'ORDER BY created_at DESC, id DESC '
            . 'LIMIT 1'
        )[0] ?? [];
    }
    foreach (['ngn_rate', 'usd_rate', 'gbp_rate', 'eur_rate'] as $rateKey) {
        $rates[$rateKey] = dashboardMoney($rates[$rateKey] ?? ($rateKey === 'ngn_rate' ? 1 : 0));
    }

    // Basic row retrieval only; calculations happen below in PHP because amount columns are VARCHAR in the schema.
    $periodJournals = dashboardFetchRows(
        $conn,
        'period-journal-rows',
        'SELECT id, journal_id, journal_type, journal_date, journal_currency, debit, credit, debit_ngn, credit_ngn, ledger_name, ledger_number, ledger_class, ledger_sub_class, ledger_type '
        . 'FROM main_journal_table '
        . 'WHERE journal_date BETWEEN ? AND ? '
        . 'ORDER BY journal_date ASC, id ASC',
        'ss',
        [$dateFromText, $dateToText]
    );
    $balanceJournals = dashboardFetchRows(
        $conn,
        'closing-journal-rows',
        'SELECT id, journal_id, journal_type, journal_date, journal_currency, debit, credit, debit_ngn, credit_ngn, ledger_name, ledger_number, ledger_class, ledger_sub_class, ledger_type '
        . 'FROM main_journal_table '
        . 'WHERE journal_date <= ? '
        . 'ORDER BY journal_date ASC, id ASC',
        's',
        [$dateToText]
    );
    $periodInvoices = dashboardFetchRows(
        $conn,
        'period-invoice-rows',
        'SELECT id, invoice_number, invoice_date, due_date, clients_id, clients_name, currency, status, paid, invoice_amount '
        . 'FROM invoice_table '
        . 'WHERE invoice_date BETWEEN ? AND ? '
        . 'ORDER BY invoice_date DESC, id DESC',
        'ss',
        [$dateFromText, $dateToText]
    );
    $closingInvoices = dashboardFetchRows(
        $conn,
        'closing-invoice-rows',
        'SELECT id, invoice_number, invoice_date, due_date, clients_id, clients_name, currency, status, paid, invoice_amount '
        . 'FROM invoice_table '
        . 'WHERE invoice_date <= ? '
        . 'ORDER BY invoice_date DESC, id DESC',
        's',
        [$dateToText]
    );

    $revenue = 0.0;
    $expenses = 0.0;
    $journalIds = [];
    $ledgerLineCount = 0;
    $monthlyPerformanceMap = [];
    $transactionMixMap = [];
    $currencyMixMap = [];

    foreach ($periodJournals as $row) {
        $sourceCurrency = dashboardCurrency((string) ($row['journal_currency'] ?? 'NGN'));
        $allDebit = dashboardMoney($row['debit_ngn'] ?? 0);
        $allCredit = dashboardMoney($row['credit_ngn'] ?? 0);
        $currencyMixMap[$sourceCurrency] = $currencyMixMap[$sourceCurrency] ?? [
            'currency' => $sourceCurrency,
            'revenue_ngn' => 0.0,
            'expenses_ngn' => 0.0,
            'activity_ngn' => 0.0,
        ];
        $isRevenueLine = strtolower(trim((string) ($row['ledger_type'] ?? ''))) === 'revenue'
            || strtolower(trim((string) ($row['ledger_sub_class'] ?? ''))) === 'revenue';
        $isExpenseLine = strtolower(trim((string) ($row['ledger_class'] ?? ''))) === 'expense'
            || strtolower(trim((string) ($row['ledger_type'] ?? ''))) === 'expense';
        if ($isRevenueLine) {
            $currencyMixMap[$sourceCurrency]['revenue_ngn'] += $allCredit - $allDebit;
        }
        if ($isExpenseLine) {
            $currencyMixMap[$sourceCurrency]['expenses_ngn'] += $allDebit - $allCredit;
        }
        $currencyMixMap[$sourceCurrency]['activity_ngn'] += ($allDebit + $allCredit) / 2;

        if (!dashboardMatchesBasis($sourceCurrency, $isConsolidated, $basis)) {
            continue;
        }
        $debit = dashboardJournalDebit($row, $isConsolidated);
        $credit = dashboardJournalCredit($row, $isConsolidated);
        $ledgerLineCount++;
        $journalIds[(string) ($row['journal_id'] ?? '')] = true;
        if ($isRevenueLine) {
            $revenue += $credit - $debit;
        }
        if ($isExpenseLine) {
            $expenses += $debit - $credit;
        }
        $month = dashboardMonthKey((string) ($row['journal_date'] ?? ''));
        if ($month !== '') {
            $monthlyPerformanceMap[$month] = $monthlyPerformanceMap[$month] ?? [
                'month' => $month, 'revenue' => 0.0, 'expenses' => 0.0, 'net_result' => 0.0,
            ];
            if ($isRevenueLine) {
                $monthlyPerformanceMap[$month]['revenue'] += $credit - $debit;
            }
            if ($isExpenseLine) {
                $monthlyPerformanceMap[$month]['expenses'] += $debit - $credit;
            }
        }
        $journalType = trim((string) ($row['journal_type'] ?? 'Other')) ?: 'Other';
        $transactionMixMap[$journalType] = $transactionMixMap[$journalType] ?? [
            'journal_type' => $journalType, 'journal_ids' => [], 'transaction_value' => 0.0,
        ];
        $transactionMixMap[$journalType]['journal_ids'][(string) ($row['journal_id'] ?? '')] = true;
        $transactionMixMap[$journalType]['transaction_value'] += ($debit + $credit) / 2;
    }
    foreach ($monthlyPerformanceMap as &$monthlyRow) {
        $monthlyRow['revenue'] = round($monthlyRow['revenue'], 2);
        $monthlyRow['expenses'] = round($monthlyRow['expenses'], 2);
        $monthlyRow['net_result'] = round($monthlyRow['revenue'] - $monthlyRow['expenses'], 2);
    }
    unset($monthlyRow);
    $monthlyPerformance = dashboardSortMonthRows($monthlyPerformanceMap);
    $transactionMix = [];
    foreach ($transactionMixMap as $row) {
        $transactionMix[] = [
            'journal_type' => $row['journal_type'],
            'journal_count' => count($row['journal_ids']),
            'transaction_value' => round($row['transaction_value'], 2),
        ];
    }
    usort($transactionMix, static fn(array $a, array $b): int => $b['transaction_value'] <=> $a['transaction_value']);
    $currencyMix = array_values(array_map(static function (array $row): array {
        $row['revenue_ngn'] = round($row['revenue_ngn'], 2);
        $row['expenses_ngn'] = round($row['expenses_ngn'], 2);
        $row['activity_ngn'] = round($row['activity_ngn'], 2);
        return $row;
    }, $currencyMixMap));
    usort($currencyMix, static fn(array $a, array $b): int => $b['activity_ngn'] <=> $a['activity_ngn']);

    $billing = ['invoice_count' => 0, 'billed_clients' => [], 'invoiced' => 0.0, 'collected' => 0.0];
    $monthlyBillingMap = [];
    $invoiceStatusMap = [];
    $recentInvoices = [];
    foreach ($periodInvoices as $invoice) {
        $sourceCurrency = dashboardCurrency((string) ($invoice['currency'] ?? 'NGN'));
        if (!dashboardMatchesBasis($sourceCurrency, $isConsolidated, $basis)) {
            continue;
        }
        $values = dashboardInvoiceValues($invoice, $isConsolidated, $rates);
        $billing['invoice_count']++;
        $billing['billed_clients'][(string) ($invoice['clients_id'] ?? $invoice['clients_name'] ?? '')] = true;
        $billing['invoiced'] += $values['amount'];
        $billing['collected'] += $values['paid'];
        $month = dashboardMonthKey((string) ($invoice['invoice_date'] ?? ''));
        if ($month !== '') {
            $monthlyBillingMap[$month] = $monthlyBillingMap[$month] ?? ['month' => $month, 'invoiced' => 0.0, 'collected' => 0.0];
            $monthlyBillingMap[$month]['invoiced'] += $values['amount'];
            $monthlyBillingMap[$month]['collected'] += $values['paid'];
        }
        $status = trim((string) ($invoice['status'] ?? 'Unknown')) ?: 'Unknown';
        $invoiceStatusMap[$status] = $invoiceStatusMap[$status] ?? ['status' => $status, 'invoice_count' => 0, 'amount' => 0.0, 'outstanding' => 0.0];
        $invoiceStatusMap[$status]['invoice_count']++;
        $invoiceStatusMap[$status]['amount'] += $values['amount'];
        $invoiceStatusMap[$status]['outstanding'] += $values['outstanding'];
        if (count($recentInvoices) < 6) {
            $recentInvoices[] = [
                'invoice_number' => $invoice['invoice_number'] ?? '',
                'invoice_date' => $invoice['invoice_date'] ?? '',
                'due_date' => $invoice['due_date'] ?? '',
                'clients_name' => $invoice['clients_name'] ?? '',
                'source_currency' => $sourceCurrency,
                'status' => $status,
                'amount' => $values['amount'],
                'outstanding' => $values['outstanding'],
            ];
        }
    }
    foreach ($monthlyBillingMap as &$monthlyRow) {
        $monthlyRow['invoiced'] = round($monthlyRow['invoiced'], 2);
        $monthlyRow['collected'] = round($monthlyRow['collected'], 2);
    }
    unset($monthlyRow);
    $monthlyBilling = dashboardSortMonthRows($monthlyBillingMap);
    $invoiceStatus = array_values(array_map(static function (array $row): array {
        $row['amount'] = round($row['amount'], 2);
        $row['outstanding'] = round($row['outstanding'], 2);
        return $row;
    }, $invoiceStatusMap));
    usort($invoiceStatus, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);

    $outstanding = 0.0;
    $overdue = 0.0;
    $openInvoiceCount = 0;
    $agingMap = [
        'Current' => ['bucket' => 'Current', 'invoice_count' => 0, 'amount' => 0.0],
        '1–30 days' => ['bucket' => '1–30 days', 'invoice_count' => 0, 'amount' => 0.0],
        '31–60 days' => ['bucket' => '31–60 days', 'invoice_count' => 0, 'amount' => 0.0],
        '61–90 days' => ['bucket' => '61–90 days', 'invoice_count' => 0, 'amount' => 0.0],
        '90+ days' => ['bucket' => '90+ days', 'invoice_count' => 0, 'amount' => 0.0],
    ];
    $clientExposureMap = [];
    foreach ($closingInvoices as $invoice) {
        $sourceCurrency = dashboardCurrency((string) ($invoice['currency'] ?? 'NGN'));
        if (!dashboardMatchesBasis($sourceCurrency, $isConsolidated, $basis)) {
            continue;
        }
        $values = dashboardInvoiceValues($invoice, $isConsolidated, $rates);
        if ($values['outstanding'] <= 0) {
            continue;
        }
        $openInvoiceCount++;
        $outstanding += $values['outstanding'];
        $dueText = (string) ($invoice['due_date'] ?? '');
        $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueText);
        $daysOverdue = ($dueDate && $dueDate < $toDate) ? (int) $dueDate->diff($toDate)->days : 0;
        if ($daysOverdue > 0) {
            $overdue += $values['outstanding'];
        }
        $bucket = 'Current';
        if ($daysOverdue > 90) {
            $bucket = '90+ days';
        } elseif ($daysOverdue > 60) {
            $bucket = '61–90 days';
        } elseif ($daysOverdue > 30) {
            $bucket = '31–60 days';
        } elseif ($daysOverdue > 0) {
            $bucket = '1–30 days';
        }
        $agingMap[$bucket]['invoice_count']++;
        $agingMap[$bucket]['amount'] += $values['outstanding'];
        $clientKey = (string) ($invoice['clients_id'] ?? '') . '|' . (string) ($invoice['clients_name'] ?? '');
        $clientExposureMap[$clientKey] = $clientExposureMap[$clientKey] ?? [
            'clients_id' => $invoice['clients_id'] ?? '',
            'clients_name' => $invoice['clients_name'] ?? '',
            'source_currency' => $isConsolidated ? 'Multiple / NGN Eqv.' : $sourceCurrency,
            'invoice_count' => 0,
            'billed' => 0.0,
            'outstanding' => 0.0,
            'overdue' => 0.0,
        ];
        $clientExposureMap[$clientKey]['invoice_count']++;
        $clientExposureMap[$clientKey]['billed'] += $values['amount'];
        $clientExposureMap[$clientKey]['outstanding'] += $values['outstanding'];
        if ($daysOverdue > 0) {
            $clientExposureMap[$clientKey]['overdue'] += $values['outstanding'];
        }
    }
    $aging = array_values(array_map(static function (array $row): array {
        $row['amount'] = round($row['amount'], 2);
        return $row;
    }, $agingMap));
    $clientExposure = array_values(array_map(static function (array $row): array {
        $row['billed'] = round($row['billed'], 2);
        $row['outstanding'] = round($row['outstanding'], 2);
        $row['overdue'] = round($row['overdue'], 2);
        return $row;
    }, $clientExposureMap));
    usort($clientExposure, static fn(array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);
    $clientExposure = array_slice($clientExposure, 0, 8);

    $cashBalance = 0.0;
    $cashMap = [];
    $positionMap = [];
    foreach ($balanceJournals as $row) {
        $sourceCurrency = dashboardCurrency((string) ($row['journal_currency'] ?? 'NGN'));
        if (!dashboardMatchesBasis($sourceCurrency, $isConsolidated, $basis)) {
            continue;
        }
        $debit = dashboardJournalDebit($row, $isConsolidated);
        $credit = dashboardJournalCredit($row, $isConsolidated);
        $ledgerClass = trim((string) ($row['ledger_class'] ?? 'Other')) ?: 'Other';
        $normalSideBalance = in_array(strtolower($ledgerClass), ['asset', 'expense'], true)
            ? $debit - $credit
            : $credit - $debit;
        $positionMap[$ledgerClass] = ($positionMap[$ledgerClass] ?? 0.0) + $normalSideBalance;
        $ledgerType = strtolower(trim((string) ($row['ledger_type'] ?? '')));
        if (strtolower($ledgerClass) === 'asset' && in_array($ledgerType, ['bank accounts', 'petty cash', 'bank'], true)) {
            $balance = $debit - $credit;
            $cashBalance += $balance;
            $cashKey = (string) ($row['ledger_number'] ?? '') . '|' . (string) ($row['ledger_name'] ?? '') . '|' . $sourceCurrency;
            $cashMap[$cashKey] = $cashMap[$cashKey] ?? [
                'ledger_name' => $row['ledger_name'] ?? 'Cash account',
                'ledger_number' => $row['ledger_number'] ?? '',
                'source_currency' => $sourceCurrency,
                'balance' => 0.0,
            ];
            $cashMap[$cashKey]['balance'] += $balance;
        }
    }
    $positionOrder = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
    $position = [];
    foreach ($positionOrder as $class) {
        if (array_key_exists($class, $positionMap)) {
            $position[] = ['ledger_class' => $class, 'balance' => round($positionMap[$class], 2)];
            unset($positionMap[$class]);
        }
    }
    foreach ($positionMap as $class => $balance) {
        $position[] = ['ledger_class' => $class, 'balance' => round($balance, 2)];
    }
    $cashAccounts = array_values(array_map(static function (array $row): array {
        $row['balance'] = round($row['balance'], 2);
        return $row;
    }, $cashMap));
    usort($cashAccounts, static fn(array $a, array $b): int => abs($b['balance']) <=> abs($a['balance']));
    $cashAccounts = array_slice($cashAccounts, 0, 8);

    $periodRows = dashboardOptionalRows(
        $conn,
        'period-controls',
        'SELECT id, start_date, end_date, is_locked, is_active, lock_reason '
        . 'FROM accounting_periods '
        . 'WHERE start_date <= ? AND end_date >= ? '
        . 'ORDER BY start_date DESC, id DESC',
        'ss',
        [$dateToText, $dateFromText]
    );
    $overlappingPeriods = 0;
    $lockedPeriods = 0;
    $closingPeriod = null;
    foreach ($periodRows as $row) {
        if (!dashboardIsTrue($row['is_active'] ?? '')) {
            continue;
        }
        $overlappingPeriods++;
        if (dashboardIsTrue($row['is_locked'] ?? '')) {
            $lockedPeriods++;
        }
        if ($closingPeriod === null && ($row['start_date'] ?? '') <= $dateToText && ($row['end_date'] ?? '') >= $dateToText) {
            $closingPeriod = $row;
        }
    }
    $periodControls = [
        'overlapping_periods' => $overlappingPeriods,
        'locked_periods' => $lockedPeriods,
        'closing_period' => $closingPeriod,
    ];

    $netResult = round($revenue - $expenses, 2);
    $billingInvoiced = round($billing['invoiced'], 2);
    $billingCollected = round($billing['collected'], 2);
    $collectionRate = $billingInvoiced > 0 ? round(($billingCollected / $billingInvoiced) * 100, 1) : 0.0;
    $outstanding = round($outstanding, 2);
    $overdue = round($overdue, 2);
    $overdueRatio = $outstanding > 0 ? round(($overdue / $outstanding) * 100, 1) : 0.0;

    jsonResponse([
        'status' => 'Success',
        'message' => 'Dashboard analytics loaded successfully.',
        'meta' => [
            'date_from' => $dateFromText,
            'date_to' => $dateToText,
            'basis' => $basis,
            'basis_label' => $basisLabel,
            'display_currency' => $displayCurrency,
            'generated_at' => date('Y-m-d H:i:s'),
            'measurement_note' => 'Performance metrics follow the selected period. Open receivables, ageing, cash and position are measured as at the ending date. Consolidated invoice exposures use the latest exchange rates available at that date.',
        ],
        'data' => [
            'executive' => [
                'revenue' => round($revenue, 2),
                'expenses' => round($expenses, 2),
                'net_result' => $netResult,
                'profit_margin' => $revenue != 0.0 ? round(($netResult / $revenue) * 100, 1) : 0.0,
                'invoiced' => $billingInvoiced,
                'collected' => $billingCollected,
                'collection_rate' => $collectionRate,
                'outstanding' => $outstanding,
                'overdue' => $overdue,
                'overdue_ratio' => $overdueRatio,
                'cash_balance' => round($cashBalance, 2),
                'invoice_count' => (int) $billing['invoice_count'],
                'open_invoice_count' => $openInvoiceCount,
                'billed_clients' => count($billing['billed_clients']),
                'journal_count' => count($journalIds),
                'ledger_line_count' => $ledgerLineCount,
            ],
            'monthly_performance' => $monthlyPerformance,
            'monthly_billing' => $monthlyBilling,
            'receivable_aging' => $aging,
            'invoice_status' => $invoiceStatus,
            'cash_accounts' => $cashAccounts,
            'client_exposure' => $clientExposure,
            'financial_position' => $position,
            'transaction_mix' => $transactionMix,
            'currency_mix' => $currencyMix,
            'latest_rates' => $rates,
            'period_controls' => $periodControls,
            'recent_invoices' => $recentInvoices,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[Smartbooks Dashboard Analytics][fatal] ' . $exception->getMessage());
    $statusCode = (int) $exception->getCode();
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => publicErrorMessage($exception),
    ], $statusCode);
}
