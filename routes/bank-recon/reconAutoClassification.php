<?php
/**
 * Shared Bank Reconciliation auto-classification helpers.
 *
 * Internal direction remains cash-flow direction:
 *   OUT = bank debit / ledger credit
 *   IN  = bank credit / ledger debit
 *
 * Reconciliation classification wording follows the manual schedule:
 *   Bank OUT → They Debit We Don't Credit
 *   Bank IN  → They Credit We Don't Debit
 *   Ledger IN  → We Debit They Don't Credit
 *   Ledger OUT → We Credit They Don't Debit
 */

if (!function_exists('brAutoNormText')) {
    function brAutoNormText(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', (string)$value));
    }
}

if (!function_exists('brAutoAmountKey')) {
    function brAutoAmountKey($amount): string
    {
        return number_format(abs((float)$amount), 2, '.', '');
    }
}

if (!function_exists('brAutoLineKey')) {
    function brAutoLineKey(string $date, $amount, string $direction, ?string $reference, ?string $description): string
    {
        return implode('|', [
            trim($date),
            strtoupper(trim($direction)),
            brAutoAmountKey($amount),
            brAutoNormText($reference),
            brAutoNormText($description),
        ]);
    }
}


if (!function_exists('brAutoLineLooseKey')) {
    function brAutoLineLooseKey(string $date, $amount, string $direction, ?string $description): string
    {
        return implode('|', [
            trim($date),
            strtoupper(trim($direction)),
            brAutoAmountKey($amount),
            brAutoNormText($description),
        ]);
    }
}

if (!function_exists('brAutoLineLooseKeyFromDb')) {
    function brAutoLineLooseKeyFromDb(array $line): string
    {
        return brAutoLineLooseKey(
            (string)($line['txn_date'] ?? ''),
            $line['amount'] ?? 0,
            (string)($line['direction'] ?? ''),
            $line['description'] ?? ''
        );
    }
}

if (!function_exists('brAutoLineKeyFromDb')) {
    function brAutoLineKeyFromDb(array $line): string
    {
        return brAutoLineKey(
            (string)($line['txn_date'] ?? ''),
            $line['amount'] ?? 0,
            (string)($line['direction'] ?? ''),
            $line['reference'] ?? '',
            $line['description'] ?? ''
        );
    }
}

if (!function_exists('brAutoLineKeyFromParsed')) {
    function brAutoLineKeyFromParsed(array $line): string
    {
        return brAutoLineKey(
            (string)($line['date'] ?? ''),
            $line['amount'] ?? 0,
            (string)($line['direction'] ?? ''),
            $line['reference'] ?? '',
            $line['description'] ?? ''
        );
    }
}

if (!function_exists('brAutoClassificationForDirection')) {
    function brAutoClassificationForDirection(string $source, string $direction): string
    {
        $source = strtolower(trim($source));
        $direction = strtoupper(trim($direction));

        if ($source === 'bank') {
            return $direction === 'OUT'
                ? "They Debit We Don't Credit"
                : "They Credit We Don't Debit";
        }

        return $direction === 'IN'
            ? "We Debit They Don't Credit"
            : "We Credit They Don't Debit";
    }
}

if (!function_exists('brAutoCategoryForLine')) {
    function brAutoCategoryForLine(string $source, string $description, string $direction): ?array
    {
        $source = strtolower(trim($source));
        $direction = strtoupper(trim($direction));

        // Only bank-side exceptions should be auto-categorised. Ledger-side
        // unmatched entries are usually timing/unposted-bank items and should
        // remain for user review unless manually classified.
        if ($source !== 'bank') {
            return null;
        }

        if (!function_exists('detectBankOnlyType') || !function_exists('suggestLedgers')) {
            return null;
        }

        $category = detectBankOnlyType($description, $direction);
        if (!$category) {
            return null;
        }

        $ledgers = suggestLedgers($category);

        return [
            'category' => $category,
            'classification' => brAutoClassificationForDirection($source, $direction),
            'dr_ledger' => (string)($ledgers['dr'] ?? ''),
            'cr_ledger' => (string)($ledgers['cr'] ?? ''),
            'note' => 'Auto-categorised during reconciliation upload based on the transaction narration.',
        ];
    }
}

if (!function_exists('brAutoCaptureClassifications')) {
    function brAutoCaptureClassifications(mysqli $conn, int $reconId, string $source): array
    {
        $source = strtolower(trim($source));
        $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';

        $sql = "SELECT * FROM {$table}
                WHERE recon_id={$reconId}
                  AND (
                    match_status IN ('Classified','Bank-Only')
                    OR COALESCE(category_name,'') <> ''
                    OR COALESCE(recon_classification,'') <> ''
                  )
                ORDER BY id";

        $res = $conn->query($sql);
        $map = [];
        if (!$res) {
            return $map;
        }

        while ($line = $res->fetch_assoc()) {
            $key = brAutoLineKeyFromDb($line);
            $snapshot = [
                'match_status' => in_array($line['match_status'] ?? '', ['Classified','Bank-Only'], true) ? $line['match_status'] : 'Classified',
                'bank_only_type' => (string)($line['bank_only_type'] ?? ''),
                'category_name' => (string)($line['category_name'] ?? ($line['bank_only_type'] ?? '')),
                'recon_classification' => (string)($line['recon_classification'] ?? ''),
                'suggested_dr_ledger' => (string)($line['suggested_dr_ledger'] ?? ''),
                'suggested_cr_ledger' => (string)($line['suggested_cr_ledger'] ?? ''),
                'journal_note' => (string)($line['journal_note'] ?? ''),
            ];

            if (!isset($map['exact'][$key])) {
                $map['exact'][$key] = [];
            }
            $map['exact'][$key][] = $snapshot;

            // Fallback for re-uploads where the bank/ledger export adds or
            // removes the reference column but the transaction itself is the same.
            $looseKey = brAutoLineLooseKeyFromDb($line);
            if (!isset($map['loose'][$looseKey])) {
                $map['loose'][$looseKey] = [];
            }
            $map['loose'][$looseKey][] = $snapshot;
        }

        return $map;
    }
}

if (!function_exists('brAutoRestoreClassifications')) {
    function brAutoRestoreClassifications(mysqli $conn, int $reconId, string $source, array $preserved): int
    {
        if (!$preserved) {
            return 0;
        }

        $source = strtolower(trim($source));
        $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
        $restored = 0;

        $res = $conn->query("SELECT * FROM {$table} WHERE recon_id={$reconId} AND match_status='Unmatched' ORDER BY id");
        if (!$res) {
            return 0;
        }

        if ($source === 'bank') {
            $stmt = $conn->prepare("UPDATE bank_recon_bank_lines
                SET match_status=?,
                    bank_only_type=?,
                    category_name=?,
                    recon_classification=?,
                    suggested_dr_ledger=?,
                    suggested_cr_ledger=?,
                    journal_note=?
                WHERE id=? AND recon_id=? AND match_status='Unmatched'");
        } else {
            $stmt = $conn->prepare("UPDATE bank_recon_ledger_lines
                SET match_status=?,
                    category_name=?,
                    recon_classification=?,
                    suggested_dr_ledger=?,
                    suggested_cr_ledger=?,
                    journal_note=?
                WHERE id=? AND recon_id=? AND match_status='Unmatched'");
        }

        if (!$stmt) {
            return 0;
        }

        while ($line = $res->fetch_assoc()) {
            $key = brAutoLineKeyFromDb($line);
            $snapshot = null;
            if (!empty($preserved['exact'][$key])) {
                $snapshot = array_shift($preserved['exact'][$key]);
            } else {
                $looseKey = brAutoLineLooseKeyFromDb($line);
                if (!empty($preserved['loose'][$looseKey])) {
                    $snapshot = array_shift($preserved['loose'][$looseKey]);
                }
            }

            if (!$snapshot) {
                continue;
            }
            $status = $snapshot['match_status'] ?: 'Classified';
            $category = $snapshot['category_name'] ?: $snapshot['bank_only_type'];
            $classification = $snapshot['recon_classification'];
            $dr = $snapshot['suggested_dr_ledger'];
            $cr = $snapshot['suggested_cr_ledger'];
            $note = $snapshot['journal_note'];
            $lineId = (int)$line['id'];

            if ($category === '' && $classification === '') {
                continue;
            }

            if ($source === 'bank') {
                $bankOnlyType = $snapshot['bank_only_type'] ?: $category;
                $stmt->bind_param('sssssssii', $status, $bankOnlyType, $category, $classification, $dr, $cr, $note, $lineId, $reconId);
            } else {
                $stmt->bind_param('ssssssii', $status, $category, $classification, $dr, $cr, $note, $lineId, $reconId);
            }

            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $restored++;
            }
        }

        $stmt->close();
        return $restored;
    }
}

if (!function_exists('brAutoApplyClassifications')) {
    function brAutoApplyClassifications(mysqli $conn, int $reconId, string $source = 'bank', array $onlyIds = []): int
    {
        $source = strtolower(trim($source));
        $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
        $idFilter = '';

        if ($onlyIds) {
            $ids = array_values(array_filter(array_map('intval', $onlyIds), static fn($id) => $id > 0));
            if (!$ids) {
                return 0;
            }
            $idFilter = ' AND id IN (' . implode(',', $ids) . ')';
        }

        $res = $conn->query("SELECT * FROM {$table} WHERE recon_id={$reconId} AND match_status='Unmatched'{$idFilter} ORDER BY txn_date, id");
        if (!$res) {
            return 0;
        }

        if ($source === 'bank') {
            $stmt = $conn->prepare("UPDATE bank_recon_bank_lines
                SET match_status='Classified',
                    bank_only_type=?,
                    category_name=?,
                    recon_classification=?,
                    suggested_dr_ledger=?,
                    suggested_cr_ledger=?,
                    journal_note=?
                WHERE id=? AND recon_id=? AND match_status='Unmatched'");
        } else {
            $stmt = $conn->prepare("UPDATE bank_recon_ledger_lines
                SET match_status='Classified',
                    category_name=?,
                    recon_classification=?,
                    suggested_dr_ledger=?,
                    suggested_cr_ledger=?,
                    journal_note=?
                WHERE id=? AND recon_id=? AND match_status='Unmatched'");
        }

        if (!$stmt) {
            return 0;
        }

        $count = 0;
        while ($line = $res->fetch_assoc()) {
            $rule = brAutoCategoryForLine($source, (string)$line['description'], (string)$line['direction']);
            if (!$rule) {
                continue;
            }

            $category = $rule['category'];
            $classification = $rule['classification'];
            $dr = $rule['dr_ledger'];
            $cr = $rule['cr_ledger'];
            $note = $rule['note'];
            $lineId = (int)$line['id'];

            if ($source === 'bank') {
                $stmt->bind_param('ssssssii', $category, $category, $classification, $dr, $cr, $note, $lineId, $reconId);
            } else {
                $stmt->bind_param('sssssii', $category, $classification, $dr, $cr, $note, $lineId, $reconId);
            }

            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $count++;
            }
        }

        $stmt->close();
        return $count;
    }
}

if (!function_exists('brAutoRecomputeSummary')) {
    function brAutoRecomputeSummary(mysqli $conn, int $reconId): array
    {
        $recon = $conn->query("SELECT * FROM bank_recons WHERE id={$reconId} LIMIT 1")->fetch_assoc();
        if (!$recon) {
            return [];
        }

        $classes = [
            "We Debit They Don't Credit" => 0.0,
            "They Debit We Don't Credit" => 0.0,
            "We Credit They Don't Debit" => 0.0,
            "They Credit We Don't Debit" => 0.0,
        ];

        foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $table) {
            $res = $conn->query("SELECT recon_classification, COALESCE(SUM(amount),0) amount
                FROM {$table}
                WHERE recon_id={$reconId}
                  AND match_status IN ('Classified','Bank-Only')
                  AND recon_classification IS NOT NULL
                  AND recon_classification <> ''
                GROUP BY recon_classification");
            if (!$res) {
                continue;
            }
            while ($row = $res->fetch_assoc()) {
                $class = (string)$row['recon_classification'];
                if (array_key_exists($class, $classes)) {
                    $classes[$class] += (float)$row['amount'];
                }
            }
        }

        $weDebitTheyDontCredit = round($classes["We Debit They Don't Credit"], 2);
        $theyDebitWeDontCredit = round($classes["They Debit We Don't Credit"], 2);
        $weCreditTheyDontDebit = round($classes["We Credit They Don't Debit"], 2);
        $theyCreditWeDontDebit = round($classes["They Credit We Don't Debit"], 2);

        $adjustedLedger = round((float)$recon['ledger_closing'] - $theyDebitWeDontCredit + $theyCreditWeDontDebit, 2);
        $adjustedBank = round((float)$recon['bank_closing'] + $weDebitTheyDontCredit - $weCreditTheyDontDebit, 2);
        $diff = round($adjustedLedger - $adjustedBank, 2);
        $status = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';

        $stmt = $conn->prepare("UPDATE bank_recons SET adjusted_bank_balance=?, adjusted_ledger_balance=?, unreconciled_difference=?, status=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('dddsi', $adjustedBank, $adjustedLedger, $diff, $status, $reconId);
            $stmt->execute();
            $stmt->close();
        }

        return [
            'weDebitTheyDontCredit' => $weDebitTheyDontCredit,
            'theyDebitWeDontCredit' => $theyDebitWeDontCredit,
            'weCreditTheyDontDebit' => $weCreditTheyDontDebit,
            'theyCreditWeDontDebit' => $theyCreditWeDontDebit,
            'adjusted_bank_balance' => $adjustedBank,
            'adjusted_ledger_balance' => $adjustedLedger,
            'unreconciled_difference' => $diff,
            'status' => $status,
        ];
    }
}
