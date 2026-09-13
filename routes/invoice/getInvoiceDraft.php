<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/cost_center_access_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireAnyPermission($conn, $user, ['invoice.create', 'invoice.edit'], 'You do not have permission to manage invoice drafts.');

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
$draftInvoiceNumber = trim((string) ($draft['invoice_number'] ?? ''));
if ($draftInvoiceNumber !== '') {
    requireInvoiceCostCenterAccess($conn, $user, $draftInvoiceNumber, false);
}
$draftCostCenter = trim((string) ($draft['payload']['invoiceDetails']['cost_center'] ?? ''));
if ($draftCostCenter !== '' && !userHasAllCostCenterAccess($user)) {
    requireCostCenterAccess($conn, $user, $draftCostCenter, 'Invoice draft not found.');
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Invoice draft fetched.',
    'data' => $draft,
]);
