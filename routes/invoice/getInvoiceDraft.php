<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);

$userId = (int) $user['id'];
$draftUuid = trim((string) ($_GET['draft_uuid'] ?? ''));
$draftKey = trim((string) ($_GET['draft_key'] ?? ''));
$mode = strtolower(trim((string) ($_GET['mode'] ?? '')));
$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));

if ($draftUuid !== '') {
    $stmt = $conn->prepare(
        'SELECT id, draft_uuid, draft_key, mode, invoice_number, payload, last_saved_at, created_at, updated_at
         FROM invoice_drafts
         WHERE draft_uuid = ? AND created_by_user_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('si', $draftUuid, $userId);
} else {
    if ($draftKey === '') {
        $draftKey = $mode === 'edit' && $invoiceNumber !== '' ? "edit:{$invoiceNumber}" : 'create';
    }

    $stmt = $conn->prepare(
        'SELECT id, draft_uuid, draft_key, mode, invoice_number, payload, last_saved_at, created_at, updated_at
         FROM invoice_drafts
         WHERE created_by_user_id = ? AND draft_key = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $userId, $draftKey);
}

$stmt->execute();
$draft = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$draft) {
    jsonResponse([
        'status' => 'Success',
        'message' => 'No saved invoice draft was found.',
        'data' => null,
    ]);
}

$draft['payload'] = decodeInvoiceDraftPayload((string) $draft['payload']);

jsonResponse([
    'status' => 'Success',
    'message' => 'Invoice draft fetched.',
    'data' => $draft,
]);
