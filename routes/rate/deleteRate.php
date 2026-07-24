<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $integrity = (string) ($userData['integrity'] ?? '');
    $userEmail = trim((string) ($userData['email'] ?? ''));
    if (!in_array($integrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can delete currency rates.', 403);
    }

    $data = json_decode((string) file_get_contents('php://input'), true);
    $rateIds = isset($data['rateIds']) && is_array($data['rateIds'])
        ? array_values(array_unique(array_filter(array_map('intval', $data['rateIds']), fn ($id) => $id > 0)))
        : [];
    if (!$rateIds) {
        throw new RuntimeException('Select at least one currency-rate record to delete.', 422);
    }

    $placeholders = implode(',', array_fill(0, count($rateIds), '?'));
    $types = str_repeat('i', count($rateIds));

    $conn->begin_transaction();

    $usedStmt = $conn->prepare(
        "SELECT closing_rate_id, journal_id, date_to
         FROM fx_revaluation_batches
         WHERE closing_rate_id IN ({$placeholders})
         LIMIT 1"
    );
    if (!$usedStmt) {
        throw new RuntimeException('Unable to verify whether the rate is in use. Apply the historical closing-rate migration first.', 503);
    }
    $usedStmt->bind_param($types, ...$rateIds);
    $usedStmt->execute();
    $used = $usedStmt->get_result()->fetch_assoc();
    $usedStmt->close();
    if ($used) {
        throw new RuntimeException(
            "Rate {$used['closing_rate_id']} is preserved by FX journal {$used['journal_id']} for {$used['date_to']} and cannot be deleted.",
            409
        );
    }

    $deleteStmt = $conn->prepare("DELETE FROM currency_table WHERE id IN ({$placeholders})");
    if (!$deleteStmt) {
        throw new RuntimeException('Unable to delete the selected rate records.', 500);
    }
    $deleteStmt->bind_param($types, ...$rateIds);
    $deleteStmt->execute();
    $affected = $deleteStmt->affected_rows;
    $deleteStmt->close();
    if ($affected === 0) {
        throw new RuntimeException('No matching currency-rate records were found.', 404);
    }

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if (!$logStmt) {
        throw new RuntimeException('Unable to write the rate audit log.', 500);
    }
    $action = "{$userEmail} deleted currency-rate record(s): " . implode(', ', $rateIds);
    $logStmt->bind_param('iss', $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    $conn->commit();
    http_response_code(200);
    echo json_encode(['status' => 'Success', 'message' => 'Currency-rate record(s) deleted successfully.']);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('Delete Rate Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($error)]);
}
