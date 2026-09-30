<?php

declare(strict_types=1);

/**
 * Loads a bank reconciliation by its primary key.
 *
 * Authorization is intentionally NOT handled here. Bank reconciliation access is
 * controlled by the bank_reconciliation.* RBAC permissions enforced by the route
 * authorization layer and the individual endpoints.
 */
if (!function_exists('requireBankReconRecord')) {
    function requireBankReconRecord(mysqli $conn, int $reconId, bool $forUpdate = false): array
    {
        if ($reconId <= 0) {
            throw new RuntimeException('Reconciliation not found.', 404);
        }

        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $conn->prepare("SELECT * FROM bank_recons WHERE id = ? LIMIT 1{$suffix}");
        if (!$stmt) {
            throw new RuntimeException('Unable to load reconciliation.', 500);
        }

        $stmt->bind_param('i', $reconId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Reconciliation not found.', 404);
        }

        return $row;
    }
}
