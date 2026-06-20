<?php
declare(strict_types=1);

function normalizeInvoicePercentage(mixed $value): float
{
    $number = round((float) $value, 4);
    if ($number < 0 || $number > 100) {
        throw new RuntimeException('Percentage values must be between 0 and 100.', 422);
    }
    return $number;
}

function normalizePaymentTermsDays(mixed $value): ?int
{
    if ($value === null || $value === '' || strtolower((string) $value) === 'custom') {
        return null;
    }

    $days = (int) $value;
    if ($days < 0 || $days > 365) {
        throw new RuntimeException('Payment terms must be between 0 and 365 days.', 422);
    }
    return $days;
}

function paymentTermsLabel(?int $days, ?string $requestedLabel = null): string
{
    $requestedLabel = trim((string) $requestedLabel);
    if ($requestedLabel !== '') {
        return mb_substr($requestedLabel, 0, 60);
    }
    if ($days === null) {
        return 'Custom due date';
    }
    if ($days === 0) {
        return 'Due on receipt';
    }
    return "Net {$days} days";
}

function generateInvoiceServiceCode(mysqli $conn): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = 'SVC-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $conn->prepare('SELECT id FROM invoice_service_catalogue WHERE service_code = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to generate a service code.', 500);
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $code;
        }
    }
    throw new RuntimeException('Unable to generate a unique service code.', 500);
}

function fetchInvoiceServiceById(mysqli $conn, int $serviceId, bool $activeOnly = true): ?array
{
    if ($serviceId <= 0) {
        return null;
    }

    $sql = 'SELECT id, service_code, service_name, description, currency, default_amount,
                   discount_percent, vat_percent, wht_percent, is_active,
                   created_at, created_by, updated_at, updated_by
            FROM invoice_service_catalogue
            WHERE id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load the service catalogue item.', 500);
    }
    $stmt->bind_param('i', $serviceId);
    $stmt->execute();
    $service = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $service;
}

function fetchClientInvoicePreferences(mysqli $conn, int $clientId): ?array
{
    if ($clientId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT
            p.id,
            p.client_id,
            p.default_currency,
            p.payment_terms_days,
            p.default_bank_id,
            p.display_tin,
            p.post_journal_entry,
            p.default_project,
            p.default_discount_percent,
            p.default_vat_percent,
            p.default_wht_percent,
            p.created_at,
            p.created_by,
            p.updated_at,
            p.updated_by,
            b.bank_name,
            b.account_name,
            b.account_number,
            b.account_currency
         FROM client_invoice_preferences p
         LEFT JOIN bank_table b ON b.id = p.default_bank_id
         WHERE p.client_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load client invoice preferences.', 500);
    }
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $preferences = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($preferences) {
        $preferences['payment_terms_days'] = $preferences['payment_terms_days'] === null
            ? null
            : (int) $preferences['payment_terms_days'];
        $preferences['default_bank_id'] = $preferences['default_bank_id'] === null
            ? null
            : (int) $preferences['default_bank_id'];
        $preferences['default_discount_percent'] = (float) $preferences['default_discount_percent'];
        $preferences['default_vat_percent'] = (float) $preferences['default_vat_percent'];
        $preferences['default_wht_percent'] = (float) $preferences['default_wht_percent'];
    }

    return $preferences;
}

function saveClientInvoicePreferences(
    mysqli $conn,
    int $clientId,
    array $preferences,
    string $userEmail
): void {
    if ($clientId <= 0) {
        throw new RuntimeException('A valid client is required before saving invoice defaults.', 422);
    }

    $clientStmt = $conn->prepare('SELECT clients_id FROM clients_table WHERE clients_id = ? LIMIT 1');
    if (!$clientStmt) {
        throw new RuntimeException('Unable to validate the selected client.', 500);
    }
    $clientStmt->bind_param('i', $clientId);
    $clientStmt->execute();
    $clientExists = (bool) $clientStmt->get_result()->fetch_assoc();
    $clientStmt->close();
    if (!$clientExists) {
        throw new RuntimeException('The selected client could not be found.', 404);
    }

    $currency = strtoupper(trim((string) ($preferences['default_currency'] ?? 'NGN')));
    if (!in_array($currency, ['NGN', 'USD', 'GBP', 'EUR'], true)) {
        throw new RuntimeException('Select a valid default invoice currency.', 422);
    }

    $termsDays = normalizePaymentTermsDays($preferences['payment_terms_days'] ?? 0);
    $bankId = isset($preferences['default_bank_id']) && $preferences['default_bank_id'] !== ''
        ? (int) $preferences['default_bank_id']
        : null;
    $displayTin = trim((string) ($preferences['display_tin'] ?? 'No')) === 'Yes' ? 'Yes' : 'No';
    $postJournal = trim((string) ($preferences['post_journal_entry'] ?? 'No')) === 'Yes' ? 'Yes' : 'No';
    $project = trim((string) ($preferences['default_project'] ?? ''));
    $discount = normalizeInvoicePercentage($preferences['default_discount_percent'] ?? 0);
    $vat = normalizeInvoicePercentage($preferences['default_vat_percent'] ?? 0);
    $wht = normalizeInvoicePercentage($preferences['default_wht_percent'] ?? 0);

    if ($bankId !== null && $bankId > 0) {
        $bankStmt = $conn->prepare('SELECT id, account_currency FROM bank_table WHERE id = ? LIMIT 1');
        if (!$bankStmt) {
            throw new RuntimeException('Unable to validate the default bank account.', 500);
        }
        $bankStmt->bind_param('i', $bankId);
        $bankStmt->execute();
        $bank = $bankStmt->get_result()->fetch_assoc();
        $bankStmt->close();
        if (!$bank) {
            throw new RuntimeException('The selected default bank account could not be found.', 422);
        }
        if (strtoupper((string) $bank['account_currency']) !== $currency) {
            throw new RuntimeException("The default bank account must use {$currency}.", 422);
        }
    } else {
        $bankId = null;
    }

    $stmt = $conn->prepare(
        'INSERT INTO client_invoice_preferences
            (client_id, default_currency, payment_terms_days, default_bank_id,
             display_tin, post_journal_entry, default_project,
             default_discount_percent, default_vat_percent, default_wht_percent,
             created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            default_currency = VALUES(default_currency),
            payment_terms_days = VALUES(payment_terms_days),
            default_bank_id = VALUES(default_bank_id),
            display_tin = VALUES(display_tin),
            post_journal_entry = VALUES(post_journal_entry),
            default_project = VALUES(default_project),
            default_discount_percent = VALUES(default_discount_percent),
            default_vat_percent = VALUES(default_vat_percent),
            default_wht_percent = VALUES(default_wht_percent),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to save client invoice preferences.', 500);
    }

    $stmt->bind_param(
        'isiisssdddss',
        $clientId,
        $currency,
        $termsDays,
        $bankId,
        $displayTin,
        $postJournal,
        $project,
        $discount,
        $vat,
        $wht,
        $userEmail,
        $userEmail
    );
    $stmt->execute();
    $stmt->close();
}
