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

require_once __DIR__ . '/reconMatchingHelpers.php';

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
    function brAutoCategoryForLine(string $source, string $description, string $direction, int $reconId = 0, string $reference = ''): ?array
    {
        $source = strtolower(trim($source));
        $direction = strtoupper(trim($direction));

        // Configurable rules take priority over learned patterns and defaults.
        if (function_exists('brReconFindAutoRule') && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $rule = brReconFindAutoRule($GLOBALS['conn'], $source, $description, $direction, $reference);
            if ($rule) {
                return [
                    'category' => (string)$rule['category_name'],
                    'classification' => (string)$rule['recon_classification'],
                    'dr_ledger' => (string)($rule['suggested_dr_ledger'] ?? ''),
                    'cr_ledger' => (string)($rule['suggested_cr_ledger'] ?? ''),
                    'note' => 'Auto-categorised by rule: ' . (string)$rule['rule_name'],
                    'origin' => 'rule',
                    'rule_id' => (int)($rule['id'] ?? 0),
                ];
            }
        }

        // Learned monthly patterns come after explicit rules and before defaults.
        if ($reconId > 0 && function_exists('brReconFindLearnedPattern') && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $learned = brReconFindLearnedPattern($GLOBALS['conn'], $reconId, $source, $description, $direction);
            if ($learned) {
                $learned['origin'] = 'learned';
                $learned['rule_id'] = null;
                return $learned;
            }
        }

        // Hardcoded Smartbooks defaults are intentionally bank-side only.
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
            'origin' => 'default',
            'rule_id' => null,
        ];
    }
}

if (!function_exists('brAutoLineHasClassification')) {
    function brAutoLineHasClassification(array $line): bool
    {
        return trim((string)($line['category_name'] ?? '')) !== ''
            || trim((string)($line['bank_only_type'] ?? '')) !== ''
            || trim((string)($line['recon_classification'] ?? '')) !== ''
            || in_array((string)($line['match_status'] ?? ''), ['Classified', 'Bank-Only'], true);
    }
}

if (!function_exists('brAutoInferClassificationOrigin')) {
    function brAutoInferClassificationOrigin(array $line): string
    {
        $origin = strtolower(trim((string)($line['classification_origin'] ?? '')));
        if (in_array($origin, ['manual', 'rule', 'learned', 'default'], true)) {
            return $origin;
        }

        $note = (string)($line['journal_note'] ?? '');
        if (stripos($note, 'Auto-categorised by rule:') === 0) return 'rule';
        if (stripos($note, 'Auto-categorised from learned monthly pattern:') === 0) return 'learned';
        if (stripos($note, 'Auto-categorised during reconciliation upload') === 0) return 'default';
        return brAutoLineHasClassification($line) ? 'manual' : '';
    }
}

if (!function_exists('brAutoCaptureClassifications')) {
    function brAutoCaptureClassifications(mysqli $conn, int $reconId, string $source): array
    {
        brReconEnsureClassificationMetadataSchema($conn);
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
                'category_name' => trim((string)($line['category_name'] ?? '')) !== ''
                    ? (string)$line['category_name']
                    : (string)($line['bank_only_type'] ?? ''),
                'recon_classification' => (string)($line['recon_classification'] ?? ''),
                'suggested_dr_ledger' => (string)($line['suggested_dr_ledger'] ?? ''),
                'suggested_cr_ledger' => (string)($line['suggested_cr_ledger'] ?? ''),
                'journal_note' => (string)($line['journal_note'] ?? ''),
                'classification_origin' => brAutoInferClassificationOrigin($line),
                'classification_rule_id' => isset($line['classification_rule_id']) ? (int)$line['classification_rule_id'] : null,
                'classification_locked' => (int)($line['classification_locked'] ?? 0),
            ];

            if (!isset($map['exact'][$key])) {
                $map['exact'][$key] = [];
            }
            $map['exact'][$key][] = $snapshot;

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

        brReconEnsureClassificationMetadataSchema($conn);
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
                    journal_note=?,
                    classification_origin=?,
                    classification_rule_id=?,
                    classification_locked=?
                WHERE id=? AND recon_id=? AND match_status='Unmatched'");
        } else {
            $stmt = $conn->prepare("UPDATE bank_recon_ledger_lines
                SET match_status=?,
                    category_name=?,
                    recon_classification=?,
                    suggested_dr_ledger=?,
                    suggested_cr_ledger=?,
                    journal_note=?,
                    classification_origin=?,
                    classification_rule_id=?,
                    classification_locked=?
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
            $origin = $snapshot['classification_origin'] ?: 'manual';
            $ruleId = $snapshot['classification_rule_id'];
            $locked = (int)($snapshot['classification_locked'] ?? ($origin === 'manual' ? 1 : 0));
            $lineId = (int)$line['id'];

            if ($category === '' && $classification === '') {
                continue;
            }

            if ($source === 'bank') {
                $bankOnlyType = $snapshot['bank_only_type'] ?: $category;
                $stmt->bind_param('ssssssssiiii', $status, $bankOnlyType, $category, $classification, $dr, $cr, $note, $origin, $ruleId, $locked, $lineId, $reconId);
            } else {
                $stmt->bind_param('sssssssiiii', $status, $category, $classification, $dr, $cr, $note, $origin, $ruleId, $locked, $lineId, $reconId);
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
        brReconEnsureRuleSchema($conn);
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
                    journal_note=?,
                    classification_origin=?,
                    classification_rule_id=?,
                    classification_locked=0
                WHERE id=? AND recon_id=? AND match_status='Unmatched'");
        } else {
            $stmt = $conn->prepare("UPDATE bank_recon_ledger_lines
                SET match_status='Classified',
                    category_name=?,
                    recon_classification=?,
                    suggested_dr_ledger=?,
                    suggested_cr_ledger=?,
                    journal_note=?,
                    classification_origin=?,
                    classification_rule_id=?,
                    classification_locked=0
                WHERE id=? AND recon_id=? AND match_status='Unmatched'");
        }

        if (!$stmt) {
            return 0;
        }

        $count = 0;
        while ($line = $res->fetch_assoc()) {
            $rule = brAutoCategoryForLine($source, (string)$line['description'], (string)$line['direction'], $reconId, (string)($line['reference'] ?? ''));
            if (!$rule) {
                continue;
            }

            $category = $rule['category'];
            $classification = $rule['classification'];
            $dr = $rule['dr_ledger'];
            $cr = $rule['cr_ledger'];
            $note = $rule['note'];
            $origin = (string)($rule['origin'] ?? 'default');
            $ruleId = isset($rule['rule_id']) && $rule['rule_id'] ? (int)$rule['rule_id'] : null;
            $lineId = (int)$line['id'];

            if ($source === 'bank') {
                $stmt->bind_param('sssssssiii', $category, $category, $classification, $dr, $cr, $note, $origin, $ruleId, $lineId, $reconId);
            } else {
                $stmt->bind_param('ssssssiii', $category, $classification, $dr, $cr, $note, $origin, $ruleId, $lineId, $reconId);
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

if (!function_exists('brAutoSameClassification')) {
    function brAutoSameClassification(array $line, array $next): bool
    {
        $currentCategory = trim((string)($line['category_name'] ?? ''));
        if ($currentCategory === '') $currentCategory = trim((string)($line['bank_only_type'] ?? ''));
        $currentRuleId = isset($line['classification_rule_id']) && $line['classification_rule_id'] !== null ? (int)$line['classification_rule_id'] : null;
        $nextRuleId = isset($next['rule_id']) && $next['rule_id'] ? (int)$next['rule_id'] : null;
        return $currentCategory === trim((string)($next['category'] ?? ''))
            && trim((string)($line['recon_classification'] ?? '')) === trim((string)($next['classification'] ?? ''))
            && trim((string)($line['suggested_dr_ledger'] ?? '')) === trim((string)($next['dr_ledger'] ?? ''))
            && trim((string)($line['suggested_cr_ledger'] ?? '')) === trim((string)($next['cr_ledger'] ?? ''))
            && brAutoInferClassificationOrigin($line) === trim((string)($next['origin'] ?? ''))
            && $currentRuleId === $nextRuleId;
    }
}

if (!function_exists('brAutoReapplyClassifications')) {
    function brAutoReapplyClassifications(mysqli $conn, int $reconId, string $source = 'bank', bool $overrideManual = false): array
    {
        brReconEnsureRuleSchema($conn);
        $source = strtolower(trim($source));
        if (!in_array($source, ['bank', 'ledger'], true)) {
            throw new Exception('source must be bank or ledger.', 422);
        }

        $recon = $conn->query('SELECT id, status FROM bank_recons WHERE id=' . (int)$reconId . ' LIMIT 1')->fetch_assoc();
        if (!$recon) {
            throw new Exception('Reconciliation not found.', 404);
        }
        if (in_array(strtolower(trim((string)($recon['status'] ?? ''))), ['approved', 'locked', 'closed'], true)) {
            throw new Exception('Approved or locked reconciliations cannot be reclassified.', 422);
        }

        $table = $source === 'bank' ? 'bank_recon_bank_lines' : 'bank_recon_ledger_lines';
        $matchedRes = $conn->query("SELECT COUNT(*) total FROM {$table} WHERE recon_id={$reconId} AND match_status='Matched'");
        $matchedSkipped = $matchedRes ? (int)($matchedRes->fetch_assoc()['total'] ?? 0) : 0;
        $res = $conn->query("SELECT * FROM {$table} WHERE recon_id={$reconId} AND match_status<>'Matched' ORDER BY txn_date, id");
        if (!$res) {
            throw new Exception('Failed to load reconciliation lines for rule application: ' . $conn->error, 500);
        }

        $stats = [
            'source' => $source,
            'evaluated' => 0,
            'newly_categorized' => 0,
            'reclassified' => 0,
            'unchanged' => 0,
            'cleared' => 0,
            'uncategorized' => 0,
            'manual_protected' => 0,
            'matched_skipped' => $matchedSkipped,
            'rule_applied' => 0,
            'learned_applied' => 0,
            'default_applied' => 0,
        ];

        if ($source === 'bank') {
            $setStmt = $conn->prepare("UPDATE bank_recon_bank_lines SET
                match_status='Classified', bank_only_type=?, category_name=?, recon_classification=?,
                suggested_dr_ledger=?, suggested_cr_ledger=?, journal_note=?, classification_origin=?,
                classification_rule_id=?, classification_locked=0
                WHERE id=? AND recon_id=? AND match_status<>'Matched'");
            $clearStmt = $conn->prepare("UPDATE bank_recon_bank_lines SET
                match_status='Unmatched', bank_only_type=NULL, category_name=NULL, recon_classification=NULL,
                suggested_dr_ledger=NULL, suggested_cr_ledger=NULL, journal_note=NULL,
                classification_origin=NULL, classification_rule_id=NULL, classification_locked=0
                WHERE id=? AND recon_id=? AND match_status<>'Matched'");
        } else {
            $setStmt = $conn->prepare("UPDATE bank_recon_ledger_lines SET
                match_status='Classified', category_name=?, recon_classification=?,
                suggested_dr_ledger=?, suggested_cr_ledger=?, journal_note=?, classification_origin=?,
                classification_rule_id=?, classification_locked=0
                WHERE id=? AND recon_id=? AND match_status<>'Matched'");
            $clearStmt = $conn->prepare("UPDATE bank_recon_ledger_lines SET
                match_status='Unmatched', category_name=NULL, recon_classification=NULL,
                suggested_dr_ledger=NULL, suggested_cr_ledger=NULL, journal_note=NULL,
                classification_origin=NULL, classification_rule_id=NULL, classification_locked=0
                WHERE id=? AND recon_id=? AND match_status<>'Matched'");
        }

        if (!$setStmt || !$clearStmt) {
            throw new Exception('Failed to prepare rule reclassification: ' . $conn->error, 500);
        }

        $conn->begin_transaction();
        try {
            while ($line = $res->fetch_assoc()) {
                $stats['evaluated']++;
                $hadClassification = brAutoLineHasClassification($line);
                $origin = brAutoInferClassificationOrigin($line);
                $isManual = $hadClassification && ($origin === 'manual' || (int)($line['classification_locked'] ?? 0) === 1);

                if ($isManual && !$overrideManual) {
                    $stats['manual_protected']++;
                    $stats['unchanged']++;
                    continue;
                }

                $next = brAutoCategoryForLine(
                    $source,
                    (string)($line['description'] ?? ''),
                    (string)($line['direction'] ?? ''),
                    $reconId,
                    (string)($line['reference'] ?? '')
                );
                $lineId = (int)$line['id'];

                if (!$next) {
                    if ($hadClassification) {
                        $clearStmt->bind_param('ii', $lineId, $reconId);
                        $clearStmt->execute();
                        $stats['cleared']++;
                    } else {
                        $stats['unchanged']++;
                    }
                    $stats['uncategorized']++;
                    continue;
                }

                $same = $hadClassification && brAutoSameClassification($line, $next);
                $category = trim((string)$next['category']);
                $classification = trim((string)$next['classification']);
                $dr = trim((string)($next['dr_ledger'] ?? ''));
                $cr = trim((string)($next['cr_ledger'] ?? ''));
                $note = trim((string)($next['note'] ?? ''));
                $nextOrigin = trim((string)($next['origin'] ?? 'default'));
                $ruleId = isset($next['rule_id']) && $next['rule_id'] ? (int)$next['rule_id'] : null;

                if ($source === 'bank') {
                    $setStmt->bind_param('sssssssiii', $category, $category, $classification, $dr, $cr, $note, $nextOrigin, $ruleId, $lineId, $reconId);
                } else {
                    $setStmt->bind_param('ssssssiii', $category, $classification, $dr, $cr, $note, $nextOrigin, $ruleId, $lineId, $reconId);
                }
                $setStmt->execute();

                if (!$hadClassification) {
                    $stats['newly_categorized']++;
                } elseif ($same) {
                    $stats['unchanged']++;
                } else {
                    $stats['reclassified']++;
                }

                if (isset($stats[$nextOrigin . '_applied'])) {
                    $stats[$nextOrigin . '_applied']++;
                }
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        } finally {
            $setStmt->close();
            $clearStmt->close();
        }

        return $stats;
    }
}

if (!function_exists('brAutoRecomputeSummary')) {
    function brAutoRecomputeSummary(mysqli $conn, int $reconId): array
    {
        if (function_exists('brReconRecomputeSummary')) {
            return brReconRecomputeSummary($conn, $reconId);
        }

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
