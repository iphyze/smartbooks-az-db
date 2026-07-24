<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function validateRateEffectiveDate(string $value): string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('Enter a valid rate effective date in YYYY-MM-DD format.', 422);
    }
    return $value;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $userEmail = trim((string) ($userData['email'] ?? ''));
    $userIntegrity = (string) ($userData['integrity'] ?? '');

    if (!in_array($userIntegrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can create currency rates.', 403);
    }

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request format. Expected a JSON object.', 400);
    }

    foreach (['ngn_rate', 'usd_rate', 'gbp_rate', 'eur_rate'] as $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field]) || (float) $data[$field] <= 0) {
            throw new RuntimeException("{$field} must be a number greater than zero.", 422);
        }
    }

    $effectiveDate = validateRateEffectiveDate((string) ($data['effective_date'] ?? $data['created_at'] ?? ''));
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
    $ngnCur = 'NGN';
    $usdCur = 'USD';
    $gbpCur = 'GBP';
    $eurCur = 'EUR';
    $sourceReferenceOrNull = $sourceReference !== '' ? $sourceReference : null;

    $conn->begin_transaction();

    $insertStmt = $conn->prepare(
        'INSERT INTO currency_table
            (effective_date, ngn_cur, ngn_rate, usd_cur, usd_rate, gbp_cur, gbp_rate,
             eur_cur, eur_rate, rate_source, source_reference, recorded_at, recorded_by,
             created_at, created_by, updated_at, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), ?)'
    );
    if (!$insertStmt) {
        throw new RuntimeException('Unable to create the rate record. Apply the historical closing-rate migration first.', 503);
    }
    $insertStmt->bind_param(
        'ssdsdsdsdssssss',
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
        $effectiveDate,
        $userEmail,
        $userEmail
    );
    $insertStmt->execute();
    $insertedId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if (!$logStmt) {
        throw new RuntimeException('Unable to write the rate audit log.', 500);
    }
    $logAction = "{$userEmail} recorded currency rates effective {$effectiveDate} [ID: {$insertedId}; source: {$rateSource}]";
    $logStmt->bind_param('iss', $loggedInUserId, $logAction, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    $conn->commit();

    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Currency rates recorded successfully.',
        'data' => [
            'id' => $insertedId,
            'effective_date' => $effectiveDate,
            'created_at' => $effectiveDate,
            'ngn_cur' => $ngnCur,
            'ngn_rate' => $ngnRate,
            'usd_cur' => $usdCur,
            'usd_rate' => $usdRate,
            'gbp_cur' => $gbpCur,
            'gbp_rate' => $gbpRate,
            'eur_cur' => $eurCur,
            'eur_rate' => $eurRate,
            'rate_source' => $rateSource,
            'source_reference' => $sourceReferenceOrNull,
            'recorded_by' => $userEmail,
        ],
    ], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('Create Rate Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($error)]);
}
