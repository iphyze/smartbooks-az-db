<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function validateRateEditEffectiveDate(string $value): string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('Enter a valid rate effective date in YYYY-MM-DD format.', 422);
    }
    return $value;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $userEmail = trim((string) ($userData['email'] ?? ''));
    $userIntegrity = (string) ($userData['integrity'] ?? '');
    if (!in_array($userIntegrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can update currency rates.', 403);
    }

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request format. Expected a JSON object.', 400);
    }

    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Select a valid currency-rate record.', 422);
    }
    foreach (['ngn_rate', 'usd_rate', 'gbp_rate', 'eur_rate'] as $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field]) || (float) $data[$field] <= 0) {
            throw new RuntimeException("{$field} must be a number greater than zero.", 422);
        }
    }

    $effectiveDate = validateRateEditEffectiveDate((string) ($data['effective_date'] ?? $data['created_at'] ?? ''));
    $rateSource = trim((string) ($data['rate_source'] ?? 'Manual entry')) ?: 'Manual entry';
    $sourceReference = trim((string) ($data['source_reference'] ?? ''));
    if (mb_strlen($rateSource) > 255) {
        throw new RuntimeException('Rate source cannot exceed 255 characters.', 422);
    }
    if (mb_strlen($sourceReference) > 500) {
        throw new RuntimeException('Source reference cannot exceed 500 characters.', 422);
    }

    $ngnRate = (float) $data['ngn_rate'];
    $usdRate = (float) $data['usd_rate'];
    $gbpRate = (float) $data['gbp_rate'];
    $eurRate = (float) $data['eur_rate'];
    $sourceReferenceOrNull = $sourceReference !== '' ? $sourceReference : null;
    $ngnCur = 'NGN';
    $usdCur = 'USD';
    $gbpCur = 'GBP';
    $eurCur = 'EUR';

    $conn->begin_transaction();

    $lockStmt = $conn->prepare('SELECT id FROM currency_table WHERE id = ? FOR UPDATE');
    if (!$lockStmt) {
        throw new RuntimeException('Unable to load the rate record. Apply the historical closing-rate migration first.', 503);
    }
    $lockStmt->bind_param('i', $id);
    $lockStmt->execute();
    $exists = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if (!$exists) {
        throw new RuntimeException("Currency-rate record {$id} was not found.", 404);
    }

    $usedStmt = $conn->prepare(
        "SELECT journal_id, date_to
         FROM fx_revaluation_batches
         WHERE closing_rate_id = ?
         LIMIT 1"
    );
    if (!$usedStmt) {
        throw new RuntimeException('Unable to verify whether this rate is already in use. Apply the historical closing-rate migration first.', 503);
    }
    $usedStmt->bind_param('i', $id);
    $usedStmt->execute();
    $usedBy = $usedStmt->get_result()->fetch_assoc();
    $usedStmt->close();
    if ($usedBy) {
        throw new RuntimeException(
            "This rate is preserved by posted FX journal {$usedBy['journal_id']} for {$usedBy['date_to']} and cannot be edited. Reverse that revaluation or create a new rate record.",
            409
        );
    }

    $updateStmt = $conn->prepare(
        'UPDATE currency_table
         SET effective_date = ?, created_at = ?,
             ngn_cur = ?, ngn_rate = ?, usd_cur = ?, usd_rate = ?,
             gbp_cur = ?, gbp_rate = ?, eur_cur = ?, eur_rate = ?,
             rate_source = ?, source_reference = ?, updated_at = NOW(), updated_by = ?
         WHERE id = ?'
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to update the rate record. Apply the historical closing-rate migration first.', 503);
    }
    $updateStmt->bind_param(
        'sssdsdsdsdsssi',
        $effectiveDate,
        $effectiveDate,
        $ngnCur,
        $ngnRate,
        $usdCur,
        $usdRate,
        $gbpCur,
        $gbpRate,
        $eurCur,
        $eurRate,
        $rateSource,
        $sourceReferenceOrNull,
        $userEmail,
        $id
    );
    $updateStmt->execute();
    $updateStmt->close();

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if (!$logStmt) {
        throw new RuntimeException('Unable to write the rate audit log.', 500);
    }
    $action = "{$userEmail} updated currency rates [ID {$id}; effective {$effectiveDate}; source: {$rateSource}]";
    $logStmt->bind_param('iss', $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    $fetchStmt = $conn->prepare(
        'SELECT id, effective_date, ngn_cur, ngn_rate, usd_cur, usd_rate,
                gbp_cur, gbp_rate, eur_cur, eur_rate, rate_source, source_reference,
                recorded_at, recorded_by, created_at, created_by, updated_at, updated_by
         FROM currency_table WHERE id = ?'
    );
    $fetchStmt->bind_param('i', $id);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    $conn->commit();
    echo json_encode([
        'status' => 'Success',
        'message' => 'Currency rates updated successfully.',
        'data' => $updatedData,
    ], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('Edit Rate Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($error)]);
}
