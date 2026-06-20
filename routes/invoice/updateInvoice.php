<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole(
    $user,
    [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER],
    'Only Admin or Controller users can manage invoices.'
);

throw new RuntimeException(
    'Payment status is calculated from recorded invoice payments. Open an invoice and use Record Payment or reverse an existing receipt instead.',
    409
);
