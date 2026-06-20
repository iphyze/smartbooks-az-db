<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconAutoClassification.php';

header('Content-Type: application/json');

function brRulesFail(string $message, int $code = 400): void { throw new Exception($message, $code); }

function brRulesBody(): array
{
    $raw = json_decode(file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function brRulesBool($value): int
{
    if (is_bool($value)) return $value ? 1 : 0;
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1','true','yes','y','on'], true) ? 1 : 0;
}

function brRulesCleanOption(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function brRulesList(mysqli $conn): array
{
    brReconEnsureRuleSchema($conn);
    $res = $conn->query('SELECT * FROM bank_recon_auto_rules ORDER BY is_active DESC, priority ASC, id DESC');
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

try {
    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';
    brReconEnsureRuleSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['status' => 'Success', 'data' => brRulesList($conn)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brRulesFail('Route not found', 404);

    $body = brRulesBody();
    $action = strtolower(trim((string)($body['action'] ?? 'save')));

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) brRulesFail('Rule id is required.');
        $stmt = $conn->prepare('DELETE FROM bank_recon_auto_rules WHERE id=?');
        if (!$stmt) brRulesFail('Failed to prepare delete: ' . $conn->error, 500);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'Success', 'message' => 'Auto-categorisation rule deleted.', 'data' => brRulesList($conn)]);
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) brRulesFail('Rule id is required.');
        $isActive = brRulesBool($body['is_active'] ?? 1);
        $stmt = $conn->prepare('UPDATE bank_recon_auto_rules SET is_active=?, updated_by=? WHERE id=?');
        if (!$stmt) brRulesFail('Failed to prepare toggle: ' . $conn->error, 500);
        $stmt->bind_param('isi', $isActive, $by, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'Success', 'message' => 'Rule status updated.', 'data' => brRulesList($conn)]);
        exit;
    }

    if ($action === 'apply') {
        $reconId = (int)($body['recon_id'] ?? 0);
        $source = strtolower(trim((string)($body['source'] ?? 'bank')));
        $overrideManual = brRulesBool($body['override_manual'] ?? 0) === 1;
        if (!$reconId) brRulesFail('recon_id is required to apply rules.');
        if (!in_array($source, ['bank','ledger','both'], true)) $source = 'bank';

        $emptyStats = [
            'evaluated' => 0,
            'newly_categorized' => 0,
            'reclassified' => 0,
            'unchanged' => 0,
            'cleared' => 0,
            'uncategorized' => 0,
            'manual_protected' => 0,
            'matched_skipped' => 0,
            'rule_applied' => 0,
            'learned_applied' => 0,
            'default_applied' => 0,
        ];
        $stats = $emptyStats;
        $breakdown = [];
        $sources = $source === 'both' ? ['bank', 'ledger'] : [$source];

        foreach ($sources as $side) {
            $sideStats = brAutoReapplyClassifications($conn, $reconId, $side, $overrideManual);
            $breakdown[$side] = $sideStats;
            foreach ($emptyStats as $key => $value) {
                $stats[$key] += (int)($sideStats[$key] ?? 0);
            }
        }

        $summary = function_exists('brReconRecomputeSummary') ? brReconRecomputeSummary($conn, $reconId) : brAutoRecomputeSummary($conn, $reconId);
        $message = sprintf(
            'Rules re-applied: %d newly categorised, %d reclassified, %d unchanged, %d uncategorised and %d manual classification%s protected.',
            $stats['newly_categorized'],
            $stats['reclassified'],
            $stats['unchanged'],
            $stats['uncategorized'],
            $stats['manual_protected'],
            $stats['manual_protected'] === 1 ? '' : 's'
        );

        echo json_encode([
            'status' => 'Success',
            'message' => $message,
            'data' => [
                'stats' => $stats,
                'breakdown' => $breakdown,
                'override_manual' => $overrideManual,
                'summary' => $summary,
            ],
        ]);
        exit;
    }

    $id = (int)($body['id'] ?? 0);
    $ruleName = trim((string)($body['rule_name'] ?? ''));
    $source = brRulesCleanOption((string)($body['source'] ?? 'bank'), ['bank','ledger','both'], 'bank');
    $matchField = brRulesCleanOption((string)($body['match_field'] ?? 'description'), ['description','reference','description_reference'], 'description');
    $matchType = brRulesCleanOption((string)($body['match_type'] ?? 'contains'), ['contains','exact','regex'], 'contains');
    $keywords = trim((string)($body['keywords'] ?? ''));
    $direction = strtoupper(trim((string)($body['direction'] ?? 'ANY')));
    if (!in_array($direction, ['ANY','OUT','IN'], true)) $direction = 'ANY';
    $category = trim((string)($body['category_name'] ?? $body['category'] ?? ''));
    $classification = trim((string)($body['recon_classification'] ?? $body['classification'] ?? ''));
    $dr = trim((string)($body['suggested_dr_ledger'] ?? $body['dr_ledger'] ?? ''));
    $cr = trim((string)($body['suggested_cr_ledger'] ?? $body['cr_ledger'] ?? ''));
    $priority = (int)($body['priority'] ?? 100);
    $isActive = brRulesBool($body['is_active'] ?? 1);

    if ($ruleName === '') brRulesFail('Rule name is required.');
    if ($keywords === '') brRulesFail('Keyword, phrase or regex is required.');
    if ($category === '') brRulesFail('Category is required.');
    if ($classification === '') brRulesFail('Reconciliation classification is required.');

    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE bank_recon_auto_rules
            SET rule_name=?, source=?, match_field=?, match_type=?, keywords=?, direction=?, category_name=?, recon_classification=?, suggested_dr_ledger=?, suggested_cr_ledger=?, priority=?, is_active=?, updated_by=?
            WHERE id=?');
        if (!$stmt) brRulesFail('Failed to prepare rule update: ' . $conn->error, 500);
        $stmt->bind_param('ssssssssssiisi', $ruleName, $source, $matchField, $matchType, $keywords, $direction, $category, $classification, $dr, $cr, $priority, $isActive, $by, $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Auto-categorisation rule updated.';
    } else {
        $stmt = $conn->prepare('INSERT INTO bank_recon_auto_rules
            (rule_name, source, match_field, match_type, keywords, direction, category_name, recon_classification, suggested_dr_ledger, suggested_cr_ledger, priority, is_active, created_by, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$stmt) brRulesFail('Failed to prepare rule insert: ' . $conn->error, 500);
        $stmt->bind_param('ssssssssssiiss', $ruleName, $source, $matchField, $matchType, $keywords, $direction, $category, $classification, $dr, $cr, $priority, $isActive, $by, $by);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        $message = 'Auto-categorisation rule created.';
    }

    echo json_encode(['status' => 'Success', 'message' => $message, 'data' => brRulesList($conn), 'id' => $id]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
