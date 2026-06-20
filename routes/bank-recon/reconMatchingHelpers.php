<?php
/**
 * Shared helpers for AccountLab bank reconciliation matching, partial allocation,
 * and configurable auto-categorisation rules.
 *
 * The helpers are intentionally defensive: they add the few required columns/tables
 * when missing so older AcctLab installations can accept this patch without a full
 * database rebuild.
 */

if (!function_exists('brReconSqlValue')) {
    function brReconSqlValue(mysqli $conn, $value): string
    {
        if ($value === null) return 'NULL';
        return "'" . $conn->real_escape_string((string)$value) . "'";
    }
}

if (!function_exists('brReconColumnExists')) {
    function brReconColumnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $columnEsc = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$columnEsc}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('brReconEnsureColumn')) {
    function brReconEnsureColumn(mysqli $conn, string $table, string $column, string $definition): void
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (!brReconColumnExists($conn, $table, $column)) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

if (!function_exists('brReconEnsureMatchingSchema')) {
    function brReconEnsureMatchingSchema(mysqli $conn): void
    {
        foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $table) {
            brReconEnsureColumn($conn, $table, 'matched_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER `amount`");
            // Backfill existing full matches created before partial allocation existed.
            $conn->query("UPDATE {$table} SET matched_amount=ABS(amount) WHERE match_status='Matched' AND COALESCE(matched_amount,0)=0");
        }

        brReconEnsureColumn($conn, 'bank_recon_matches', 'bank_allocated_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER `ledger_line_id`");
        brReconEnsureColumn($conn, 'bank_recon_matches', 'ledger_allocated_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER `bank_allocated_amount`");
        brReconEnsureColumn($conn, 'bank_recon_matches', 'is_partial', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `ledger_allocated_amount`");
        brReconEnsureColumn($conn, 'bank_recon_matches', 'match_note', "VARCHAR(255) NULL AFTER `is_partial`");
    }
}


if (!function_exists('brReconLineSelectList')) {
    function brReconLineSelectList(mysqli $conn, string $table): string
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $bankOnlySelect = brReconColumnExists($conn, $table, 'bank_only_type') ? 'bank_only_type' : 'NULL AS bank_only_type';
        $ledgerNameSelect = brReconColumnExists($conn, $table, 'ledger_name') ? 'ledger_name' : 'NULL AS ledger_name';
        $runningBalanceSelect = brReconColumnExists($conn, $table, 'running_balance') ? 'running_balance' : 'NULL AS running_balance';
        $originSelect = brReconColumnExists($conn, $table, 'classification_origin') ? 'classification_origin' : 'NULL AS classification_origin';
        $ruleIdSelect = brReconColumnExists($conn, $table, 'classification_rule_id') ? 'classification_rule_id' : 'NULL AS classification_rule_id';
        $lockedSelect = brReconColumnExists($conn, $table, 'classification_locked') ? 'classification_locked' : '0 AS classification_locked';
        return "id, recon_id, txn_date, description, reference, {$ledgerNameSelect}, amount, direction, {$runningBalanceSelect}, match_status, match_group, auto_matched, category_name, recon_classification, suggested_dr_ledger, suggested_cr_ledger, journal_note, {$originSelect}, {$ruleIdSelect}, {$lockedSelect}, COALESCE(matched_amount,0) AS matched_amount, {$bankOnlySelect}";
    }
}

if (!function_exists('brReconTextSimilarity')) {
    function brReconTextSimilarity(?string $a, ?string $b): float
    {
        $a = strtolower(trim((string)$a));
        $b = strtolower(trim((string)$b));
        if ($a === '' || $b === '') return 0.0;
        similar_text($a, $b, $pct);
        return (float)$pct;
    }
}

if (!function_exists('brReconEnsureClassificationMetadataSchema')) {
    function brReconEnsureClassificationMetadataSchema(mysqli $conn): void
    {
        foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $table) {
            brReconEnsureColumn($conn, $table, 'classification_origin', "VARCHAR(24) NULL AFTER `journal_note`");
            brReconEnsureColumn($conn, $table, 'classification_rule_id', "INT NULL AFTER `classification_origin`");
            brReconEnsureColumn($conn, $table, 'classification_locked', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `classification_rule_id`");

            // Backfill older records so a full rule refresh can distinguish
            // deliberate manual decisions from rule/learned/default output.
            $conn->query("UPDATE {$table}
                SET classification_origin = CASE
                    WHEN COALESCE(category_name,'')='' AND COALESCE(recon_classification,'')='' THEN NULL
                    WHEN COALESCE(journal_note,'') LIKE 'Auto-categorised by rule:%' THEN 'rule'
                    WHEN COALESCE(journal_note,'') LIKE 'Auto-categorised from learned monthly pattern:%' THEN 'learned'
                    WHEN COALESCE(journal_note,'') LIKE 'Auto-categorised during reconciliation upload%' THEN 'default'
                    ELSE 'manual'
                END
                WHERE classification_origin IS NULL");

            $conn->query("UPDATE {$table}
                SET classification_locked=1
                WHERE classification_origin='manual'
                  AND (COALESCE(category_name,'')<>'' OR COALESCE(recon_classification,'')<>'')
                  AND classification_locked=0");
        }
    }
}

if (!function_exists('brReconEnsureRuleSchema')) {
    function brReconEnsureRuleSchema(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS bank_recon_auto_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(120) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'bank',
            match_field VARCHAR(40) NOT NULL DEFAULT 'description',
            match_type VARCHAR(20) NOT NULL DEFAULT 'contains',
            keywords TEXT NOT NULL,
            direction VARCHAR(10) NOT NULL DEFAULT 'ANY',
            category_name VARCHAR(120) NOT NULL,
            recon_classification VARCHAR(120) NOT NULL,
            suggested_dr_ledger VARCHAR(160) NULL,
            suggested_cr_ledger VARCHAR(160) NULL,
            priority INT NOT NULL DEFAULT 100,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(160) NULL,
            updated_by VARCHAR(160) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bank_recon_auto_rules_active (is_active, source, direction, priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        brReconEnsureClassificationMetadataSchema($conn);
    }
}

if (!function_exists('brReconOutstandingAmount')) {
    function brReconOutstandingAmount(array $line): float
    {
        $amount = abs((float)($line['amount'] ?? 0));
        $matched = abs((float)($line['matched_amount'] ?? 0));
        return round(max(0, $amount - min($matched, $amount)), 2);
    }
}

if (!function_exists('brReconLineMapWithOutstanding')) {
    function brReconLineMapWithOutstanding(array $row): array
    {
        $amount = abs((float)($row['amount'] ?? 0));
        $matched = abs((float)($row['matched_amount'] ?? 0));
        if (($row['match_status'] ?? '') === 'Matched' && $matched <= 0.009) $matched = $amount;
        if ($matched > $amount) $matched = $amount;
        $outstanding = round(max(0, $amount - $matched), 2);

        $row['amount'] = $amount;
        $row['matched_amount'] = round($matched, 2);
        $row['outstanding_amount'] = $outstanding;
        $row['remaining_amount'] = $outstanding;
        $row['is_partially_matched'] = $matched > 0.009 && $outstanding > 0.009;
        $row['display_match_status'] = $row['is_partially_matched'] ? 'Partially Matched' : ($row['match_status'] ?? 'Unmatched');
        return $row;
    }
}

if (!function_exists('brReconFetchSelectedLines')) {
    function brReconFetchSelectedLines(mysqli $conn, string $table, int $reconId, array $ids): array
    {
        if (!$ids) return [];
        brReconEnsureMatchingSchema($conn);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$reconId], $ids);
        $stmt = $conn->prepare("SELECT *, GREATEST(ABS(amount) - COALESCE(matched_amount,0), 0) AS available_amount FROM {$table} WHERE recon_id = ? AND id IN ({$ph}) ORDER BY txn_date, id");
        if (!$stmt) throw new Exception('Failed to prepare selected line lookup: ' . $conn->error, 500);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map('brReconLineMapWithOutstanding', $rows);
    }
}

if (!function_exists('brReconGroupTotalsByDirection')) {
    function brReconGroupTotalsByDirection(array $lines): array
    {
        $totals = ['OUT' => 0.0, 'IN' => 0.0];
        foreach ($lines as $line) {
            $direction = strtoupper((string)($line['direction'] ?? ''));
            if (isset($totals[$direction])) $totals[$direction] += brReconOutstandingAmount($line);
        }
        $totals['OUT'] = round($totals['OUT'], 2);
        $totals['IN'] = round($totals['IN'], 2);
        return $totals;
    }
}

if (!function_exists('brReconBuildAllocations')) {
    function brReconBuildAllocations(array $bankLines, array $ledgerLines, bool $allowPartial, float $tolerance): array
    {
        $allocations = [];
        $bankTotal = 0.0;
        $ledgerTotal = 0.0;
        $matchedTotal = 0.0;
        $hasMismatch = false;

        foreach (['OUT', 'IN'] as $direction) {
            $banks = array_values(array_filter($bankLines, static fn($l) => strtoupper((string)($l['direction'] ?? '')) === $direction && brReconOutstandingAmount($l) > 0.009));
            $ledgers = array_values(array_filter($ledgerLines, static fn($l) => strtoupper((string)($l['direction'] ?? '')) === $direction && brReconOutstandingAmount($l) > 0.009));

            $bTotal = round(array_reduce($banks, static fn($s, $l) => $s + brReconOutstandingAmount($l), 0.0), 2);
            $lTotal = round(array_reduce($ledgers, static fn($s, $l) => $s + brReconOutstandingAmount($l), 0.0), 2);
            $bankTotal += $bTotal;
            $ledgerTotal += $lTotal;

            if ($bTotal <= 0.009 && $lTotal <= 0.009) continue;
            if ($bTotal <= 0.009 || $lTotal <= 0.009) {
                throw new Exception("Selected {$direction} lines must include both bank and ledger entries.", 422);
            }

            $diff = round($bTotal - $lTotal, 2);
            if (abs($diff) > max($tolerance, 0.01)) {
                if (!$allowPartial) {
                    throw new Exception('Selected lines do not fully balance for ' . $direction . '. Difference: ' . number_format(abs($diff), 2) . '. Enable partial match to allocate the smaller side and leave the balance outstanding.', 422);
                }
                $hasMismatch = true;
            }

            $j = 0;
            $ledgerRemaining = isset($ledgers[$j]) ? brReconOutstandingAmount($ledgers[$j]) : 0.0;

            foreach ($banks as $bank) {
                $bankRemaining = brReconOutstandingAmount($bank);
                while ($bankRemaining > 0.009 && $j < count($ledgers)) {
                    if ($ledgerRemaining <= 0.009) {
                        $j++;
                        $ledgerRemaining = isset($ledgers[$j]) ? brReconOutstandingAmount($ledgers[$j]) : 0.0;
                        continue;
                    }

                    $alloc = round(min($bankRemaining, $ledgerRemaining), 2);
                    if ($alloc <= 0.009) break;

                    $allocations[] = [
                        'direction' => $direction,
                        'bank_line_id' => (int)$bank['id'],
                        'ledger_line_id' => (int)$ledgers[$j]['id'],
                        'amount' => $alloc,
                        'day_difference' => isset($bank['txn_date'], $ledgers[$j]['txn_date'])
                            ? (int)(abs(strtotime((string)$bank['txn_date']) - strtotime((string)$ledgers[$j]['txn_date'])) / 86400)
                            : 0,
                    ];

                    $matchedTotal += $alloc;
                    $bankRemaining = round($bankRemaining - $alloc, 2);
                    $ledgerRemaining = round($ledgerRemaining - $alloc, 2);
                }
            }
        }

        if (!$allocations) {
            throw new Exception('No available outstanding amount found on the selected lines.', 422);
        }

        return [
            'allocations' => $allocations,
            'bank_total' => round($bankTotal, 2),
            'ledger_total' => round($ledgerTotal, 2),
            'matched_total' => round($matchedTotal, 2),
            'difference' => round($bankTotal - $ledgerTotal, 2),
            'is_partial' => $allowPartial || $hasMismatch || abs(round($bankTotal - $ledgerTotal, 2)) > max($tolerance, 0.01),
        ];
    }
}

if (!function_exists('brReconApplyMatchedDelta')) {
    function brReconApplyMatchedDelta(mysqli $conn, string $table, int $lineId, float $delta, float $tolerance, ?string $matchGroup = null): void
    {
        brReconEnsureMatchingSchema($conn);
        $selectList = brReconLineSelectList($conn, $table);
        $line = $conn->query("SELECT {$selectList} FROM {$table} WHERE id=" . (int)$lineId . " LIMIT 1")->fetch_assoc();
        if (!$line) return;
        $amount = abs((float)$line['amount']);
        $current = abs((float)$line['matched_amount']);
        $next = round(max(0, min($amount, $current + $delta)), 2);
        $hasCategory = trim((string)($line['category_name'] ?? '')) !== ''
            || trim((string)($line['bank_only_type'] ?? '')) !== ''
            || trim((string)($line['recon_classification'] ?? '')) !== ''
            || in_array((string)($line['match_status'] ?? ''), ['Classified','Bank-Only'], true);
        if (($amount - $next) <= max($tolerance, 0.01) && $next > 0.009) {
            $status = 'Matched';
        } else {
            $status = $hasCategory ? 'Classified' : 'Unmatched';
        }
        $groupSql = $matchGroup !== null ? ', match_group=' . brReconSqlValue($conn, $matchGroup) : '';
        $conn->query("UPDATE {$table} SET matched_amount={$next}, match_status='{$status}'{$groupSql}, auto_matched=0 WHERE id=" . (int)$lineId);
    }
}

if (!function_exists('brReconRefreshLineGroupAfterUnmatch')) {
    function brReconRefreshLineGroupAfterUnmatch(mysqli $conn, string $table, int $reconId, int $lineId, string $idColumn, float $tolerance): void
    {
        brReconEnsureMatchingSchema($conn);
        $selectList = brReconLineSelectList($conn, $table);
        $line = $conn->query("SELECT {$selectList} FROM {$table} WHERE id=" . (int)$lineId . " AND recon_id=" . (int)$reconId . " LIMIT 1")->fetch_assoc();
        if (!$line) return;

        $amount = abs((float)$line['amount']);
        $matched = min($amount, abs((float)$line['matched_amount']));
        $hasCategory = trim((string)($line['category_name'] ?? '')) !== ''
            || trim((string)($line['bank_only_type'] ?? '')) !== ''
            || trim((string)($line['recon_classification'] ?? '')) !== ''
            || in_array((string)($line['match_status'] ?? ''), ['Classified','Bank-Only'], true);
        if (($amount - $matched) <= max($tolerance, 0.01) && $matched > 0.009) {
            $status = 'Matched';
        } else {
            $status = $hasCategory ? 'Classified' : 'Unmatched';
        }

        $group = null;
        if ($matched > 0.009) {
            $res = $conn->query("SELECT match_group FROM bank_recon_matches WHERE recon_id=" . (int)$reconId . " AND {$idColumn}=" . (int)$lineId . " ORDER BY id DESC LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) $group = $row['match_group'];
        }
        $groupSql = $group ? brReconSqlValue($conn, $group) : 'NULL';
        $conn->query("UPDATE {$table} SET match_status='{$status}', match_group={$groupSql}, matched_amount={$matched} WHERE id=" . (int)$lineId . " AND recon_id=" . (int)$reconId);
    }
}

if (!function_exists('brReconNormalizeDifference')) {
    function brReconNormalizeDifference(float $difference, float $tolerance = 0.01): float
    {
        $rounded = round($difference, 2);
        return abs($rounded) <= $tolerance ? 0.00 : $rounded;
    }
}

if (!function_exists('brReconRecomputeSummary')) {
    function brReconRecomputeSummary(mysqli $conn, int $reconId): array
    {
        brReconEnsureMatchingSchema($conn);
        $r = $conn->query("SELECT * FROM bank_recons WHERE id={$reconId} LIMIT 1")->fetch_assoc();
        if (!$r) return [];

        $classes = [
            "We Debit They Don't Credit" => 0.0,
            "They Debit We Don't Credit" => 0.0,
            "We Credit They Don't Debit" => 0.0,
            "They Credit We Don't Debit" => 0.0,
        ];

        foreach (['bank_recon_bank_lines', 'bank_recon_ledger_lines'] as $table) {
            $res = $conn->query("SELECT recon_classification, COALESCE(SUM(GREATEST(ABS(amount)-COALESCE(matched_amount,0),0)),0) amount
                FROM {$table}
                WHERE recon_id={$reconId}
                  AND match_status IN ('Classified','Bank-Only','Unmatched')
                  AND COALESCE(recon_classification,'') <> ''
                GROUP BY recon_classification");
            if (!$res) continue;
            while ($row = $res->fetch_assoc()) {
                $class = (string)$row['recon_classification'];
                if (array_key_exists($class, $classes)) $classes[$class] += (float)$row['amount'];
            }
        }

        $weDebitTheyDontCredit = round($classes["We Debit They Don't Credit"], 2);
        $theyDebitWeDontCredit = round($classes["They Debit We Don't Credit"], 2);
        $weCreditTheyDontDebit = round($classes["We Credit They Don't Debit"], 2);
        $theyCreditWeDontDebit = round($classes["They Credit We Don't Debit"], 2);

        $adjustedLedger = round((float)$r['ledger_closing'] - $theyDebitWeDontCredit + $theyCreditWeDontDebit, 2);
        $adjustedBank = round((float)$r['bank_closing'] + $weDebitTheyDontCredit - $weCreditTheyDontDebit, 2);
        $diff = brReconNormalizeDifference($adjustedLedger - $adjustedBank);
        $status = abs($diff) <= 0.01 ? 'Balanced' : 'Unbalanced';

        $stmt = $conn->prepare('UPDATE bank_recons SET adjusted_bank_balance=?, adjusted_ledger_balance=?, unreconciled_difference=?, status=? WHERE id=?');
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

if (!function_exists('brReconRuleMatchesLine')) {
    function brReconRuleMatchesLine(array $rule, string $source, string $description, string $direction, string $reference = ''): bool
    {
        $source = strtolower(trim($source));
        $ruleSource = strtolower(trim((string)($rule['source'] ?? 'bank')));
        if ($ruleSource !== 'both' && $ruleSource !== $source) return false;

        $ruleDirection = strtoupper(trim((string)($rule['direction'] ?? 'ANY')));
        $lineDirection = strtoupper(trim($direction));
        if ($ruleDirection !== 'ANY' && $ruleDirection !== $lineDirection) return false;

        $field = strtolower(trim((string)($rule['match_field'] ?? 'description')));
        $haystack = $field === 'reference' ? $reference : ($field === 'description_reference' ? ($description . ' ' . $reference) : $description);
        $haystack = (string)$haystack;
        $matchType = strtolower(trim((string)($rule['match_type'] ?? 'contains')));
        $keywords = trim((string)($rule['keywords'] ?? ''));
        if ($keywords === '') return false;

        $parts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $keywords))));
        if (!$parts) return false;

        foreach ($parts as $needle) {
            if ($matchType === 'exact' && strcasecmp(trim($haystack), $needle) === 0) return true;
            if ($matchType === 'regex') {
                $pattern = @preg_match($needle, '') !== false ? $needle : '/' . str_replace('/', '\\/', $needle) . '/i';
                if (@preg_match($pattern, $haystack)) return true;
            }
            if ($matchType === 'contains' && stripos($haystack, $needle) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('brReconFindAutoRule')) {
    function brReconFindAutoRule(mysqli $conn, string $source, string $description, string $direction, string $reference = ''): ?array
    {
        brReconEnsureRuleSchema($conn);
        $sourceE = $conn->real_escape_string(strtolower(trim($source)));
        $res = $conn->query("SELECT * FROM bank_recon_auto_rules WHERE is_active=1 AND (source='{$sourceE}' OR source='both') ORDER BY priority ASC, id ASC");
        if (!$res) return null;
        while ($rule = $res->fetch_assoc()) {
            if (brReconRuleMatchesLine($rule, $source, $description, $direction, $reference)) {
                return $rule;
            }
        }
        return null;
    }
}


if (!function_exists('brReconCategoryKeyForLine')) {
    function brReconCategoryKeyForLine(array $line): string
    {
        $parts = [
            (string)($line['category_name'] ?? ''),
            (string)($line['bank_only_type'] ?? ''),
            (string)($line['recon_classification'] ?? ''),
        ];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') return $part;
        }
        return '';
    }
}

if (!function_exists('brReconCategoryNorm')) {
    function brReconCategoryNorm(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
        $value = preg_replace('/\b(charges|charge)\b/i', 'charge', (string)$value);
        $value = preg_replace('/\b(fees|fee)\b/i', 'fee', (string)$value);
        return trim(preg_replace('/\s+/', ' ', (string)$value));
    }
}

if (!function_exists('brReconCategoryMatchScore')) {
    function brReconCategoryMatchScore(array $newLine, array $group, float $amountDiff, float $tolerance): int
    {
        $category = brReconCategoryNorm((string)($group['category_key'] ?? ''));
        $classification = brReconCategoryNorm((string)($group['classification'] ?? ''));
        $description = brReconCategoryNorm((string)($newLine['description'] ?? '') . ' ' . (string)($newLine['ledger_name'] ?? '') . ' ' . (string)($newLine['reference'] ?? ''));
        $ledgerHints = brReconCategoryNorm((string)($group['dr_ledger'] ?? '') . ' ' . (string)($group['cr_ledger'] ?? ''));

        $score = 60;
        if ($amountDiff <= max($tolerance, 0.01)) $score += 12;
        if ($category !== '' && $description !== '' && (strpos($description, $category) !== false || strpos($category, $description) !== false)) $score += 18;
        if ($ledgerHints !== '' && $description !== '') {
            foreach (array_filter(explode(' ', $ledgerHints)) as $token) {
                if (strlen($token) >= 4 && strpos($description, $token) !== false) {
                    $score += 6;
                    break;
                }
            }
        }
        if ($category !== '' && $description !== '') {
            $score += (int)round(min(10, brReconTextSimilarity($category, $description) / 10));
        }
        if ($classification !== '' && $description !== '') {
            $score += (int)round(min(5, brReconTextSimilarity($classification, $description) / 20));
        }
        return min(99, $score);
    }
}

if (!function_exists('brReconFetchLinesForCategorySettlement')) {
    function brReconFetchLinesForCategorySettlement(mysqli $conn, string $table, int $reconId, ?array $ids = null, bool $categoryOnly = false): array
    {
        brReconEnsureMatchingSchema($conn);
        $selectList = brReconLineSelectList($conn, $table);
        $where = "recon_id=" . (int)$reconId;
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
            if (!$ids) return [];
            $where .= " AND id IN (" . implode(',', $ids) . ")";
        }
        $where .= " AND match_status <> 'Matched' AND GREATEST(ABS(amount)-COALESCE(matched_amount,0),0) > 0.009";
        if ($categoryOnly) {
            $where .= " AND (COALESCE(category_name,'') <> '' OR COALESCE(recon_classification,'') <> ''";
            if (brReconColumnExists($conn, $table, 'bank_only_type')) $where .= " OR COALESCE(bank_only_type,'') <> ''";
            $where .= ")";
        }
        $res = $conn->query("SELECT {$selectList}, GREATEST(ABS(amount)-COALESCE(matched_amount,0),0) AS available_amount FROM {$table} WHERE {$where} ORDER BY txn_date, id");
        return $res ? array_map('brReconLineMapWithOutstanding', $res->fetch_all(MYSQLI_ASSOC)) : [];
    }
}

if (!function_exists('brReconAutoMatchInsertedAgainstCategoryTotals')) {
    function brReconAutoMatchInsertedAgainstCategoryTotals(mysqli $conn, int $reconId, string $newSource, array $insertedIds, int $tolDays, float $tolAmt, string $by, int &$matchSeq): int
    {
        $insertedIds = array_values(array_filter(array_map('intval', $insertedIds), static fn($id) => $id > 0));
        if (!$insertedIds) return 0;
        brReconEnsureMatchingSchema($conn);

        $newSource = strtolower(trim($newSource));
        $newTable = $newSource === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
        $otherTable = $newSource === 'bank' ? 'bank_recon_ledger_lines' : 'bank_recon_bank_lines';

        $newLines = brReconFetchLinesForCategorySettlement($conn, $newTable, $reconId, $insertedIds, false);
        if (!$newLines) return 0;

        $categoryLines = brReconFetchLinesForCategorySettlement($conn, $otherTable, $reconId, null, true);
        if (!$categoryLines) return 0;

        $groups = [];
        foreach ($categoryLines as $line) {
            $categoryKey = brReconCategoryKeyForLine($line);
            if ($categoryKey === '') continue;
            $direction = strtoupper((string)($line['direction'] ?? ''));
            if (!in_array($direction, ['IN','OUT'], true)) continue;
            $classification = trim((string)($line['recon_classification'] ?? ''));
            $groupKey = $direction . '|' . brReconCategoryNorm($categoryKey) . '|' . brReconCategoryNorm($classification);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'direction' => $direction,
                    'category_key' => $categoryKey,
                    'classification' => $classification,
                    'total' => 0.0,
                    'lines' => [],
                    'dr_ledger' => '',
                    'cr_ledger' => '',
                    'used' => false,
                ];
            }
            $groups[$groupKey]['total'] = round($groups[$groupKey]['total'] + brReconOutstandingAmount($line), 2);
            $groups[$groupKey]['lines'][] = $line;
            if ($groups[$groupKey]['dr_ledger'] === '' && trim((string)($line['suggested_dr_ledger'] ?? '')) !== '') $groups[$groupKey]['dr_ledger'] = (string)$line['suggested_dr_ledger'];
            if ($groups[$groupKey]['cr_ledger'] === '' && trim((string)($line['suggested_cr_ledger'] ?? '')) !== '') $groups[$groupKey]['cr_ledger'] = (string)$line['suggested_cr_ledger'];
        }

        if (!$groups) return 0;

        $mIns = $conn->prepare("INSERT INTO bank_recon_matches
            (recon_id, match_group, bank_line_id, ledger_line_id, bank_allocated_amount, ledger_allocated_amount, is_partial, match_note, match_type, confidence, amount_difference, day_difference, matched_by)
            VALUES (?,?,?,?,?,?,0,?,'Auto',?,?,?,?)");
        if (!$mIns) return 0;

        $autoMatched = 0;
        foreach ($newLines as $newLine) {
            $newDirection = strtoupper((string)($newLine['direction'] ?? ''));
            $newOutstanding = brReconOutstandingAmount($newLine);
            if ($newOutstanding <= 0.009) continue;

            $candidates = [];
            foreach ($groups as $idx => $group) {
                if (!empty($group['used'])) continue;
                if ($group['direction'] !== $newDirection) continue;
                $amountDiff = round(abs((float)$group['total'] - $newOutstanding), 2);
                if ($amountDiff > max($tolAmt, 0.01)) continue;
                $score = brReconCategoryMatchScore($newLine, $group, $amountDiff, $tolAmt);
                $candidates[] = ['idx' => $idx, 'score' => $score, 'amount_diff' => $amountDiff];
            }

            if (!$candidates) continue;
            usort($candidates, static function($a, $b) {
                if ($a['score'] === $b['score']) return $a['amount_diff'] <=> $b['amount_diff'];
                return $b['score'] <=> $a['score'];
            });

            $best = $candidates[0];
            if ((int)$best['score'] < 70) continue;
            if (count($candidates) > 1 && (int)$best['score'] === (int)$candidates[1]['score'] && (float)$best['amount_diff'] === (float)$candidates[1]['amount_diff']) {
                // Avoid guessing when two category groups look equally likely.
                continue;
            }

            $group = $groups[$best['idx']];
            $mg = 'ACAT-' . str_pad((string)$matchSeq++, 4, '0', STR_PAD_LEFT) . '-' . $reconId;
            $note = 'Auto category total match: ' . substr((string)$group['category_key'], 0, 170);
            $confidence = min(99, max(70, (int)$best['score']));
            $amountDifference = round((float)$best['amount_diff'], 2);
            $newId = (int)$newLine['id'];
            $newDateTs = strtotime((string)($newLine['txn_date'] ?? '')) ?: 0;
            $remaining = $newOutstanding;
            $allocated = 0.0;

            foreach ($group['lines'] as $categoryLine) {
                if ($remaining <= 0.009) break;
                $available = brReconOutstandingAmount($categoryLine);
                if ($available <= 0.009) continue;
                $alloc = round(min($remaining, $available), 2);
                if ($alloc <= 0.009) continue;

                if ($newSource === 'bank') {
                    $bankId = $newId;
                    $ledgerId = (int)$categoryLine['id'];
                } else {
                    $bankId = (int)$categoryLine['id'];
                    $ledgerId = $newId;
                }

                $catDateTs = strtotime((string)($categoryLine['txn_date'] ?? '')) ?: $newDateTs;
                $dayDifference = $newDateTs && $catDateTs ? (int)(abs($newDateTs - $catDateTs) / 86400) : 0;
                $bankAllocated = $alloc;
                $ledgerAllocated = $alloc;
                $byValue = $by;
                $mIns->bind_param('isiiddsidis', $reconId, $mg, $bankId, $ledgerId, $bankAllocated, $ledgerAllocated, $note, $confidence, $amountDifference, $dayDifference, $byValue);
                $mIns->execute();

                brReconApplyMatchedDelta($conn, $otherTable, (int)$categoryLine['id'], $alloc, max($tolAmt, 0.01), $mg);
                $conn->query("UPDATE {$otherTable} SET auto_matched=1 WHERE id=" . (int)$categoryLine['id'] . " AND recon_id=" . (int)$reconId);

                $remaining = round($remaining - $alloc, 2);
                $allocated = round($allocated + $alloc, 2);
            }

            if (abs($allocated - $newOutstanding) > max($tolAmt, 0.01)) {
                // Defensive rollback for this generated group if a concurrent update changed availability.
                $mgEsc = $conn->real_escape_string($mg);
                $conn->query("DELETE FROM bank_recon_matches WHERE recon_id=" . (int)$reconId . " AND match_group='{$mgEsc}'");
                continue;
            }

            brReconApplyMatchedDelta($conn, $newTable, $newId, $allocated, max($tolAmt, 0.01), $mg);
            $conn->query("UPDATE {$newTable} SET auto_matched=1 WHERE id={$newId} AND recon_id=" . (int)$reconId);
            $groups[$best['idx']]['used'] = true;
            $autoMatched++;
        }

        $mIns->close();
        return $autoMatched;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   Smart Reconciliation Engine helpers
   - Bank upload profile memory
   - Learning from accepted monthly patterns
   - Reconciliation difference explanation
   These helpers are additive and do not restructure the existing schema.
═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('brReconEnsureUploadProfileSchema')) {
    function brReconEnsureUploadProfileSchema(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS bank_recon_upload_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(20) NOT NULL DEFAULT 'bank',
            bank_name VARCHAR(255) NULL,
            account_number VARCHAR(80) NULL,
            currency VARCHAR(10) NULL,
            file_extension VARCHAR(20) NULL,
            header_signature CHAR(64) NOT NULL,
            original_headers TEXT NULL,
            normalized_headers TEXT NULL,
            column_mapping TEXT NULL,
            first_seen_recon_id INT NULL,
            last_seen_recon_id INT NULL,
            use_count INT NOT NULL DEFAULT 1,
            last_file_name VARCHAR(255) NULL,
            created_by VARCHAR(160) NULL,
            updated_by VARCHAR(160) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_br_upload_profile_lookup (source, header_signature, bank_name(80), account_number(40)),
            INDEX idx_br_upload_profile_last_seen (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('brReconEnsureLearnedPatternSchema')) {
    function brReconEnsureLearnedPatternSchema(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS bank_recon_learned_patterns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(20) NOT NULL DEFAULT 'bank',
            bank_name VARCHAR(255) NULL,
            account_number VARCHAR(80) NULL,
            currency VARCHAR(10) NULL,
            direction VARCHAR(10) NOT NULL DEFAULT 'ANY',
            pattern_key CHAR(64) NOT NULL,
            pattern_text VARCHAR(255) NOT NULL,
            category_name VARCHAR(120) NOT NULL,
            recon_classification VARCHAR(120) NOT NULL,
            suggested_dr_ledger VARCHAR(160) NULL,
            suggested_cr_ledger VARCHAR(160) NULL,
            confidence DECIMAL(5,2) NOT NULL DEFAULT 72.00,
            use_count INT NOT NULL DEFAULT 1,
            accepted_count INT NOT NULL DEFAULT 1,
            last_recon_id INT NULL,
            last_line_id INT NULL,
            last_seen_at TIMESTAMP NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(160) NULL,
            updated_by VARCHAR(160) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_br_learned_pattern (pattern_key),
            INDEX idx_br_learned_pattern_lookup (is_active, source, direction, bank_name(80), account_number(40)),
            INDEX idx_br_learned_pattern_confidence (confidence, use_count)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('brReconEnsureDifferenceSchema')) {
    function brReconEnsureDifferenceSchema(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS bank_recon_difference_explanations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recon_id INT NOT NULL,
            difference_amount DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(40) NOT NULL DEFAULT 'Unbalanced',
            explanation_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_br_difference_recon (recon_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('brReconEnsureSmartSchema')) {
    function brReconEnsureSmartSchema(mysqli $conn): void
    {
        brReconEnsureMatchingSchema($conn);
        brReconEnsureRuleSchema($conn);
        brReconEnsureUploadProfileSchema($conn);
        brReconEnsureLearnedPatternSchema($conn);
        brReconEnsureDifferenceSchema($conn);
    }
}

if (!function_exists('brReconNormToken')) {
    function brReconNormToken(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', (string)$value));
    }
}

if (!function_exists('brReconHeadersFromRows')) {
    function brReconHeadersFromRows(array $rows): array
    {
        if (!$rows) return [];
        $first = reset($rows);
        return is_array($first) ? array_values(array_map('strval', array_keys($first))) : [];
    }
}

if (!function_exists('brReconHeaderSignature')) {
    function brReconHeaderSignature(array $headers): string
    {
        $norm = array_map('brReconNormToken', $headers);
        $norm = array_values(array_filter($norm, static fn($v) => $v !== ''));
        return hash('sha256', implode('|', $norm));
    }
}

if (!function_exists('brReconDetectColumnMapping')) {
    function brReconDetectColumnMapping(array $headers, string $source): array
    {
        $norm = array_map('brReconNormToken', $headers);
        $pick = static function(array $candidates) use ($norm, $headers): ?string {
            foreach ($candidates as $candidate) {
                $candidateNorm = brReconNormToken($candidate);
                foreach ($norm as $i => $headerNorm) {
                    if ($headerNorm === $candidateNorm || strpos($headerNorm, $candidateNorm) !== false) {
                        return (string)($headers[$i] ?? $headerNorm);
                    }
                }
            }
            return null;
        };

        if (strtolower($source) === 'ledger') {
            return [
                'date' => $pick(['date','transaction date','journal date','posting date','entry date','value date']),
                'description' => $pick(['description','narration','details','particulars','remarks']),
                'reference' => $pick(['reference','ref','folio','journal number','voucher']),
                'ledger_name' => $pick(['ledger','ledger name','account','account name','bank account']),
                'debit' => $pick(['debit','dr']),
                'credit' => $pick(['credit','cr']),
                'balance' => $pick(['balance','running balance']),
            ];
        }

        return [
            'date' => $pick(['date','create date','transaction date','txn date','posting date','value date','effective date']),
            'description' => $pick(['description','description payee memo','description/payee/memo','narration','details','remarks','particulars']),
            'reference' => $pick(['reference','ref','check no','cheque no']),
            'debit' => $pick(['debit','debit amount','withdrawal','dr','money out']),
            'credit' => $pick(['credit','credit amount','deposit','cr','money in']),
            'balance' => $pick(['balance','running balance','closing balance']),
        ];
    }
}

if (!function_exists('brReconRememberUploadProfileFromHeaders')) {
    function brReconRememberUploadProfileFromHeaders(mysqli $conn, int $reconId, string $source, array $headers, string $fileName, string $by = 'system'): ?array
    {
        brReconEnsureUploadProfileSchema($conn);
        $headers = array_values(array_filter(array_map('strval', $headers), static fn($h) => trim($h) !== ''));
        if (!$headers) return null;

        $recon = $conn->query('SELECT bank_name, account_number, currency FROM bank_recons WHERE id=' . (int)$reconId . ' LIMIT 1')->fetch_assoc() ?: [];
        $source = strtolower(trim($source));
        if (!in_array($source, ['bank','ledger'], true)) $source = 'bank';
        $bankName = trim((string)($recon['bank_name'] ?? ''));
        $accountNumber = trim((string)($recon['account_number'] ?? ''));
        $currency = trim((string)($recon['currency'] ?? 'NGN'));
        $signature = brReconHeaderSignature($headers);
        $originalHeaders = json_encode(array_values($headers), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $normalizedHeaders = json_encode(array_map('brReconNormToken', $headers), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $mapping = json_encode(brReconDetectColumnMapping($headers, $source), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $stmt = $conn->prepare("SELECT id, use_count FROM bank_recon_upload_profiles
            WHERE source=? AND header_signature=? AND COALESCE(bank_name,'')=? AND COALESCE(account_number,'')=?
            LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('ssss', $source, $signature, $bankName, $accountNumber);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $id = (int)$existing['id'];
            $stmt = $conn->prepare("UPDATE bank_recon_upload_profiles
                SET currency=?, file_extension=?, original_headers=?, normalized_headers=?, column_mapping=?, last_seen_recon_id=?, use_count=use_count+1, last_file_name=?, updated_by=?
                WHERE id=?");
            if ($stmt) {
                $stmt->bind_param('sssssissi', $currency, $ext, $originalHeaders, $normalizedHeaders, $mapping, $reconId, $fileName, $by, $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO bank_recon_upload_profiles
                (source, bank_name, account_number, currency, file_extension, header_signature, original_headers, normalized_headers, column_mapping, first_seen_recon_id, last_seen_recon_id, use_count, last_file_name, created_by, updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)");
            if ($stmt) {
                $stmt->bind_param('sssssssssiisss', $source, $bankName, $accountNumber, $currency, $ext, $signature, $originalHeaders, $normalizedHeaders, $mapping, $reconId, $reconId, $fileName, $by, $by);
                $stmt->execute();
                $stmt->close();
            }
        }

        $profile = $conn->query("SELECT * FROM bank_recon_upload_profiles WHERE source='" . $conn->real_escape_string($source) . "' AND header_signature='" . $conn->real_escape_string($signature) . "' AND COALESCE(bank_name,'')='" . $conn->real_escape_string($bankName) . "' AND COALESCE(account_number,'')='" . $conn->real_escape_string($accountNumber) . "' LIMIT 1")->fetch_assoc();
        if ($profile) {
            $profile['headers'] = json_decode((string)($profile['original_headers'] ?? '[]'), true) ?: [];
            $profile['column_mapping'] = json_decode((string)($profile['column_mapping'] ?? '{}'), true) ?: [];
        }
        return $profile ?: null;
    }
}

if (!function_exists('brReconRememberUploadProfile')) {
    function brReconRememberUploadProfile(mysqli $conn, int $reconId, string $source, array $rawRows, string $fileName, string $by = 'system'): ?array
    {
        $headers = brReconHeadersFromRows($rawRows);
        if (!$headers) return null;
        return brReconRememberUploadProfileFromHeaders($conn, $reconId, $source, $headers, $fileName, $by);
    }
}


if (!function_exists('brReconPatternText')) {
    function brReconPatternText(?string $description): string
    {
        $text = brReconNormToken($description);
        $tokens = preg_split('/\s+/', $text) ?: [];
        $stop = array_flip(['the','and','for','from','with','into','being','payment','transfer','txn','trf','ref','narration','transaction','date','value','to','of','by','on','at','ngn','usd','eur','gbp']);
        $months = array_flip(['jan','january','feb','february','mar','march','apr','april','may','jun','june','jul','july','aug','august','sep','sept','september','oct','october','nov','november','dec','december']);
        $kept = [];
        foreach ($tokens as $token) {
            if ($token === '' || isset($stop[$token]) || isset($months[$token])) continue;
            if (preg_match('/^\d+$/', $token)) continue;
            if (strlen($token) < 3) continue;
            $kept[] = $token;
            if (count($kept) >= 9) break;
        }
        if (!$kept) $kept = array_slice(array_values(array_filter($tokens)), 0, 7);
        return trim(implode(' ', $kept));
    }
}

if (!function_exists('brReconLearnPatternKey')) {
    function brReconLearnPatternKey(string $source, string $bankName, string $accountNumber, string $direction, string $patternText, string $category, string $classification): string
    {
        return hash('sha256', strtolower(implode('|', [$source, $bankName, $accountNumber, $direction, $patternText, $category, $classification])));
    }
}

if (!function_exists('brReconLearnFromLine')) {
    function brReconLearnFromLine(mysqli $conn, int $reconId, string $source, int $lineId, string $by = 'system'): bool
    {
        brReconEnsureLearnedPatternSchema($conn);
        $source = strtolower(trim($source));
        $table = $source === 'ledger' ? 'bank_recon_ledger_lines' : 'bank_recon_bank_lines';
        $line = $conn->query("SELECT * FROM {$table} WHERE id=" . (int)$lineId . " AND recon_id=" . (int)$reconId . " LIMIT 1")->fetch_assoc();
        if (!$line) return false;
        $category = trim((string)($line['category_name'] ?? ($line['bank_only_type'] ?? '')));
        $classification = trim((string)($line['recon_classification'] ?? ''));
        if ($category === '' || $classification === '') return false;

        $recon = $conn->query('SELECT bank_name, account_number, currency, status FROM bank_recons WHERE id=' . (int)$reconId . ' LIMIT 1')->fetch_assoc() ?: [];
        $bankName = trim((string)($recon['bank_name'] ?? ''));
        $accountNumber = trim((string)($recon['account_number'] ?? ''));
        $currency = trim((string)($recon['currency'] ?? 'NGN'));
        $direction = strtoupper(trim((string)($line['direction'] ?? 'ANY')));
        if (!in_array($direction, ['IN','OUT'], true)) $direction = 'ANY';
        $patternText = brReconPatternText($line['description'] ?? '');
        if ($patternText === '') return false;
        $key = brReconLearnPatternKey($source, $bankName, $accountNumber, $direction, $patternText, $category, $classification);
        $dr = trim((string)($line['suggested_dr_ledger'] ?? ''));
        $cr = trim((string)($line['suggested_cr_ledger'] ?? ''));
        $baseConfidence = in_array((string)($recon['status'] ?? ''), ['Balanced'], true) ? 82.0 : 74.0;

        $stmt = $conn->prepare('SELECT id FROM bank_recon_learned_patterns WHERE pattern_key=? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $id = (int)$existing['id'];
            $stmt = $conn->prepare("UPDATE bank_recon_learned_patterns
                SET confidence=LEAST(99.00, confidence+3.00), use_count=use_count+1, accepted_count=accepted_count+1,
                    suggested_dr_ledger=?, suggested_cr_ledger=?, last_recon_id=?, last_line_id=?, last_seen_at=NOW(), updated_by=?, is_active=1
                WHERE id=?");
            if (!$stmt) return false;
            $stmt->bind_param('ssiisi', $dr, $cr, $reconId, $lineId, $by, $id);
            $stmt->execute();
            $stmt->close();
            return true;
        }

        $stmt = $conn->prepare("INSERT INTO bank_recon_learned_patterns
            (source, bank_name, account_number, currency, direction, pattern_key, pattern_text, category_name, recon_classification, suggested_dr_ledger, suggested_cr_ledger, confidence, use_count, accepted_count, last_recon_id, last_line_id, last_seen_at, created_by, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,1,?,?,NOW(),?,?)");
        if (!$stmt) return false;
        $stmt->bind_param('sssssssssssdiiss', $source, $bankName, $accountNumber, $currency, $direction, $key, $patternText, $category, $classification, $dr, $cr, $baseConfidence, $reconId, $lineId, $by, $by);
        $stmt->execute();
        $stmt->close();
        return true;
    }
}

if (!function_exists('brReconLearnFromClassifiedLines')) {
    function brReconLearnFromClassifiedLines(mysqli $conn, int $reconId, string $source, array $lineIds, string $by = 'system'): int
    {
        $count = 0;
        foreach (array_values(array_unique(array_filter(array_map('intval', $lineIds)))) as $lineId) {
            if ($lineId > 0 && brReconLearnFromLine($conn, $reconId, $source, $lineId, $by)) $count++;
        }
        return $count;
    }
}

if (!function_exists('brReconFindLearnedPattern')) {
    function brReconFindLearnedPattern(mysqli $conn, int $reconId, string $source, string $description, string $direction): ?array
    {
        brReconEnsureLearnedPatternSchema($conn);
        $source = strtolower(trim($source));
        $direction = strtoupper(trim($direction));
        $recon = $conn->query('SELECT bank_name, account_number, currency FROM bank_recons WHERE id=' . (int)$reconId . ' LIMIT 1')->fetch_assoc() ?: [];
        $bankName = trim((string)($recon['bank_name'] ?? ''));
        $accountNumber = trim((string)($recon['account_number'] ?? ''));
        $patternText = brReconPatternText($description);
        if ($patternText === '') return null;

        $stmt = $conn->prepare("SELECT * FROM bank_recon_learned_patterns
            WHERE is_active=1
              AND source=?
              AND (direction='ANY' OR direction=?)
              AND (COALESCE(bank_name,'')='' OR bank_name=?)
              AND (COALESCE(account_number,'')='' OR account_number=?)
            ORDER BY confidence DESC, use_count DESC, last_seen_at DESC
            LIMIT 100");
        if (!$stmt) return null;
        $stmt->bind_param('ssss', $source, $direction, $bankName, $accountNumber);
        $stmt->execute();
        $res = $stmt->get_result();
        $best = null;
        $bestScore = 0.0;
        while ($row = $res->fetch_assoc()) {
            $candidate = (string)($row['pattern_text'] ?? '');
            if ($candidate === '') continue;
            similar_text($patternText, $candidate, $pct);
            $substring = strpos($patternText, $candidate) !== false || strpos($candidate, $patternText) !== false;
            $score = ($substring ? 95.0 : (float)$pct) + min(8.0, ((float)($row['use_count'] ?? 1)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
        $stmt->close();

        if (!$best || $bestScore < 70.0) return null;
        $confidence = max((float)($best['confidence'] ?? 72), min(99.0, $bestScore));
        return [
            'category' => (string)$best['category_name'],
            'classification' => (string)$best['recon_classification'],
            'dr_ledger' => (string)($best['suggested_dr_ledger'] ?? ''),
            'cr_ledger' => (string)($best['suggested_cr_ledger'] ?? ''),
            'note' => 'Auto-categorised from learned monthly pattern: ' . (string)$best['pattern_text'] . ' (' . number_format($confidence, 0) . '% confidence).',
            'confidence' => round($confidence, 2),
            'pattern_id' => (int)$best['id'],
        ];
    }
}

if (!function_exists('brReconFetchUploadProfilesForRecon')) {
    function brReconFetchUploadProfilesForRecon(mysqli $conn, int $reconId): array
    {
        brReconEnsureUploadProfileSchema($conn);
        $res = $conn->query('SELECT * FROM bank_recon_upload_profiles WHERE first_seen_recon_id=' . (int)$reconId . ' OR last_seen_recon_id=' . (int)$reconId . ' ORDER BY source, id DESC');
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($rows as &$row) {
            $row['mapping'] = json_decode((string)($row['column_mapping'] ?? '{}'), true) ?: [];
            $row['headers'] = json_decode((string)($row['original_headers'] ?? '[]'), true) ?: [];
        }
        return $rows;
    }
}

if (!function_exists('brReconFetchLearnedPatternsForRecon')) {
    function brReconFetchLearnedPatternsForRecon(mysqli $conn, int $reconId): array
    {
        brReconEnsureLearnedPatternSchema($conn);
        $recon = $conn->query('SELECT bank_name, account_number FROM bank_recons WHERE id=' . (int)$reconId . ' LIMIT 1')->fetch_assoc() ?: [];
        $bankName = trim((string)($recon['bank_name'] ?? ''));
        $accountNumber = trim((string)($recon['account_number'] ?? ''));
        $stmt = $conn->prepare("SELECT * FROM bank_recon_learned_patterns
            WHERE is_active=1
              AND (COALESCE(bank_name,'')='' OR bank_name=?)
              AND (COALESCE(account_number,'')='' OR account_number=?)
            ORDER BY confidence DESC, use_count DESC, updated_at DESC
            LIMIT 25");
        if (!$stmt) return [];
        $stmt->bind_param('ss', $bankName, $accountNumber);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }
}

if (!function_exists('brReconBuildDifferenceExplanation')) {
    function brReconBuildDifferenceExplanation(mysqli $conn, int $reconId, array $recon, array $bankLines, array $ledgerLines, array $summary): array
    {
        $diff = brReconNormalizeDifference((float)($summary['diff'] ?? $summary['unreconciled_difference'] ?? $recon['unreconciled_difference'] ?? 0));
        $absDiff = abs($diff);
        $currency = (string)($recon['currency'] ?? 'NGN');
        $causes = [];
        $actions = [];
        $riskFlags = [];

        $sumLines = static function(array $lines, callable $filter): float {
            $total = 0.0;
            foreach ($lines as $line) {
                if ($filter($line)) $total += (float)($line['outstanding_amount'] ?? $line['amount'] ?? 0);
            }
            return round($total, 2);
        };

        $bankUnmatchedOut = $sumLines($bankLines, static fn($l) => ($l['match_status'] ?? '') === 'Unmatched' && ($l['direction'] ?? '') === 'OUT');
        $bankUnmatchedIn = $sumLines($bankLines, static fn($l) => ($l['match_status'] ?? '') === 'Unmatched' && ($l['direction'] ?? '') === 'IN');
        $ledgerUnmatchedOut = $sumLines($ledgerLines, static fn($l) => ($l['match_status'] ?? '') === 'Unmatched' && ($l['direction'] ?? '') === 'OUT');
        $ledgerUnmatchedIn = $sumLines($ledgerLines, static fn($l) => ($l['match_status'] ?? '') === 'Unmatched' && ($l['direction'] ?? '') === 'IN');
        $partialBank = $sumLines($bankLines, static fn($l) => !empty($l['is_partially_matched']));
        $partialLedger = $sumLines($ledgerLines, static fn($l) => !empty($l['is_partially_matched']));
        $unclassified = $bankUnmatchedOut + $bankUnmatchedIn + $ledgerUnmatchedOut + $ledgerUnmatchedIn;
        $noMovementPeriod = count($bankLines) === 0 && count($ledgerLines) === 0;

        if ($noMovementPeriod && $absDiff > 0.01) {
            $causes[] = [
                'type' => 'no_movement_balance_difference',
                'label' => 'No-movement balance difference',
                'description' => 'No bank or ledger transaction lines were uploaded for this period, but the bank and ledger closing balances are not equal.',
                'amount' => $absDiff,
            ];
        }

        $classifiedTotal = 0.0;
        foreach ([
            "We Debit They Don't Credit" => 'ledger debit items not yet in bank',
            "They Debit We Don't Credit" => 'bank debits not yet posted in ledger',
            "We Credit They Don't Debit" => 'ledger credit items not yet in bank',
            "They Credit We Don't Debit" => 'bank credits not yet posted in ledger',
        ] as $class => $label) {
            $amount = 0.0;
            foreach (array_merge($bankLines, $ledgerLines) as $line) {
                if (in_array((string)($line['match_status'] ?? ''), ['Classified','Bank-Only'], true) && (string)($line['recon_classification'] ?? '') === $class) {
                    $amount += (float)($line['outstanding_amount'] ?? $line['amount'] ?? 0);
                }
            }
            $amount = round($amount, 2);
            if ($amount > 0.009) {
                $classifiedTotal += $amount;
                $causes[] = [
                    'type' => 'classified_exception',
                    'label' => $class,
                    'description' => ucfirst($label) . ' already classified but not fully cleared.',
                    'amount' => $amount,
                ];
            }
        }

        if ($bankUnmatchedOut > 0.009) $causes[] = ['type' => 'unclassified_bank_debit', 'label' => 'Unclassified bank debits', 'description' => 'Bank debit lines still need matching, posting or categorisation.', 'amount' => $bankUnmatchedOut];
        if ($bankUnmatchedIn > 0.009) $causes[] = ['type' => 'unclassified_bank_credit', 'label' => 'Unclassified bank credits', 'description' => 'Bank credit lines still need matching, posting or categorisation.', 'amount' => $bankUnmatchedIn];
        if ($ledgerUnmatchedOut > 0.009) $causes[] = ['type' => 'unclassified_ledger_credit', 'label' => 'Unmatched ledger credits', 'description' => 'Ledger credit lines have not yet appeared in the bank extract.', 'amount' => $ledgerUnmatchedOut];
        if ($ledgerUnmatchedIn > 0.009) $causes[] = ['type' => 'unclassified_ledger_debit', 'label' => 'Unmatched ledger debits', 'description' => 'Ledger debit lines have not yet appeared in the bank extract.', 'amount' => $ledgerUnmatchedIn];
        if (($partialBank + $partialLedger) > 0.009) $causes[] = ['type' => 'partial_balance', 'label' => 'Partially matched balances', 'description' => 'Some grouped matches still have balances outstanding after allocation.', 'amount' => round($partialBank + $partialLedger, 2)];

        if ($noMovementPeriod && $absDiff <= 0.01) {
            $headline = 'No transactions were uploaded for this period and the closing balances agree. The period is reconciled as a no-movement month.';
            $actions[] = 'Keep the heading-only bank and ledger extracts with this reconciliation as the monthly audit trail.';
        } elseif ($noMovementPeriod) {
            $headline = 'No transactions were uploaded for this period, but the bank and ledger closing balances differ by ' . $currency . ' ' . number_format($absDiff, 2) . '.';
            $actions[] = 'Confirm the opening and closing balances entered for both bank and ledger.';
            $actions[] = 'If there were hidden movements, upload the corrected bank or ledger extract with transaction lines.';
        } elseif ($absDiff <= 0.01) {
            $headline = 'The reconciliation balances. No unresolved difference remains after matched lines and classified reconciling items.';
            $actions[] = 'Keep the category sheets/Excel attachment as supporting schedules for posted and outstanding items.';
        } else {
            $headline = 'The reconciliation is out by ' . $currency . ' ' . number_format($absDiff, 2) . '.';
            if ($unclassified > 0.009) $actions[] = 'Review unmatched/unclassified bank and ledger lines first because they are not yet explaining the difference.';
            if ($classifiedTotal > 0.009) $actions[] = 'Post or clear classified exceptions that should now exist in the ledger, then re-match them.';
            if (($partialBank + $partialLedger) > 0.009) $actions[] = 'Open partial groups and allocate or clear the remaining balances.';
            $actions[] = 'Re-run auto rules after adding new bank/category patterns for recurring descriptions.';
        }

        $openingGap = round((float)($recon['bank_opening'] ?? 0) - (float)($recon['ledger_opening'] ?? 0), 2);
        if (abs($openingGap) > max((float)($recon['tolerance_amount'] ?? 0), 0.01)) {
            $riskFlags[] = [
                'type' => 'opening_balance_gap',
                'label' => 'Opening balance gap',
                'description' => 'Bank and ledger opening balances are different before current-period transactions are considered.',
                'amount' => $openingGap,
            ];
        }

        $explanation = [
            'status' => $absDiff <= 0.01 ? 'Balanced' : 'Unbalanced',
            'currency' => $currency,
            'difference' => $diff,
            'absolute_difference' => $absDiff,
            'headline' => $headline,
            'causes' => $causes,
            'actions' => $actions,
            'risk_flags' => $riskFlags,
            'no_movement_period' => $noMovementPeriod,
            'generated_at' => date('c'),
        ];

        brReconEnsureDifferenceSchema($conn);
        $json = json_encode($explanation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $status = $explanation['status'];
        $stmt = $conn->prepare("INSERT INTO bank_recon_difference_explanations (recon_id, difference_amount, status, explanation_json)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE difference_amount=VALUES(difference_amount), status=VALUES(status), explanation_json=VALUES(explanation_json), updated_at=NOW()");
        if ($stmt) {
            $stmt->bind_param('idss', $reconId, $diff, $status, $json);
            $stmt->execute();
            $stmt->close();
        }

        return $explanation;
    }
}

