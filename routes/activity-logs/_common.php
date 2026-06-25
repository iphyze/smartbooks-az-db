<?php
declare(strict_types=1);

function activityLogModuleExpression(string $alias = 'l'): string
{
    return "CASE
        WHEN NULLIF(TRIM({$alias}.module), '') IS NOT NULL THEN {$alias}.module
        WHEN LOWER({$alias}.action) LIKE '%journal%' OR LOWER({$alias}.action) LIKE '%voucher%' THEN 'Journals'
        WHEN LOWER({$alias}.action) LIKE '%invoice%' OR LOWER({$alias}.action) LIKE '%receipt%' OR LOWER({$alias}.action) LIKE '%payment%' THEN 'Invoices'
        WHEN LOWER({$alias}.action) LIKE '%bank recon%' OR LOWER({$alias}.action) LIKE '%reconciliation%' THEN 'Bank Reconciliation'
        WHEN LOWER({$alias}.action) LIKE '%bank%' THEN 'Banks'
        WHEN LOWER({$alias}.action) LIKE '%ledger%' THEN 'Ledgers'
        WHEN LOWER({$alias}.action) LIKE '%account type%' OR LOWER({$alias}.action) LIKE '%accounting period%' OR LOWER({$alias}.action) LIKE '%lock period%' THEN 'Accounting Controls'
        WHEN LOWER({$alias}.action) LIKE '%client%' THEN 'Clients'
        WHEN LOWER({$alias}.action) LIKE '%project%' THEN 'Projects'
        WHEN LOWER({$alias}.action) LIKE '%staff%' THEN 'Staff'
        WHEN LOWER({$alias}.action) LIKE '%timesheet%' THEN 'Timesheets'
        WHEN LOWER({$alias}.action) LIKE '%currency rate%' OR LOWER({$alias}.action) LIKE '%exchange rate%' THEN 'Exchange Rates'
        WHEN LOWER({$alias}.action) LIKE '%user%' OR LOWER({$alias}.action) LIKE '%role%' OR LOWER({$alias}.action) LIKE '%password%' THEN 'Users & Access'
        WHEN LOWER({$alias}.action) LIKE '%logged in%' OR LOWER({$alias}.action) LIKE '%logged out%' OR LOWER({$alias}.action) LIKE '%login%' THEN 'Authentication'
        ELSE 'General'
    END";
}

function activityLogActionTypeExpression(string $alias = 'l'): string
{
    return "CASE
        WHEN NULLIF(TRIM({$alias}.action_type), '') IS NOT NULL THEN {$alias}.action_type
        WHEN LOWER({$alias}.action) LIKE '%logged in%' THEN 'login'
        WHEN LOWER({$alias}.action) LIKE '%logged out%' THEN 'logout'
        WHEN LOWER({$alias}.action) LIKE '%created%' OR LOWER({$alias}.action) LIKE '%added%' THEN 'create'
        WHEN LOWER({$alias}.action) LIKE '%updated%' OR LOWER({$alias}.action) LIKE '%edited%' OR LOWER({$alias}.action) LIKE '%changed%' THEN 'update'
        WHEN LOWER({$alias}.action) LIKE '%deleted%' OR LOWER({$alias}.action) LIKE '%removed%' THEN 'delete'
        WHEN LOWER({$alias}.action) LIKE '%reversed%' THEN 'reverse'
        WHEN LOWER({$alias}.action) LIKE '%sent%' OR LOWER({$alias}.action) LIKE '%emailed%' THEN 'send'
        WHEN LOWER({$alias}.action) LIKE '%locked%' THEN 'lock'
        WHEN LOWER({$alias}.action) LIKE '%unlocked%' THEN 'unlock'
        ELSE 'activity'
    END";
}

function activityLogControllerScope(string $moduleExpression): string
{
    $allowed = [
        'Journals', 'Invoices', 'Bank Reconciliation', 'Banks', 'Ledgers',
        'Accounting Controls', 'Clients', 'Projects', 'Staff', 'Timesheets',
        'Exchange Rates', 'General'
    ];
    $quoted = implode(', ', array_map(static fn (string $value): string => "'" . addslashes($value) . "'", $allowed));
    return "({$moduleExpression}) IN ({$quoted})";
}

function activityLogFilterSql(array $user, array $input, array &$params, string &$types): string
{
    $moduleExpression = activityLogModuleExpression('l');
    $actionTypeExpression = activityLogActionTypeExpression('l');
    $conditions = ['1=1'];

    if (($user['integrity'] ?? '') === 'Controller') {
        $conditions[] = activityLogControllerScope($moduleExpression);
    }

    $search = trim((string) ($input['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = "(
            l.action LIKE ? OR l.created_by LIKE ? OR l.description LIKE ? OR
            l.entity_id LIKE ? OR l.ip_address LIKE ? OR {$moduleExpression} LIKE ? OR
            {$actionTypeExpression} LIKE ?
        )";
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    $module = trim((string) ($input['module'] ?? ''));
    if ($module !== '' && $module !== 'all') {
        $conditions[] = "{$moduleExpression} = ?";
        $params[] = $module;
        $types .= 's';
    }

    $actionType = trim((string) ($input['action_type'] ?? ''));
    if ($actionType !== '' && $actionType !== 'all') {
        $conditions[] = "{$actionTypeExpression} = ?";
        $params[] = $actionType;
        $types .= 's';
    }

    $userId = (int) ($input['user_id'] ?? 0);
    if ($userId > 0) {
        $conditions[] = 'l.userId = ?';
        $params[] = $userId;
        $types .= 'i';
    }

    $from = trim((string) ($input['date_from'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $conditions[] = 'l.created_at >= ?';
        $params[] = $from . ' 00:00:00';
        $types .= 's';
    }

    $to = trim((string) ($input['date_to'] ?? ''));
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $conditions[] = 'l.created_at <= ?';
        $params[] = $to . ' 23:59:59';
        $types .= 's';
    }

    return implode(' AND ', $conditions);
}

function decodeActivityJson(mixed $value): mixed
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function normaliseActivityRow(array $row): array
{
    foreach (['metadata_json', 'before_json', 'after_json'] as $field) {
        $row[$field] = decodeActivityJson($row[$field] ?? null);
    }
    $row['id'] = (int) ($row['id'] ?? 0);
    $row['userId'] = (int) ($row['userId'] ?? 0);
    return $row;
}
