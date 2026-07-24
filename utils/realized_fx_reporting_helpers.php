<?php
declare(strict_types=1);

require_once __DIR__ . '/fx_helpers.php';

/**
 * Realized FX reporting is journal-led. Only settlement journals that were
 * generated automatically or manually linked and validated are included.
 */
function smartbooksRealizedFxRequiredColumns(): array
{
    return [
        'invoice_currency',
        'invoice_amount_settled',
        'payment_currency',
        'payment_amount_received',
        'cross_currency_rate',
        'payment_rate_date',
        'payment_currency_rate_ngn',
        'settlement_value_ngn',
        'carrying_value_settled_ngn',
        'realized_fx_gain_ngn',
        'realized_fx_loss_ngn',
        'realized_fx_ledger_number',
        'journal_origin',
        'journal_validation_status',
        'reversal_journal_id',
    ];
}

function smartbooksRealizedFxColumnExists(mysqli $conn, string $tableName, string $columnName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $exists;
}

function smartbooksRealizedFxSchemaReady(mysqli $conn): bool
{
    if (!smartbooksFxTableExists($conn, 'invoice_payments')) {
        return false;
    }

    foreach (smartbooksRealizedFxRequiredColumns() as $columnName) {
        if (!smartbooksRealizedFxColumnExists($conn, 'invoice_payments', $columnName)) {
            return false;
        }
    }

    return smartbooksFxTableExists($conn, 'journal_table')
        && smartbooksFxTableExists($conn, 'main_journal_table');
}

function smartbooksRealizedFxRequireSchema(mysqli $conn): void
{
    if (!smartbooksRealizedFxSchemaReady($conn)) {
        throw new RuntimeException(
            'The mixed-currency payment and manual journal-linking migrations must be applied before realized FX reporting can be used.',
            503
        );
    }
}

function smartbooksRealizedFxEmptySummary(string $currency, string $dateFrom, string $dateTo): array
{
    return [
        'gross_gain_posted_ngn' => 0.0,
        'gross_loss_posted_ngn' => 0.0,
        'gain_reversals_ngn' => 0.0,
        'loss_reversals_ngn' => 0.0,
        'gain_account_net_ngn' => 0.0,
        'loss_account_net_ngn' => 0.0,
        'grand_total_gain' => 0.0,
        'grand_total_loss' => 0.0,
        'grand_total_net' => 0.0,
        'net_label' => 'No Realized Exchange Gain or Loss',
        'settlement_journal_count' => 0,
        'reversal_journal_count' => 0,
        'automatic_settlement_count' => 0,
        'manual_settlement_count' => 0,
        'payment_count' => 0,
        'fx_event_count' => 0,
        'currency' => $currency,
        'datefrom' => $dateFrom,
        'dateto' => $dateTo,
        'fx_type' => 'realized',
        'source_scope' => 'validated_invoice_payment_journals',
    ];
}

function smartbooksRealizedFxPendingManualPayments(
    mysqli $conn,
    string $dateFrom,
    string $dateTo,
    string $currency
): array {
    $stmt = $conn->prepare(
        "SELECT
            id,
            payment_code,
            invoice_number,
            payment_date,
            invoice_currency,
            invoice_amount_settled,
            payment_currency,
            payment_amount_received,
            settlement_value_ngn,
            carrying_value_settled_ngn,
            realized_fx_gain_ngn,
            realized_fx_loss_ngn,
            journal_id,
            journal_origin,
            journal_validation_status,
            transaction_reference
         FROM invoice_payments
         WHERE invoice_currency = ?
           AND payment_date BETWEEN ? AND ?
           AND status = 'Active'
           AND (
                journal_id IS NULL
                OR journal_validation_status <> 'Validated'
           )
           AND (
                ABS(COALESCE(realized_fx_gain_ngn, 0)) >= 0.01
                OR ABS(COALESCE(realized_fx_loss_ngn, 0)) >= 0.01
           )
         ORDER BY payment_date DESC, id DESC"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load unposted realized FX payments.', 500);
    }

    $stmt->bind_param('sss', $currency, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();

    $records = [];
    $expectedGain = 0.0;
    $expectedLoss = 0.0;
    while ($row = $result->fetch_assoc()) {
        $gain = round((float) ($row['realized_fx_gain_ngn'] ?? 0), 2);
        $loss = round((float) ($row['realized_fx_loss_ngn'] ?? 0), 2);
        $expectedGain += $gain;
        $expectedLoss += $loss;

        $records[] = [
            'payment_id' => (int) $row['id'],
            'payment_code' => (string) $row['payment_code'],
            'invoice_number' => (string) $row['invoice_number'],
            'payment_date' => (string) $row['payment_date'],
            'invoice_currency' => (string) $row['invoice_currency'],
            'invoice_amount_settled' => (float) $row['invoice_amount_settled'],
            'payment_currency' => (string) $row['payment_currency'],
            'payment_amount_received' => (float) $row['payment_amount_received'],
            'settlement_value_ngn' => (float) $row['settlement_value_ngn'],
            'carrying_value_settled_ngn' => (float) $row['carrying_value_settled_ngn'],
            'expected_realized_fx_gain_ngn' => $gain,
            'expected_realized_fx_loss_ngn' => $loss,
            'journal_id' => $row['journal_id'] !== null ? (int) $row['journal_id'] : null,
            'journal_origin' => (string) ($row['journal_origin'] ?? 'Unposted'),
            'journal_validation_status' => (string) ($row['journal_validation_status'] ?? 'Pending'),
            'transaction_reference' => $row['transaction_reference'] !== null
                ? (string) $row['transaction_reference']
                : null,
            'reporting_status' => 'Excluded until a journal is posted and validated',
        ];
    }
    $stmt->close();

    $net = round($expectedGain - $expectedLoss, 2);
    return [
        'records' => $records,
        'summary' => [
            'payment_count' => count($records),
            'expected_gain_ngn' => round($expectedGain, 2),
            'expected_loss_ngn' => round($expectedLoss, 2),
            'expected_net_ngn' => $net,
            'net_label' => $net >= 0 ? 'Expected Unposted Exchange Gain' : 'Expected Unposted Exchange Loss',
            'included_in_realized_totals' => false,
        ],
    ];
}

function smartbooksRealizedFxJournalRows(
    mysqli $conn,
    string $dateFrom,
    string $dateTo,
    string $currency
): array {
    $gainLedger = SMARTBOOKS_FX_REALIZED_GAIN_LEDGER;
    $lossLedger = SMARTBOOKS_FX_REALIZED_LOSS_LEDGER;

    $sql = "
        SELECT
            p.id AS payment_id,
            p.payment_code,
            p.invoice_number,
            p.payment_date,
            p.invoice_currency,
            p.invoice_amount_settled,
            p.payment_currency,
            p.payment_amount_received,
            p.cross_currency_rate,
            p.payment_rate_date,
            p.payment_currency_rate_ngn,
            p.settlement_value_ngn,
            p.carrying_value_settled_ngn,
            p.realized_fx_gain_ngn AS expected_gain_ngn,
            p.realized_fx_loss_ngn AS expected_loss_ngn,
            p.transaction_reference,
            p.status AS payment_status,
            p.journal_origin,
            p.journal_validation_status,
            p.journal_id AS original_journal_id,
            p.reversal_journal_id,
            p.journal_id AS event_journal_id,
            'Settlement' AS event_type,
            h.journal_type,
            h.transaction_type,
            h.journal_description,
            h.journal_date AS header_journal_date,
            m.id AS journal_line_id,
            m.journal_date,
            m.ledger_name,
            m.ledger_number,
            m.debit_ngn,
            m.credit_ngn
        FROM invoice_payments p
        INNER JOIN journal_table h ON h.journal_id = p.journal_id
        INNER JOIN main_journal_table m ON m.journal_id = p.journal_id
        WHERE p.invoice_currency = ?
          AND m.journal_date BETWEEN ? AND ?
          AND p.journal_id IS NOT NULL
          AND p.journal_origin IN ('Automatic', 'Manual')
          AND p.journal_validation_status IN ('Validated', 'Reversed')
          AND m.ledger_number IN ({$gainLedger}, {$lossLedger})

        UNION ALL

        SELECT
            p.id AS payment_id,
            p.payment_code,
            p.invoice_number,
            p.payment_date,
            p.invoice_currency,
            p.invoice_amount_settled,
            p.payment_currency,
            p.payment_amount_received,
            p.cross_currency_rate,
            p.payment_rate_date,
            p.payment_currency_rate_ngn,
            p.settlement_value_ngn,
            p.carrying_value_settled_ngn,
            p.realized_fx_gain_ngn AS expected_gain_ngn,
            p.realized_fx_loss_ngn AS expected_loss_ngn,
            p.transaction_reference,
            p.status AS payment_status,
            p.journal_origin,
            p.journal_validation_status,
            p.journal_id AS original_journal_id,
            p.reversal_journal_id,
            p.reversal_journal_id AS event_journal_id,
            'Reversal' AS event_type,
            h.journal_type,
            h.transaction_type,
            h.journal_description,
            h.journal_date AS header_journal_date,
            m.id AS journal_line_id,
            m.journal_date,
            m.ledger_name,
            m.ledger_number,
            m.debit_ngn,
            m.credit_ngn
        FROM invoice_payments p
        INNER JOIN journal_table h ON h.journal_id = p.reversal_journal_id
        INNER JOIN main_journal_table m ON m.journal_id = p.reversal_journal_id
        WHERE p.invoice_currency = ?
          AND m.journal_date BETWEEN ? AND ?
          AND p.reversal_journal_id IS NOT NULL
          AND p.journal_origin IN ('Automatic', 'Manual')
          AND p.journal_validation_status = 'Reversed'
          AND m.ledger_number IN ({$gainLedger}, {$lossLedger})

        ORDER BY journal_date DESC, event_journal_id DESC, journal_line_id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the realized FX journal report.', 500);
    }
    $stmt->bind_param('ssssss', $currency, $dateFrom, $dateTo, $currency, $dateFrom, $dateTo);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function smartbooksRealizedFxBuildReport(
    mysqli $conn,
    string $dateFrom,
    string $dateTo,
    string $currency
): array {
    $dateFrom = smartbooksFxValidateDate($dateFrom, 'start date');
    $dateTo = smartbooksFxValidateDate($dateTo, 'end date');
    if ($dateFrom > $dateTo) {
        throw new RuntimeException('The start date cannot be later than the end date.', 422);
    }
    $currency = smartbooksFxNormaliseCurrency($currency);
    smartbooksRealizedFxRequireSchema($conn);

    $rows = smartbooksRealizedFxJournalRows($conn, $dateFrom, $dateTo, $currency);
    $events = [];
    $warnings = [];

    foreach ($rows as $row) {
        $journalId = (int) $row['event_journal_id'];
        $eventType = (string) $row['event_type'];
        $eventKey = $eventType . ':' . $journalId . ':' . (int) $row['payment_id'];

        if (!isset($events[$eventKey])) {
            $events[$eventKey] = [
                'payment_id' => (int) $row['payment_id'],
                'payment_code' => (string) $row['payment_code'],
                'invoice_number' => (string) $row['invoice_number'],
                'payment_date' => (string) $row['payment_date'],
                'invoice_currency' => (string) $row['invoice_currency'],
                'invoice_amount_settled' => (float) $row['invoice_amount_settled'],
                'payment_currency' => (string) $row['payment_currency'],
                'payment_amount_received' => (float) $row['payment_amount_received'],
                'cross_currency_rate' => (float) $row['cross_currency_rate'],
                'payment_rate_date' => (string) $row['payment_rate_date'],
                'payment_currency_rate_ngn' => $row['payment_currency_rate_ngn'] !== null
                    ? (float) $row['payment_currency_rate_ngn']
                    : null,
                'settlement_value_ngn' => (float) $row['settlement_value_ngn'],
                'carrying_value_settled_ngn' => (float) $row['carrying_value_settled_ngn'],
                'expected_realized_fx_gain_ngn' => (float) $row['expected_gain_ngn'],
                'expected_realized_fx_loss_ngn' => (float) $row['expected_loss_ngn'],
                'transaction_reference' => $row['transaction_reference'] !== null
                    ? (string) $row['transaction_reference']
                    : null,
                'payment_status' => (string) $row['payment_status'],
                'journal_origin' => (string) $row['journal_origin'],
                'journal_validation_status' => (string) $row['journal_validation_status'],
                'original_journal_id' => (int) $row['original_journal_id'],
                'reversal_journal_id' => $row['reversal_journal_id'] !== null
                    ? (int) $row['reversal_journal_id']
                    : null,
                'event_type' => $eventType,
                'journal_id' => $journalId,
                'journal_date' => (string) $row['journal_date'],
                'header_journal_date' => (string) $row['header_journal_date'],
                'journal_type' => (string) $row['journal_type'],
                'transaction_type' => (string) $row['transaction_type'],
                'journal_description' => (string) $row['journal_description'],
                'gain_credit_ngn' => 0.0,
                'gain_debit_ngn' => 0.0,
                'loss_debit_ngn' => 0.0,
                'loss_credit_ngn' => 0.0,
                'signed_fx_ngn' => 0.0,
                'fx_gain_ngn' => 0.0,
                'fx_loss_ngn' => 0.0,
                'fx_effect' => 'None',
                'lines' => [],
            ];
        }

        $ledgerNumber = (int) $row['ledger_number'];
        $debitNgn = round((float) $row['debit_ngn'], 2);
        $creditNgn = round((float) $row['credit_ngn'], 2);

        if ($ledgerNumber === SMARTBOOKS_FX_REALIZED_GAIN_LEDGER) {
            $events[$eventKey]['gain_credit_ngn'] += $creditNgn;
            $events[$eventKey]['gain_debit_ngn'] += $debitNgn;
        } elseif ($ledgerNumber === SMARTBOOKS_FX_REALIZED_LOSS_LEDGER) {
            $events[$eventKey]['loss_debit_ngn'] += $debitNgn;
            $events[$eventKey]['loss_credit_ngn'] += $creditNgn;
        }

        $events[$eventKey]['lines'][] = [
            'journal_line_id' => (int) $row['journal_line_id'],
            'ledger_name' => (string) $row['ledger_name'],
            'ledger_number' => $ledgerNumber,
            'debit_ngn' => $debitNgn,
            'credit_ngn' => $creditNgn,
        ];
    }

    $grossGainPosted = 0.0;
    $grossLossPosted = 0.0;
    $gainReversals = 0.0;
    $lossReversals = 0.0;
    $settlementJournalIds = [];
    $reversalJournalIds = [];
    $automaticSettlementIds = [];
    $manualSettlementIds = [];
    $paymentIds = [];

    foreach ($events as $eventKey => &$event) {
        $event['gain_credit_ngn'] = round((float) $event['gain_credit_ngn'], 2);
        $event['gain_debit_ngn'] = round((float) $event['gain_debit_ngn'], 2);
        $event['loss_debit_ngn'] = round((float) $event['loss_debit_ngn'], 2);
        $event['loss_credit_ngn'] = round((float) $event['loss_credit_ngn'], 2);

        $signedFx = round(
            $event['gain_credit_ngn']
            - $event['gain_debit_ngn']
            - $event['loss_debit_ngn']
            + $event['loss_credit_ngn'],
            2
        );
        $event['signed_fx_ngn'] = $signedFx;
        $event['fx_gain_ngn'] = $signedFx > 0 ? $signedFx : 0.0;
        $event['fx_loss_ngn'] = $signedFx < 0 ? abs($signedFx) : 0.0;

        if ($event['event_type'] === 'Settlement') {
            $event['fx_effect'] = $signedFx > 0
                ? 'Realized Gain'
                : ($signedFx < 0 ? 'Realized Loss' : 'No Realized FX');
            $settlementJournalIds[$event['journal_id']] = true;
            if (strcasecmp((string) $event['journal_origin'], 'Automatic') === 0) {
                $automaticSettlementIds[$event['journal_id']] = true;
            } elseif (strcasecmp((string) $event['journal_origin'], 'Manual') === 0) {
                $manualSettlementIds[$event['journal_id']] = true;
            }

            $expectedSigned = round(
                (float) $event['expected_realized_fx_gain_ngn']
                - (float) $event['expected_realized_fx_loss_ngn'],
                2
            );
            if (abs($signedFx - $expectedSigned) > 0.01) {
                $warnings[] = [
                    'code' => 'REALIZED_FX_JOURNAL_MISMATCH',
                    'payment_id' => (int) $event['payment_id'],
                    'payment_code' => (string) $event['payment_code'],
                    'journal_id' => (int) $event['journal_id'],
                    'message' => 'The posted realized FX journal amount does not match the settlement amount stored on the payment.',
                ];
            }
        } else {
            $event['fx_effect'] = $signedFx > 0
                ? 'Realized Loss Reversal'
                : ($signedFx < 0 ? 'Realized Gain Reversal' : 'No Realized FX Reversal');
            $reversalJournalIds[$event['journal_id']] = true;

            $expectedSigned = round(
                -(
                    (float) $event['expected_realized_fx_gain_ngn']
                    - (float) $event['expected_realized_fx_loss_ngn']
                ),
                2
            );
            if (abs($signedFx - $expectedSigned) > 0.01) {
                $warnings[] = [
                    'code' => 'REALIZED_FX_REVERSAL_MISMATCH',
                    'payment_id' => (int) $event['payment_id'],
                    'payment_code' => (string) $event['payment_code'],
                    'journal_id' => (int) $event['journal_id'],
                    'message' => 'The realized FX reversal does not exactly reverse the amount stored on the payment.',
                ];
            }
        }

        if ($event['header_journal_date'] !== $event['journal_date']) {
            $warnings[] = [
                'code' => 'JOURNAL_DATE_MISMATCH',
                'payment_id' => (int) $event['payment_id'],
                'payment_code' => (string) $event['payment_code'],
                'journal_id' => (int) $event['journal_id'],
                'message' => 'The journal header date and realized FX journal-line date do not match.',
            ];
        }

        $grossGainPosted += $event['gain_credit_ngn'];
        $gainReversals += $event['gain_debit_ngn'];
        $grossLossPosted += $event['loss_debit_ngn'];
        $lossReversals += $event['loss_credit_ngn'];
        $paymentIds[$event['payment_id']] = true;
    }
    unset($event);

    $gainAccountNet = round($grossGainPosted - $gainReversals, 2);
    $lossAccountNet = round($grossLossPosted - $lossReversals, 2);

    // A debit balance on the gain account is an unfavourable period effect;
    // a credit balance on the loss account is a favourable period effect.
    $totalGain = round(max($gainAccountNet, 0) + max(-$lossAccountNet, 0), 2);
    $totalLoss = round(max($lossAccountNet, 0) + max(-$gainAccountNet, 0), 2);
    $net = round($totalGain - $totalLoss, 2);

    $summary = [
        'gross_gain_posted_ngn' => round($grossGainPosted, 2),
        'gross_loss_posted_ngn' => round($grossLossPosted, 2),
        'gain_reversals_ngn' => round($gainReversals, 2),
        'loss_reversals_ngn' => round($lossReversals, 2),
        'gain_account_net_ngn' => $gainAccountNet,
        'loss_account_net_ngn' => $lossAccountNet,
        'grand_total_gain' => $totalGain,
        'grand_total_loss' => $totalLoss,
        'grand_total_net' => $net,
        'net_label' => abs($net) < 0.01
            ? 'No Net Realized Exchange Gain or Loss'
            : ($net > 0 ? 'Net Realized Exchange Gain' : 'Net Realized Exchange Loss'),
        'settlement_journal_count' => count($settlementJournalIds),
        'reversal_journal_count' => count($reversalJournalIds),
        'automatic_settlement_count' => count($automaticSettlementIds),
        'manual_settlement_count' => count($manualSettlementIds),
        'payment_count' => count($paymentIds),
        'fx_event_count' => count($events),
        'currency' => $currency,
        'datefrom' => $dateFrom,
        'dateto' => $dateTo,
        'fx_type' => 'realized',
        'source_scope' => 'validated_invoice_payment_journals',
    ];

    $pending = smartbooksRealizedFxPendingManualPayments($conn, $dateFrom, $dateTo, $currency);
    if ($pending['summary']['payment_count'] > 0) {
        $warnings[] = [
            'code' => 'UNPOSTED_REALIZED_FX_PAYMENTS',
            'message' => $pending['summary']['payment_count'] .
                ' payment(s) have an expected realized FX amount but are excluded because no validated journal is linked.',
        ];
    }

    return [
        'data' => array_values($events),
        'summary' => $summary,
        'pending_manual_journals' => $pending['records'],
        'pending_manual_summary' => $pending['summary'],
        'warnings' => $warnings,
        'meta' => [
            'currency' => $currency,
            'datefrom' => $dateFrom,
            'dateto' => $dateTo,
            'fx_type' => 'realized',
            'posting_scope' => 'read_only_report',
            'included_sources' => ['Automatic', 'Manual Validated'],
            'excluded_sources' => ['Unposted', 'Manual Pending', 'Manual Unvalidated'],
            'schema_ready' => true,
        ],
    ];
}

function smartbooksFxBuildCombinedSummary(array $realizedSummary, array $unrealizedSummary): array
{
    $realizedGain = round((float) ($realizedSummary['grand_total_gain'] ?? 0), 2);
    $realizedLoss = round((float) ($realizedSummary['grand_total_loss'] ?? 0), 2);
    $realizedNet = round((float) ($realizedSummary['grand_total_net'] ?? ($realizedGain - $realizedLoss)), 2);

    $unrealizedGain = round((float) ($unrealizedSummary['grand_total_gain'] ?? 0), 2);
    $unrealizedLoss = round((float) ($unrealizedSummary['grand_total_loss'] ?? 0), 2);
    $unrealizedNet = round((float) ($unrealizedSummary['grand_total_net'] ?? ($unrealizedGain - $unrealizedLoss)), 2);

    $totalGain = round($realizedGain + $unrealizedGain, 2);
    $totalLoss = round($realizedLoss + $unrealizedLoss, 2);
    $totalNet = round($realizedNet + $unrealizedNet, 2);

    return [
        'realized_gain_ngn' => $realizedGain,
        'realized_loss_ngn' => $realizedLoss,
        'realized_net_ngn' => $realizedNet,
        'unrealized_gain_ngn' => $unrealizedGain,
        'unrealized_loss_ngn' => $unrealizedLoss,
        'unrealized_net_ngn' => $unrealizedNet,
        'grand_total_gain' => $totalGain,
        'grand_total_loss' => $totalLoss,
        'grand_total_net' => $totalNet,
        'total_fx_impact_ngn' => $totalNet,
        'net_label' => abs($totalNet) < 0.01
            ? 'No Net Exchange Gain or Loss'
            : ($totalNet > 0 ? 'Total Net Exchange Gain' : 'Total Net Exchange Loss'),
        'posting_scope' => 'Realized is read-only; only the unrealized preview is postable.',
        'fx_type' => 'combined',
    ];
}
