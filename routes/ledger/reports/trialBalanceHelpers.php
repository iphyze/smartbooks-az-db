<?php

/**
 * Shared Trial Balance calculations used by the API and Excel export.
 *
 * Opening balances are the net position before the selected start date.
 * Period movements are the gross debit/credit postings within the selected range.
 * Closing balances are the opening position plus the period movement, split into
 * debit and credit columns for a conventional trial balance presentation.
 */

function trialBalanceNormalise(float $value): float
{
    return abs($value) < 0.005 ? 0.0 : $value;
}

function trialBalanceSplit(float $net): array
{
    $net = trialBalanceNormalise($net);

    return [
        'debit' => $net > 0 ? $net : 0.0,
        'credit' => $net < 0 ? abs($net) : 0.0,
    ];
}

function trialBalanceEmptyGroup(): array
{
    return [
        'records' => [],
        'sub_total_opening_debit' => 0.0,
        'sub_total_opening_credit' => 0.0,
        'sub_total_movement_debit' => 0.0,
        'sub_total_movement_credit' => 0.0,
        'sub_total_closing_debit' => 0.0,
        'sub_total_closing_credit' => 0.0,
        // Backwards-compatible aliases for older consumers.
        'sub_total_debit' => 0.0,
        'sub_total_credit' => 0.0,
    ];
}

function fetchTrialBalanceReport(
    mysqli $conn,
    string $datefrom,
    string $dateto,
    string $rateCol,
    string $zerobal = 'No',
    ?string $search = null
): array {
    $classSortOrder = "
        CASE l.ledger_class
            WHEN 'Asset'     THEN 1
            WHEN 'Equity'    THEN 2
            WHEN 'Revenue'   THEN 3
            WHEN 'Liability' THEN 4
            WHEN 'Expense'   THEN 5
            ELSE 6
        END
    ";

    $search = trim((string) $search);
    $searchCondition = '';
    if ($search !== '') {
        $searchCondition = ' AND (l.ledger_name LIKE ? OR CAST(l.ledger_number AS CHAR) LIKE ?)';
    }

    $activeCondition = '';
    if (strcasecmp($zerobal, 'Yes') !== 0) {
        $activeCondition = "
            AND (
                ABS(COALESCE(a.opening_net, 0)) > 0.004
                OR ABS(COALESCE(a.movement_debit, 0)) > 0.004
                OR ABS(COALESCE(a.movement_credit, 0)) > 0.004
                OR ABS(
                    COALESCE(a.opening_net, 0)
                    + COALESCE(a.movement_debit, 0)
                    - COALESCE(a.movement_credit, 0)
                ) > 0.004
            )
        ";
    }

    $query = "
        SELECT
            l.ledger_name,
            l.ledger_number,
            l.ledger_class,
            COALESCE(a.opening_net, 0) AS opening_net,
            COALESCE(a.movement_debit, 0) AS movement_debit,
            COALESCE(a.movement_credit, 0) AS movement_credit
        FROM ledger_table l
        LEFT JOIN (
            SELECT
                m.ledger_number,
                SUM(
                    CASE
                        WHEN m.journal_date < ? THEN
                            (
                                CAST(COALESCE(NULLIF(m.debit_ngn, ''), '0') AS DECIMAL(30, 8))
                                - CAST(COALESCE(NULLIF(m.credit_ngn, ''), '0') AS DECIMAL(30, 8))
                            ) / NULLIF(CAST(COALESCE(NULLIF(m.$rateCol, ''), '0') AS DECIMAL(30, 8)), 0)
                        ELSE 0
                    END
                ) AS opening_net,
                SUM(
                    CASE
                        WHEN m.journal_date BETWEEN ? AND ? THEN
                            CAST(COALESCE(NULLIF(m.debit_ngn, ''), '0') AS DECIMAL(30, 8))
                            / NULLIF(CAST(COALESCE(NULLIF(m.$rateCol, ''), '0') AS DECIMAL(30, 8)), 0)
                        ELSE 0
                    END
                ) AS movement_debit,
                SUM(
                    CASE
                        WHEN m.journal_date BETWEEN ? AND ? THEN
                            CAST(COALESCE(NULLIF(m.credit_ngn, ''), '0') AS DECIMAL(30, 8))
                            / NULLIF(CAST(COALESCE(NULLIF(m.$rateCol, ''), '0') AS DECIMAL(30, 8)), 0)
                        ELSE 0
                    END
                ) AS movement_credit
            FROM main_journal_table m
            WHERE m.journal_date <= ?
            GROUP BY m.ledger_number
        ) a ON a.ledger_number = l.ledger_number
        WHERE 1 = 1
        $searchCondition
        $activeCondition
        ORDER BY $classSortOrder, l.ledger_number ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare trial balance query: ' . $conn->error, 500);
    }

    if ($search !== '') {
        $likeSearch = '%' . $search . '%';
        $stmt->bind_param(
            'ssssssss',
            $datefrom,
            $datefrom,
            $dateto,
            $datefrom,
            $dateto,
            $dateto,
            $likeSearch,
            $likeSearch
        );
    } else {
        $stmt->bind_param(
            'ssssss',
            $datefrom,
            $datefrom,
            $dateto,
            $datefrom,
            $dateto,
            $dateto
        );
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $groupedData = [
        'Asset' => trialBalanceEmptyGroup(),
        'Equity' => trialBalanceEmptyGroup(),
        'Revenue' => trialBalanceEmptyGroup(),
        'Liability' => trialBalanceEmptyGroup(),
        'Expense' => trialBalanceEmptyGroup(),
    ];

    $totals = [
        'grand_total_opening_debit' => 0.0,
        'grand_total_opening_credit' => 0.0,
        'grand_total_movement_debit' => 0.0,
        'grand_total_movement_credit' => 0.0,
        'grand_total_closing_debit' => 0.0,
        'grand_total_closing_credit' => 0.0,
    ];

    $flatRows = [];

    while ($row = $result->fetch_assoc()) {
        $openingNet = trialBalanceNormalise((float) $row['opening_net']);
        $movementDebit = trialBalanceNormalise((float) $row['movement_debit']);
        $movementCredit = trialBalanceNormalise((float) $row['movement_credit']);
        $closingNet = trialBalanceNormalise($openingNet + $movementDebit - $movementCredit);

        $opening = trialBalanceSplit($openingNet);
        $closing = trialBalanceSplit($closingNet);

        $record = [
            'ledger_name' => $row['ledger_name'],
            'ledger_number' => $row['ledger_number'],
            'ledger_class' => $row['ledger_class'],
            'opening_balance' => $openingNet,
            'opening_debit' => $opening['debit'],
            'opening_credit' => $opening['credit'],
            'movement_debit' => $movementDebit,
            'movement_credit' => $movementCredit,
            'closing_balance' => $closingNet,
            'closing_debit' => $closing['debit'],
            'closing_credit' => $closing['credit'],
            // Backwards-compatible fields: the previous report's debit/credit
            // columns represented movement during the selected period.
            'total_debit' => $movementDebit,
            'total_credit' => $movementCredit,
            'balance' => $closingNet,
        ];

        $class = $row['ledger_class'] ?: 'Other';
        if (!isset($groupedData[$class])) {
            $groupedData[$class] = trialBalanceEmptyGroup();
        }

        $groupedData[$class]['records'][] = $record;
        $groupedData[$class]['sub_total_opening_debit'] += $opening['debit'];
        $groupedData[$class]['sub_total_opening_credit'] += $opening['credit'];
        $groupedData[$class]['sub_total_movement_debit'] += $movementDebit;
        $groupedData[$class]['sub_total_movement_credit'] += $movementCredit;
        $groupedData[$class]['sub_total_closing_debit'] += $closing['debit'];
        $groupedData[$class]['sub_total_closing_credit'] += $closing['credit'];
        $groupedData[$class]['sub_total_debit'] += $movementDebit;
        $groupedData[$class]['sub_total_credit'] += $movementCredit;

        $totals['grand_total_opening_debit'] += $opening['debit'];
        $totals['grand_total_opening_credit'] += $opening['credit'];
        $totals['grand_total_movement_debit'] += $movementDebit;
        $totals['grand_total_movement_credit'] += $movementCredit;
        $totals['grand_total_closing_debit'] += $closing['debit'];
        $totals['grand_total_closing_credit'] += $closing['credit'];

        $flatRows[] = $record;
    }

    $stmt->close();

    foreach ($groupedData as &$group) {
        foreach ($group as $key => $value) {
            if ($key !== 'records') {
                $group[$key] = trialBalanceNormalise((float) $value);
            }
        }
    }
    unset($group);

    foreach ($totals as $key => $value) {
        $totals[$key] = trialBalanceNormalise((float) $value);
    }

    $totals['grand_opening_difference'] = trialBalanceNormalise(
        $totals['grand_total_opening_debit'] - $totals['grand_total_opening_credit']
    );
    $totals['grand_movement_difference'] = trialBalanceNormalise(
        $totals['grand_total_movement_debit'] - $totals['grand_total_movement_credit']
    );
    $totals['grand_closing_difference'] = trialBalanceNormalise(
        $totals['grand_total_closing_debit'] - $totals['grand_total_closing_credit']
    );

    // Backwards-compatible total fields.
    $totals['grand_total_debit'] = $totals['grand_total_movement_debit'];
    $totals['grand_total_credit'] = $totals['grand_total_movement_credit'];
    $totals['grand_total_balance'] = $totals['grand_closing_difference'];

    return [
        'data' => $groupedData,
        'rows' => $flatRows,
        'totals' => $totals,
        'total_records' => count($flatRows),
    ];
}
