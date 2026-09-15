<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/authorization.php';
require_once 'utils/cost_center_access_helpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }

    $user = authenticateUser();
    requireAnyPermission(
        $conn,
        $user,
        ['journal.create', 'journal.edit', 'journal.import'],
        'You do not have permission to load journal ledger suggestions.'
    );

    $email = trim((string) ($user['email'] ?? ''));
    $limit = isset($_GET['limit']) ? max(1, min(12, (int) $_GET['limit'])) : 6;
    $usageScope = costCenterReportScopeSql($user, 'main_journal_table.cost_center');
    $balanceScope = costCenterReportScopeSql($user, 'main_journal_table.cost_center');

    $sql = "
        SELECT
            l.ledger_name,
            l.ledger_number,
            l.ledger_class,
            l.ledger_class_code,
            l.ledger_sub_class,
            l.ledger_type,
            usage_data.use_count,
            usage_data.last_used_at,
            COALESCE(balance_data.balance_ngn, 0) AS balance_ngn
        FROM (
            SELECT ledger_number, COUNT(*) AS use_count, MAX(created_at) AS last_used_at
            FROM main_journal_table
            WHERE created_by = ?{$usageScope}
            GROUP BY ledger_number
            ORDER BY last_used_at DESC, use_count DESC
            LIMIT ?
        ) usage_data
        INNER JOIN ledger_table l ON l.ledger_number = usage_data.ledger_number
        LEFT JOIN (
            SELECT ledger_number,
                   SUM(CAST(debit_ngn AS DECIMAL(20, 4))) - SUM(CAST(credit_ngn AS DECIMAL(20, 4))) AS balance_ngn
            FROM main_journal_table
            WHERE 1=1{$balanceScope}
            GROUP BY ledger_number
        ) balance_data ON balance_data.ledger_number = l.ledger_number
        ORDER BY usage_data.last_used_at DESC, usage_data.use_count DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $email, $limit);
    $stmt->execute();
    $suggestions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // New users may have no personal history yet. Fall back to the most frequently used ledgers.
    if ($suggestions === []) {
        $fallbackScope = costCenterReportScopeSql($user, 'm.cost_center');
        $fallbackSql = "
            SELECT
                l.ledger_name,
                l.ledger_number,
                l.ledger_class,
                l.ledger_class_code,
                l.ledger_sub_class,
                l.ledger_type,
                COUNT(m.id) AS use_count,
                MAX(m.created_at) AS last_used_at,
                COALESCE(SUM(CAST(m.debit_ngn AS DECIMAL(20, 4))) - SUM(CAST(m.credit_ngn AS DECIMAL(20, 4))), 0) AS balance_ngn
            FROM main_journal_table m
            INNER JOIN ledger_table l ON l.ledger_number = m.ledger_number
            WHERE 1=1{$fallbackScope}
            GROUP BY l.ledger_name, l.ledger_number, l.ledger_class, l.ledger_class_code, l.ledger_sub_class, l.ledger_type
            ORDER BY use_count DESC, last_used_at DESC
            LIMIT ?
        ";
        $fallback = $conn->prepare($fallbackSql);
        $fallback->bind_param('i', $limit);
        $fallback->execute();
        $suggestions = $fallback->get_result()->fetch_all(MYSQLI_ASSOC);
        $fallback->close();
    }

    echo json_encode([
        'status' => 'Success',
        'data' => $suggestions,
    ]);
} catch (Throwable $error) {
    error_log('Journal ledger suggestions error: ' . $error->getMessage());
    http_response_code($error->getCode() >= 400 ? $error->getCode() : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($error),
    ]);
}
