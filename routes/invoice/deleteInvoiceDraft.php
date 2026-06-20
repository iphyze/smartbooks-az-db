<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';

if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? ''), ['POST', 'DELETE'], true)) {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);

$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];
$draftUuid = trim((string) ($data['draft_uuid'] ?? $_GET['draft_uuid'] ?? ''));
if ($draftUuid === '') {
    throw new RuntimeException('Draft identifier is required.', 400);
}

$userId = (int) $user['id'];
$stmt = $conn->prepare('DELETE FROM invoice_drafts WHERE draft_uuid = ? AND created_by_user_id = ?');
$stmt->bind_param('si', $draftUuid, $userId);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'message' => $deleted > 0 ? 'Invoice draft removed.' : 'Invoice draft was already cleared.',
]);
