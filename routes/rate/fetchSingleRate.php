<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $integrity = (string) ($userData['integrity'] ?? '');
    if (!in_array($integrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can access currency rates.', 403);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException("Missing or invalid parameter 'id'.", 422);
    }

    $stmt = $conn->prepare(
        'SELECT rate.id, rate.effective_date, rate.ngn_cur, rate.ngn_rate,
                rate.usd_cur, rate.usd_rate, rate.gbp_cur, rate.gbp_rate,
                rate.eur_cur, rate.eur_rate, rate.rate_source, rate.source_reference,
                rate.recorded_at, rate.recorded_by, rate.created_at, rate.created_by,
                rate.updated_at, rate.updated_by,
                EXISTS(
                    SELECT 1 FROM fx_revaluation_batches batch
                    WHERE batch.closing_rate_id = rate.id
                ) AS is_used_by_posted_revaluation
         FROM currency_table rate
         WHERE rate.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load the rate. Apply the historical closing-rate migration first.', 503);
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$data) {
        throw new RuntimeException("Rate {$id} was not found.", 404);
    }

    $data['is_used_by_posted_revaluation'] = (bool) $data['is_used_by_posted_revaluation'];
    http_response_code(200);
    echo json_encode(['status' => 'Success', 'data' => $data], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    error_log('Fetch Single Rate Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => publicErrorMessage($error)]);
}
