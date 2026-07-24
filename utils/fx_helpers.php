<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting_period_helpers.php';

const SMARTBOOKS_FX_UNREALIZED_GAIN_LEDGER = 72000002;
const SMARTBOOKS_FX_UNREALIZED_LOSS_LEDGER = 65000003;
const SMARTBOOKS_FX_REALIZED_GAIN_LEDGER = 72000005;
const SMARTBOOKS_FX_REALIZED_LOSS_LEDGER = 65000004;

function smartbooksFxAllowedCurrencies(): array
{
    return [
        'USD' => 'usd_rate',
        'EUR' => 'eur_rate',
        'GBP' => 'gbp_rate',
    ];
}

function smartbooksFxValidateDate(string $value, string $fieldName): string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("Enter a valid {$fieldName} in YYYY-MM-DD format.", 422);
    }

    return $value;
}

function smartbooksFxNormaliseCurrency(string $currency): string
{
    $currency = strtoupper(trim($currency));
    if (!array_key_exists($currency, smartbooksFxAllowedCurrencies())) {
        throw new RuntimeException('FX processing is available only for USD, EUR, or GBP.', 422);
    }

    return $currency;
}

function smartbooksFxTableExists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $exists;
}

function smartbooksFxSchemaReady(mysqli $conn): bool
{
    return smartbooksFxTableExists($conn, 'fx_revaluation_batches')
        && smartbooksFxTableExists($conn, 'fx_revaluation_lines');
}

function smartbooksFxRequireSchema(mysqli $conn): void
{
    if (!smartbooksFxSchemaReady($conn)) {
        throw new RuntimeException(
            'The FX database migration has not been applied. Run 20260723_exchange_gain_loss_backend.sql before posting.',
            503
        );
    }
}

function smartbooksFxRateRowToPayload(array $row, string $currency): array
{
    $rateColumn = smartbooksFxAllowedCurrencies()[$currency];
    $closingRate = (float) ($row[$rateColumn] ?? $row['closing_rate'] ?? 0);
    if ($closingRate <= 0) {
        throw new RuntimeException("The selected {$currency} exchange rate is invalid.", 422);
    }

    $effectiveDate = (string) ($row['effective_date'] ?? $row['created_at'] ?? '');
    $recordedAt = isset($row['recorded_at']) && $row['recorded_at'] !== ''
        ? (string) $row['recorded_at']
        : null;
    $recordedDate = $recordedAt ? substr($recordedAt, 0, 10) : null;

    return [
        'id' => (int) $row['id'],
        // rate_date remains for backward-compatible journal and frontend fields.
        'rate_date' => $effectiveDate,
        'effective_date' => $effectiveDate,
        'closing_rate' => $closingRate,
        'ngn_rate' => (float) $row['ngn_rate'],
        'usd_rate' => (float) $row['usd_rate'],
        'eur_rate' => (float) $row['eur_rate'],
        'gbp_rate' => (float) $row['gbp_rate'],
        'rate_source' => trim((string) ($row['rate_source'] ?? 'Manual entry')) ?: 'Manual entry',
        'source_reference' => isset($row['source_reference']) && trim((string) $row['source_reference']) !== ''
            ? trim((string) $row['source_reference'])
            : null,
        'recorded_at' => $recordedAt,
        'recorded_by' => isset($row['recorded_by']) && trim((string) $row['recorded_by']) !== ''
            ? trim((string) $row['recorded_by'])
            : (isset($row['created_by']) ? trim((string) $row['created_by']) : null),
        'entered_after_effective_date' => $recordedDate !== null && $recordedDate > $effectiveDate,
    ];
}

function smartbooksFxRateById(mysqli $conn, string $currency, int $rateId): array
{
    $currency = smartbooksFxNormaliseCurrency($currency);
    if ($rateId <= 0) {
        throw new RuntimeException('Select a valid closing-rate record.', 422);
    }

    $stmt = $conn->prepare(
        'SELECT id, effective_date, created_at, ngn_rate, usd_rate, eur_rate, gbp_rate,
                rate_source, source_reference, recorded_at, recorded_by, created_by
         FROM currency_table
         WHERE id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load the selected closing exchange rate. Apply the historical closing-rate migration first.', 503);
    }
    $stmt->bind_param('i', $rateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('The selected closing-rate record no longer exists.', 404);
    }

    return smartbooksFxRateRowToPayload($row, $currency);
}

function smartbooksFxRateForDate(
    mysqli $conn,
    string $currency,
    string $requestedDate,
    bool $exactDate = false
): array {
    $currency = smartbooksFxNormaliseCurrency($currency);
    $requestedDate = smartbooksFxValidateDate($requestedDate, 'rate effective date');

    if ($exactDate) {
        $sql = 'SELECT id, effective_date, created_at, ngn_rate, usd_rate, eur_rate, gbp_rate,
                       rate_source, source_reference, recorded_at, recorded_by, created_by
                FROM currency_table
                WHERE effective_date = ?
                ORDER BY id DESC
                LIMIT 1';
    } else {
        $sql = 'SELECT id, effective_date, created_at, ngn_rate, usd_rate, eur_rate, gbp_rate,
                       rate_source, source_reference, recorded_at, recorded_by, created_by
                FROM currency_table
                WHERE effective_date <= ?
                ORDER BY effective_date DESC, id DESC
                LIMIT 1';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load the closing exchange rate. Apply the historical closing-rate migration first.', 503);
    }
    $stmt->bind_param('s', $requestedDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $qualifier = $exactDate ? 'effective on' : 'effective on or before';
        throw new RuntimeException("No valid {$currency} exchange rate exists {$qualifier} {$requestedDate}.", 422);
    }

    return smartbooksFxRateRowToPayload($row, $currency);
}

function smartbooksFxRevaluableCategories(): array
{
    return [
        'BankAccounts' => [
            'title' => 'Bank Accounts',
            'sub_class' => 'Current Asset',
            'type' => 'Bank Accounts',
            'is_asset' => true,
        ],
        'OffshoreBankAccounts' => [
            'title' => 'Offshore Bank Accounts',
            'sub_class' => 'Current Asset',
            'type' => 'Offshore Bank Accounts',
            'is_asset' => true,
        ],
        'PettyCash' => [
            'title' => 'Petty Cash (FCY)',
            'sub_class' => 'Current Asset',
            'type' => 'Petty Cash',
            'is_asset' => true,
        ],
        'ServiceCustomers' => [
            'title' => 'Service Customers (Receivables)',
            'sub_class' => 'Current Asset',
            'type' => 'Service Customers',
            'is_asset' => true,
        ],
        'StrategicPartners' => [
            'title' => 'Strategic Partners',
            'sub_class' => 'Current Asset',
            'type' => 'Strategic Partners',
            'is_asset' => true,
        ],
        'Agents' => [
            'title' => 'Agents',
            'sub_class' => 'Current Asset',
            'type' => 'Agents',
            'is_asset' => true,
        ],
        'LoansAndSimilarDebts' => [
            'title' => 'Loans and Similar Debts',
            'sub_class' => 'Non-Current Liability',
            'type' => 'Loans and Similar Debts',
            'is_asset' => false,
        ],
        'SuppliersCreditors' => [
            'title' => 'Suppliers / Creditors',
            'sub_class' => 'Current Liability',
            'type' => 'Suppliers / Creditors',
            'is_asset' => false,
        ],
        'OutsourcingAgent' => [
            'title' => 'Outsourcing Agents',
            'sub_class' => 'Current Liability',
            'type' => 'Outsourcing Agent',
            'is_asset' => false,
        ],
    ];
}

function smartbooksFxLockedPeriod(mysqli $conn, string $postingDate): ?array
{
    return smartbooksLockedPeriodForDate($conn, $postingDate);
}

function smartbooksFxAssertPostingDateOpen(mysqli $conn, string $postingDate): void
{
    smartbooksAssertPostingDateOpen($conn, $postingDate, 'FX posting date');
}

function smartbooksFxLedgerByNumber(mysqli $conn, int $ledgerNumber): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type
         FROM ledger_table
         WHERE ledger_number = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load the configured FX ledger.', 500);
    }
    $stmt->bind_param('i', $ledgerNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function smartbooksFxRequiredLedger(
    mysqli $conn,
    int $ledgerNumber,
    string $label,
    ?string $expectedClass = null
): array {
    $ledger = smartbooksFxLedgerByNumber($conn, $ledgerNumber);
    if (!$ledger) {
        throw new RuntimeException("{$label} ledger ({$ledgerNumber}) is not configured.", 503);
    }
    if ($expectedClass !== null && strcasecmp(trim((string) $ledger['ledger_class']), $expectedClass) !== 0) {
        throw new RuntimeException(
            "{$label} ledger ({$ledgerNumber}) must be classified as {$expectedClass}.",
            503
        );
    }

    return $ledger;
}

function smartbooksFxActiveAdjustmentsByLedger(mysqli $conn, string $currency, string $asOfDate): array
{
    if (!smartbooksFxSchemaReady($conn)) {
        return [];
    }

    $currency = smartbooksFxNormaliseCurrency($currency);
    $asOfDate = smartbooksFxValidateDate($asOfDate, 'as-of date');

    $stmt = $conn->prepare(
        "SELECT l.ledger_number, COALESCE(SUM(l.adjustment_ngn), 0) AS active_adjustment_ngn
         FROM fx_revaluation_lines l
         INNER JOIN fx_revaluation_batches b ON b.id = l.batch_id
         WHERE b.currency = ?
           AND b.date_to <= ?
           AND b.journal_date <= ?
           AND (
                b.reversal_journal_id IS NULL
                OR b.reversal_date IS NULL
                OR b.reversal_date > ?
           )
         GROUP BY l.ledger_number"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load previous FX revaluation adjustments.', 500);
    }
    $stmt->bind_param('ssss', $currency, $asOfDate, $asOfDate, $asOfDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $adjustments = [];
    while ($row = $result->fetch_assoc()) {
        $adjustments[(int) $row['ledger_number']] = (float) $row['active_adjustment_ngn'];
    }
    $stmt->close();

    return $adjustments;
}

function smartbooksFxActiveBatchForClosingDate(
    mysqli $conn,
    string $currency,
    string $closingDate
): ?array {
    if (!smartbooksFxSchemaReady($conn)) {
        return null;
    }

    $currency = smartbooksFxNormaliseCurrency($currency);
    $closingDate = smartbooksFxValidateDate($closingDate, 'closing date');

    $stmt = $conn->prepare(
        "SELECT id, batch_code, journal_id, journal_date, reversal_journal_id, reversal_date, status
         FROM fx_revaluation_batches
         WHERE currency = ?
           AND date_to = ?
           AND journal_date <= ?
           AND (
                reversal_journal_id IS NULL
                OR reversal_date IS NULL
                OR reversal_date > ?
           )
         ORDER BY id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check the existing FX revaluation.', 500);
    }
    $stmt->bind_param('ssss', $currency, $closingDate, $closingDate, $closingDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function smartbooksFxCategoryKey(string $subClass, string $type): ?string
{
    foreach (smartbooksFxRevaluableCategories() as $key => $config) {
        if ($config['sub_class'] === $subClass && $config['type'] === $type) {
            return $key;
        }
    }

    return null;
}

function smartbooksFxLoadCurrencyBalances(
    mysqli $conn,
    string $currency,
    string $asOfDate
): array {
    $currency = smartbooksFxNormaliseCurrency($currency);
    $asOfDate = smartbooksFxValidateDate($asOfDate, 'closing date');
    $categories = smartbooksFxRevaluableCategories();

    $conditions = [];
    foreach ($categories as $config) {
        $subClass = $conn->real_escape_string($config['sub_class']);
        $type = $conn->real_escape_string($config['type']);
        $conditions[] = "(l.ledger_sub_class = '{$subClass}' AND l.ledger_type = '{$type}')";
    }
    $categoryWhere = implode(' OR ', $conditions);

    $stmt = $conn->prepare(
        "SELECT
            l.ledger_name,
            l.ledger_number,
            l.ledger_class,
            l.ledger_class_code,
            l.ledger_sub_class,
            l.ledger_type,
            COALESCE(SUM(CAST(m.debit AS DECIMAL(20,6)) - CAST(m.credit AS DECIMAL(20,6))), 0) AS fcy_balance,
            COALESCE(SUM(CAST(m.debit_ngn AS DECIMAL(20,6)) - CAST(m.credit_ngn AS DECIMAL(20,6))), 0) AS base_carrying_ngn
         FROM ledger_table l
         INNER JOIN main_journal_table m ON m.ledger_number = l.ledger_number
         WHERE m.journal_currency = ?
           AND m.journal_date <= ?
           AND ({$categoryWhere})
         GROUP BY
            l.ledger_name, l.ledger_number, l.ledger_class, l.ledger_class_code,
            l.ledger_sub_class, l.ledger_type
         HAVING ABS(COALESCE(SUM(CAST(m.debit AS DECIMAL(20,6)) - CAST(m.credit AS DECIMAL(20,6))), 0)) > 0.00005
         ORDER BY l.ledger_number ASC"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate the foreign-currency ledger balances.', 500);
    }
    $stmt->bind_param('ss', $currency, $asOfDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function smartbooksFxLedgerCurrencyPosition(
    mysqli $conn,
    int $ledgerNumber,
    string $currency,
    string $asOfDate
): array {
    if ($ledgerNumber <= 0) {
        throw new RuntimeException('Select a valid ledger.', 422);
    }

    $currency = smartbooksFxNormaliseCurrency($currency);
    $asOfDate = smartbooksFxValidateDate($asOfDate, 'as-of date');
    $ledger = smartbooksFxLedgerByNumber($conn, $ledgerNumber);
    if (!$ledger) {
        throw new RuntimeException('The selected ledger is no longer available.', 422);
    }

    $stmt = $conn->prepare(
        'SELECT
            COALESCE(SUM(CAST(debit AS DECIMAL(20,6)) - CAST(credit AS DECIMAL(20,6))), 0) AS fcy_balance,
            COALESCE(SUM(CAST(debit_ngn AS DECIMAL(20,6)) - CAST(credit_ngn AS DECIMAL(20,6))), 0) AS base_carrying_ngn
         FROM main_journal_table
         WHERE ledger_number = ?
           AND journal_currency = ?
           AND journal_date <= ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate the ledger currency position.', 500);
    }
    $stmt->bind_param('iss', $ledgerNumber, $currency, $asOfDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $fcyBalance = (float) ($row['fcy_balance'] ?? 0);
    $baseCarrying = (float) ($row['base_carrying_ngn'] ?? 0);
    $activeAdjustments = smartbooksFxActiveAdjustmentsByLedger($conn, $currency, $asOfDate);
    $priorAdjustment = (float) ($activeAdjustments[$ledgerNumber] ?? 0);
    $currentCarrying = $baseCarrying + $priorAdjustment;
    $carryingRate = abs($fcyBalance) > 0.0000001
        ? $currentCarrying / $fcyBalance
        : 0.0;

    return [
        'ledger' => $ledger,
        'currency' => $currency,
        'as_of_date' => $asOfDate,
        'fcy_balance' => round($fcyBalance, 6),
        'base_carrying_ngn' => round($baseCarrying, 2),
        'prior_revaluation_adjustment_ngn' => round($priorAdjustment, 2),
        'current_carrying_ngn' => round($currentCarrying, 2),
        'carrying_rate' => round($carryingRate, 8),
    ];
}

function smartbooksFxBuildRevaluationPreview(
    mysqli $conn,
    string $dateFrom,
    string $dateTo,
    string $currency,
    array $rateData
): array {
    $dateFrom = smartbooksFxValidateDate($dateFrom, 'start date');
    $dateTo = smartbooksFxValidateDate($dateTo, 'end date');
    if ($dateFrom > $dateTo) {
        throw new RuntimeException('The start date cannot be later than the end date.', 422);
    }
    $currency = smartbooksFxNormaliseCurrency($currency);
    $closingRate = (float) $rateData['closing_rate'];

    $categories = smartbooksFxRevaluableCategories();
    $reportData = [];
    foreach ($categories as $key => $config) {
        $reportData[$key] = [
            'title' => $config['title'],
            'is_asset' => $config['is_asset'],
            'records' => [],
            'subtotal_gain' => 0.0,
            'subtotal_loss' => 0.0,
            'subtotal_net' => 0.0,
        ];
    }

    $priorAdjustments = smartbooksFxActiveAdjustmentsByLedger($conn, $currency, $dateTo);
    $balances = smartbooksFxLoadCurrencyBalances($conn, $currency, $dateTo);
    $pendingJournals = [];
    $grandTotalGain = 0.0;
    $grandTotalLoss = 0.0;
    $grandTotalNet = 0.0;

    foreach ($balances as $row) {
        $categoryKey = smartbooksFxCategoryKey(
            trim((string) $row['ledger_sub_class']),
            trim((string) $row['ledger_type'])
        );
        if ($categoryKey === null) {
            continue;
        }

        $ledgerNumber = (int) $row['ledger_number'];
        $fcyBalance = (float) $row['fcy_balance'];
        $baseCarrying = (float) $row['base_carrying_ngn'];
        $priorAdjustment = (float) ($priorAdjustments[$ledgerNumber] ?? 0);
        $currentCarrying = $baseCarrying + $priorAdjustment;
        $closingValue = $fcyBalance * $closingRate;
        $adjustment = round($closingValue - $currentCarrying, 2);
        $gain = $adjustment > 0 ? $adjustment : 0.0;
        $loss = $adjustment < 0 ? abs($adjustment) : 0.0;
        $averageBookRate = abs($fcyBalance) > 0.0000001
            ? abs($currentCarrying / $fcyBalance)
            : 0.0;

        $record = [
            'ledger_name' => (string) $row['ledger_name'],
            'ledger_number' => $ledgerNumber,
            'ledger_sub_class' => (string) $row['ledger_sub_class'],
            'ledger_type' => (string) $row['ledger_type'],
            'ledger_class' => (string) $row['ledger_class'],
            'journal_currency' => $currency,
            'fcy_net_balance' => round($fcyBalance, 4),
            'avg_book_rate' => round($averageBookRate, 6),
            'base_book_value_ngn' => round($baseCarrying, 2),
            'prior_revaluation_adjustment_ngn' => round($priorAdjustment, 2),
            'ngn_book_value' => round($currentCarrying, 2),
            'closing_rate' => round($closingRate, 6),
            'ngn_closing_value' => round($closingValue, 2),
            'fx_difference' => $adjustment,
            'fx_gain' => round($gain, 2),
            'fx_loss' => round($loss, 2),
            'fx_net' => $adjustment,
            'fx_type' => 'unrealized',
        ];

        $reportData[$categoryKey]['records'][] = $record;
        $reportData[$categoryKey]['subtotal_gain'] += $gain;
        $reportData[$categoryKey]['subtotal_loss'] += $loss;
        $reportData[$categoryKey]['subtotal_net'] += $adjustment;
        $grandTotalGain += $gain;
        $grandTotalLoss += $loss;
        $grandTotalNet += $adjustment;

        if (abs($adjustment) >= 0.01) {
            $isGain = $adjustment > 0;
            $pendingJournals[] = [
                'ledger_name' => (string) $row['ledger_name'],
                'ledger_number' => $ledgerNumber,
                'ledger_class' => (string) $row['ledger_class'],
                'ledger_class_code' => (int) $row['ledger_class_code'],
                'ledger_sub_class' => (string) $row['ledger_sub_class'],
                'ledger_type' => (string) $row['ledger_type'],
                'is_asset' => (bool) $categories[$categoryKey]['is_asset'],
                'fcy_net' => round($fcyBalance, 4),
                'base_carrying_ngn' => round($baseCarrying, 2),
                'prior_revaluation_adjustment_ngn' => round($priorAdjustment, 2),
                'current_carrying_ngn' => round($currentCarrying, 2),
                'closing_value_ngn' => round($closingValue, 2),
                'fx_net' => $adjustment,
                'fx_difference' => $adjustment,
                'ledger_debit_ngn' => $adjustment > 0 ? $adjustment : 0.0,
                'ledger_credit_ngn' => $adjustment < 0 ? abs($adjustment) : 0.0,
                'contra_debit_ngn' => $adjustment < 0 ? abs($adjustment) : 0.0,
                'contra_credit_ngn' => $adjustment > 0 ? $adjustment : 0.0,
                'contra_ledger_number' => $isGain
                    ? SMARTBOOKS_FX_UNREALIZED_GAIN_LEDGER
                    : SMARTBOOKS_FX_UNREALIZED_LOSS_LEDGER,
                'contra_ledger_name' => $isGain ? 'Exchange Gain' : 'Exchange Loss',
                'contra_ledger_class' => $isGain ? 'Revenue' : 'Expense',
                'fx_type' => 'unrealized',
            ];
        }
    }

    foreach ($reportData as &$group) {
        $group['subtotal_gain'] = round((float) $group['subtotal_gain'], 2);
        $group['subtotal_loss'] = round((float) $group['subtotal_loss'], 2);
        $group['subtotal_net'] = round((float) $group['subtotal_net'], 2);
    }
    unset($group);

    $previewBasis = [
        'datefrom' => $dateFrom,
        'dateto' => $dateTo,
        'currency' => $currency,
        'rate_id' => (int) ($rateData['id'] ?? 0),
        'rate_date' => (string) $rateData['rate_date'],
        'closing_rate' => round($closingRate, 8),
        'pending_journals' => $pendingJournals,
    ];

    return [
        'data' => $reportData,
        'summary' => [
            'grand_total_gain' => round($grandTotalGain, 2),
            'grand_total_loss' => round($grandTotalLoss, 2),
            'grand_total_net' => round($grandTotalNet, 2),
            'net_label' => $grandTotalNet >= 0 ? 'Net Exchange Gain' : 'Net Exchange Loss',
            'contra_gain_ledger' => SMARTBOOKS_FX_UNREALIZED_GAIN_LEDGER . ' — Exchange Gain (Revenue)',
            'contra_loss_ledger' => SMARTBOOKS_FX_UNREALIZED_LOSS_LEDGER . ' — Exchange Loss (Finance Cost)',
            'net_contra_ledger' => $grandTotalNet >= 0
                ? SMARTBOOKS_FX_UNREALIZED_GAIN_LEDGER . ' — Exchange Gain'
                : SMARTBOOKS_FX_UNREALIZED_LOSS_LEDGER . ' — Exchange Loss',
            'fx_type' => 'unrealized',
        ],
        'pending_journals' => $pendingJournals,
        'preview_token' => hash('sha256', json_encode($previewBasis, JSON_PRESERVE_ZERO_FRACTION)),
    ];
}

function smartbooksFxNextJournalId(mysqli $conn): int
{
    return smartbooksNextJournalId($conn);
}

function smartbooksFxInsertJournalHeader(
    mysqli $conn,
    int $journalId,
    string $transactionType,
    string $journalDate,
    string $journalCurrency,
    string $description,
    float $totalDebitNgn,
    float $totalCreditNgn,
    string $rateDate,
    float $rate,
    float $debitOthers,
    float $creditOthers,
    string $costCenter,
    string $userEmail
): void {
    $stmt = $conn->prepare(
        'INSERT INTO journal_table
            (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description,
             debit, credit, rate_date, rate, debit_ngn, credit_ngn, debit_others, credit_others,
             cost_center, created_by, updated_by)
         VALUES (?, \'Journal\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the FX journal header.', 500);
    }

    $stmt->bind_param(
        'issssddsdddddsss',
        $journalId,
        $transactionType,
        $journalDate,
        $journalCurrency,
        $description,
        $totalDebitNgn,
        $totalCreditNgn,
        $rateDate,
        $rate,
        $totalDebitNgn,
        $totalCreditNgn,
        $debitOthers,
        $creditOthers,
        $costCenter,
        $userEmail,
        $userEmail
    );
    $stmt->execute();
    $stmt->close();
}

function smartbooksFxInsertJournalLine(
    mysqli $conn,
    int $journalId,
    string $transactionType,
    string $journalDate,
    string $journalCurrency,
    string $description,
    float $debit,
    float $credit,
    string $rateDate,
    float $rate,
    float $debitNgn,
    float $creditNgn,
    array $rateData,
    string $costCenter,
    array $ledger,
    string $userEmail
): int {
    $stmt = $conn->prepare(
        'INSERT INTO main_journal_table
            (journal_id, journal_type, transaction_type, journal_date, journal_currency, journal_description,
             debit, credit, rate_date, rate, debit_ngn, credit_ngn,
             ngn_rate, usd_rate, eur_rate, gbp_rate, cost_center,
             ledger_name, ledger_number, ledger_class, ledger_class_code, ledger_sub_class, ledger_type,
             created_by, updated_by)
         VALUES (?, \'Journal\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare an FX journal line.', 500);
    }

    $ledgerName = (string) $ledger['ledger_name'];
    $ledgerNumber = (int) $ledger['ledger_number'];
    $ledgerClass = (string) $ledger['ledger_class'];
    $ledgerClassCode = (int) $ledger['ledger_class_code'];
    $ledgerSubClass = (string) $ledger['ledger_sub_class'];
    $ledgerType = (string) $ledger['ledger_type'];
    $ngnRate = (float) $rateData['ngn_rate'];
    $usdRate = (float) $rateData['usd_rate'];
    $eurRate = (float) $rateData['eur_rate'];
    $gbpRate = (float) $rateData['gbp_rate'];

    $stmt->bind_param(
        'issssddsdddddddssisissss',
        $journalId,
        $transactionType,
        $journalDate,
        $journalCurrency,
        $description,
        $debit,
        $credit,
        $rateDate,
        $rate,
        $debitNgn,
        $creditNgn,
        $ngnRate,
        $usdRate,
        $eurRate,
        $gbpRate,
        $costCenter,
        $ledgerName,
        $ledgerNumber,
        $ledgerClass,
        $ledgerClassCode,
        $ledgerSubClass,
        $ledgerType,
        $userEmail,
        $userEmail
    );
    $stmt->execute();
    $lineId = (int) $stmt->insert_id;
    $stmt->close();

    return $lineId;
}
