<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    throw new RuntimeException('Invalid request body.', 400);
}

$mode = strtolower(trim((string) ($data['mode'] ?? 'create')));
if (!in_array($mode, ['create', 'edit'], true)) {
    throw new RuntimeException('Invalid draft mode.', 400);
}

$invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
if ($mode === 'edit' && $invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required for an edit draft.', 400);
}

$payload = $data['payload'] ?? null;
if (!is_array($payload)) {
    throw new RuntimeException('Draft payload must be an object.', 400);
}

$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($payloadJson === false) {
    throw new RuntimeException('Unable to prepare the draft.', 400);
}
if (strlen($payloadJson) > 2_000_000) {
    throw new RuntimeException('The invoice draft is too large.', 413);
}

$userId = (int) $user['id'];
$userEmail = (string) $user['email'];
$draftUuid = trim((string) ($data['draft_uuid'] ?? ''));
$requestedDraftKey = trim((string) ($data['draft_key'] ?? ''));
$defaultDraftKey = $mode === 'edit' ? "edit:{$invoiceNumber}" : 'create';
$draftKey = $requestedDraftKey !== '' ? $requestedDraftKey : $defaultDraftKey;
$draftKey = preg_replace('/[^a-zA-Z0-9:_-]/', '', $draftKey) ?: $defaultDraftKey;
$draftKey = substr($draftKey, 0, 120);

$conn->begin_transaction();
try {
    if ($draftUuid !== '') {
        $checkStmt = $conn->prepare(
            'SELECT id, draft_uuid
             FROM invoice_drafts
             WHERE draft_uuid = ? AND created_by_user_id = ?
             LIMIT 1'
        );
        $checkStmt->bind_param('si', $draftUuid, $userId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$existing) {
            throw new RuntimeException('The invoice draft could not be found.', 404);
        }

        $updateStmt = $conn->prepare(
            'UPDATE invoice_drafts
             SET draft_key = ?, mode = ?, invoice_number = NULLIF(?, \'\'), payload = ?,
                 created_by_email = ?, last_saved_at = CURRENT_TIMESTAMP
             WHERE draft_uuid = ? AND created_by_user_id = ?'
        );
        $updateStmt->bind_param(
            'ssssssi',
            $draftKey,
            $mode,
            $invoiceNumber,
            $payloadJson,
            $userEmail,
            $draftUuid,
            $userId
        );
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        $draftUuid = generateUuidV4();
        $upsertStmt = $conn->prepare(
            'INSERT INTO invoice_drafts
                (draft_uuid, draft_key, mode, invoice_number, payload, created_by_user_id, created_by_email, last_saved_at)
             VALUES (?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                draft_uuid = VALUES(draft_uuid),
                mode = VALUES(mode),
                invoice_number = VALUES(invoice_number),
                payload = VALUES(payload),
                created_by_email = VALUES(created_by_email),
                last_saved_at = CURRENT_TIMESTAMP'
        );
        $upsertStmt->bind_param(
            'sssssis',
            $draftUuid,
            $draftKey,
            $mode,
            $invoiceNumber,
            $payloadJson,
            $userId,
            $userEmail
        );
        $upsertStmt->execute();
        $upsertStmt->close();

        $lookupStmt = $conn->prepare(
            'SELECT draft_uuid FROM invoice_drafts WHERE created_by_user_id = ? AND draft_key = ? LIMIT 1'
        );
        $lookupStmt->bind_param('is', $userId, $draftKey);
        $lookupStmt->execute();
        $saved = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();
        $draftUuid = (string) ($saved['draft_uuid'] ?? $draftUuid);
    }

    $conn->commit();

    jsonResponse([
        'status' => 'Success',
        'message' => 'Invoice draft saved.',
        'data' => [
            'draft_uuid' => $draftUuid,
            'draft_key' => $draftKey,
            'mode' => $mode,
            'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            'last_saved_at' => date('Y-m-d H:i:s'),
        ],
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}
