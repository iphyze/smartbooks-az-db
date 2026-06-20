<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json');

function brFail($message, $code = 400) {
    throw new Exception($message, $code);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        brFail('Route not found', 404);
    }

    $user = requireAdmin();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim((string) ($_GET['search'] ?? ''));
    $yearRaw = trim((string) ($_GET['year'] ?? ''));
    $year = preg_match('/^\d{4}$/', $yearRaw) ? (int) $yearRaw : null;

    $whereParts = ['1=1'];
    $types = '';
    $params = [];

    if ($year !== null) {
        $whereParts[] = 'YEAR(period_to) = ?';
        $types .= 'i';
        $params[] = $year;
    }

    if ($search !== '') {
        $whereParts[] = '(recon_number LIKE ? OR company_name LIKE ? OR bank_name LIKE ? OR account_name LIKE ? OR account_number LIKE ? OR status LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $where = implode(' AND ', $whereParts);

    $countSql = "SELECT COUNT(*) AS total FROM bank_recons WHERE {$where}";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        brFail('Failed to prepare count query: ' . $conn->error, 500);
    }
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "
        SELECT
            id,
            recon_number,
            company_name,
            bank_name,
            account_name,
            account_number,
            currency,
            period_from,
            period_to,
            bank_opening,
            bank_closing,
            ledger_opening,
            ledger_closing,
            adjusted_bank_balance,
            adjusted_ledger_balance,
            unreconciled_difference,
            tolerance_days,
            tolerance_amount,
            status,
            bank_file_name,
            ledger_file_name,
            notes,
            created_by,
            updated_by,
            created_at,
            updated_at
        FROM bank_recons
        WHERE {$where}
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        brFail('Failed to prepare list query: ' . $conn->error, 500);
    }

    $bindTypes = $types . 'ii';
    $bindParams = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'status' => 'Success',
        'message' => 'Bank reconciliations fetched successfully',
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / $limit),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
