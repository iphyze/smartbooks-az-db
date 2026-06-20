<?php
declare(strict_types=1);

/**
 * Return a complete invoice bundle used by view, duplication and email routes.
 */
function fetchInvoiceBundle(mysqli $conn, string $invoiceNumber): array
{
    $headerStmt = $conn->prepare(
        'SELECT
            id,
            invoice_number,
            invoice_date,
            due_date,
            payment_terms_days,
            payment_terms_label,
            clients_name,
            clients_id,
            project,
            invoice_amount,
            currency,
            status,
            workflow_status,
            issued_at,
            last_sent_at,
            sent_count,
            bank_name,
            account_name,
            account_number,
            account_currency,
            tin_number,
            paid,
            rate_date,
            created_at,
            created_by,
            updated_at,
            updated_by
         FROM invoice_table
         WHERE invoice_number = ?
         LIMIT 1'
    );
    if (!$headerStmt) {
        throw new RuntimeException('Unable to load the invoice.', 500);
    }

    $headerStmt->bind_param('s', $invoiceNumber);
    $headerStmt->execute();
    $invoice = $headerStmt->get_result()->fetch_assoc();
    $headerStmt->close();

    if (!$invoice) {
        throw new RuntimeException("Invoice #{$invoiceNumber} was not found.", 404);
    }

    $itemsStmt = $conn->prepare(
        'SELECT
            id,
            invoice_number,
            service_catalogue_id,
            clients_name,
            clients_id,
            description,
            amount,
            discount,
            vat,
            wht,
            discount_percent,
            vat_percent,
            wht_percent,
            total,
            rate_date,
            created_at,
            created_by,
            updated_at,
            updated_by
         FROM main_invoice_table
         WHERE invoice_number = ?
         ORDER BY id ASC'
    );
    if (!$itemsStmt) {
        throw new RuntimeException('Unable to load invoice items.', 500);
    }

    $itemsStmt->bind_param('s', $invoiceNumber);
    $itemsStmt->execute();
    $invoice['items'] = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();

    $companyResult = $conn->query(
        'SELECT
            id,
            office_address,
            email,
            tel,
            account_name,
            account_number,
            bank_name,
            tin,
            created_at,
            created_by,
            updated_at,
            updated_by
         FROM profile_table
         LIMIT 1'
    );
    $invoice['company_data'] = $companyResult ? $companyResult->fetch_assoc() : null;

    $invoice['clients_data'] = null;
    $clientId = (int) ($invoice['clients_id'] ?? 0);
    if ($clientId > 0) {
        $clientStmt = $conn->prepare(
            'SELECT
                id,
                clients_id,
                clients_name,
                clients_email,
                clients_address,
                clients_number,
                created_at,
                created_by,
                updated_at,
                updated_by
             FROM clients_table
             WHERE clients_id = ?
             LIMIT 1'
        );
        if ($clientStmt) {
            $clientStmt->bind_param('i', $clientId);
            $clientStmt->execute();
            $invoice['clients_data'] = $clientStmt->get_result()->fetch_assoc();
            $clientStmt->close();
        }
    }

    return $invoice;
}

function recordInvoiceStatusHistory(
    mysqli $conn,
    string $invoiceNumber,
    string $statusType,
    ?string $oldStatus,
    string $newStatus,
    ?string $reason,
    array $user
): void {
    $statusType = strtolower(trim($statusType));
    if (!in_array($statusType, ['workflow', 'payment'], true)) {
        throw new InvalidArgumentException('Invalid invoice status history type.');
    }

    $oldStatus = $oldStatus !== null ? trim($oldStatus) : null;
    $newStatus = trim($newStatus);
    $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
    $userId = (int) ($user['id'] ?? 0);
    $userEmail = trim((string) ($user['email'] ?? 'system'));

    $stmt = $conn->prepare(
        'INSERT INTO invoice_status_history
            (invoice_number, status_type, old_status, new_status, reason, changed_by_user_id, changed_by_email)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to record invoice history.', 500);
    }

    $stmt->bind_param(
        'sssssis',
        $invoiceNumber,
        $statusType,
        $oldStatus,
        $newStatus,
        $reason,
        $userId,
        $userEmail
    );
    $stmt->execute();
    $stmt->close();
}

function fetchInvoiceStatusHistory(mysqli $conn, string $invoiceNumber): array
{
    $stmt = $conn->prepare(
        'SELECT
            id,
            invoice_number,
            status_type,
            old_status,
            new_status,
            reason,
            changed_by_user_id,
            changed_by_email,
            created_at
         FROM invoice_status_history
         WHERE invoice_number = ?
         ORDER BY created_at DESC, id DESC'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $invoiceNumber);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fetchInvoiceEmailHistory(mysqli $conn, string $invoiceNumber): array
{
    $stmt = $conn->prepare(
        'SELECT
            id,
            invoice_number,
            recipient_email,
            cc_emails,
            bcc_emails,
            subject,
            message,
            attachment_included,
            attachment_name,
            delivery_status,
            error_message,
            sent_by_user_id,
            sent_by_email,
            sent_at
         FROM invoice_email_history
         WHERE invoice_number = ?
         ORDER BY sent_at DESC, id DESC'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $invoiceNumber);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $row): array {
        $row['attachment_included'] = (bool) $row['attachment_included'];
        $row['cc_emails'] = $row['cc_emails'] ? json_decode($row['cc_emails'], true) ?: [] : [];
        $row['bcc_emails'] = $row['bcc_emails'] ? json_decode($row['bcc_emails'], true) ?: [] : [];
        return $row;
    }, $rows);
}

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function decodeInvoiceDraftPayload(string $payload): array
{
    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Return every receipt allocated to an invoice, including reversed entries.
 */
function fetchInvoicePayments(mysqli $conn, string $invoiceNumber): array
{
    $stmt = $conn->prepare(
        'SELECT
            p.id,
            p.payment_code,
            p.invoice_number,
            p.payment_date,
            p.amount,
            p.currency,
            p.payment_method,
            p.bank_id,
            p.bank_name,
            p.account_name,
            p.account_number,
            p.transaction_reference,
            p.notes,
            p.status,
            p.recorded_by_user_id,
            p.recorded_by_email,
            p.reversed_at,
            p.reversed_by_user_id,
            p.reversed_by_email,
            p.reversal_reason,
            p.created_at,
            p.updated_at,
            a.allocated_amount
         FROM invoice_payments p
         INNER JOIN invoice_payment_allocations a
            ON a.payment_id = p.id
           AND a.invoice_number = ?
         WHERE p.invoice_number = ?
         ORDER BY p.payment_date DESC, p.id DESC'
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ss', $invoiceNumber, $invoiceNumber);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['bank_id'] = $row['bank_id'] !== null ? (int) $row['bank_id'] : null;
        $row['amount'] = (float) $row['amount'];
        $row['allocated_amount'] = (float) $row['allocated_amount'];
        $row['is_reversed'] = strcasecmp((string) $row['status'], 'Reversed') === 0;
        return $row;
    }, $rows);
}

function invoicePaymentSummary(mysqli $conn, string $invoiceNumber, ?float $invoiceTotal = null): array
{
    if ($invoiceTotal === null) {
        $invoiceStmt = $conn->prepare(
            'SELECT CAST(invoice_amount AS DECIMAL(18,2)) AS invoice_total
             FROM invoice_table
             WHERE invoice_number = ?
             LIMIT 1'
        );
        if (!$invoiceStmt) {
            throw new RuntimeException('Unable to calculate invoice payment summary.', 500);
        }
        $invoiceStmt->bind_param('s', $invoiceNumber);
        $invoiceStmt->execute();
        $invoiceRow = $invoiceStmt->get_result()->fetch_assoc();
        $invoiceStmt->close();
        if (!$invoiceRow) {
            throw new RuntimeException('Invoice not found.', 404);
        }
        $invoiceTotal = (float) $invoiceRow['invoice_total'];
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(a.allocated_amount), 0) AS amount_paid,
                COUNT(*) AS active_payment_count
         FROM invoice_payment_allocations a
         INNER JOIN invoice_payments p ON p.id = a.payment_id
         WHERE a.invoice_number = ?
           AND p.status = 'Active'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate invoice payment summary.', 500);
    }

    $stmt->bind_param('s', $invoiceNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $paid = round((float) ($row['amount_paid'] ?? 0), 2);
    $total = round(max(0, (float) $invoiceTotal), 2);
    $balance = round(max(0, $total - $paid), 2);

    return [
        'invoice_total' => $total,
        'amount_paid' => $paid,
        'balance_due' => $balance,
        'active_payment_count' => (int) ($row['active_payment_count'] ?? 0),
        'payment_progress' => $total > 0 ? min(100, round(($paid / $total) * 100, 2)) : 0,
    ];
}

function invoicePaymentStatus(float $invoiceTotal, float $amountPaid, string $dueDate): string
{
    $total = round(max(0, $invoiceTotal), 2);
    $paid = round(max(0, $amountPaid), 2);

    if ($total > 0 && $paid >= $total - 0.01) {
        return 'Paid';
    }

    if ($paid > 0) {
        return 'Partially Paid';
    }

    $dueTimestamp = strtotime($dueDate);
    if ($dueTimestamp !== false && $dueTimestamp < strtotime(date('Y-m-d'))) {
        return 'Overdue';
    }

    return 'Pending';
}

/**
 * Rebuild invoice_table.paid and payment status from active allocations.
 */
function syncInvoicePaymentState(
    mysqli $conn,
    string $invoiceNumber,
    array $user,
    string $reason = 'Invoice payment register updated.'
): array {
    $stmt = $conn->prepare(
        'SELECT status, CAST(invoice_amount AS DECIMAL(18,2)) AS invoice_total, due_date
         FROM invoice_table
         WHERE invoice_number = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to update the invoice payment state.', 500);
    }

    $stmt->bind_param('s', $invoiceNumber);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        throw new RuntimeException('Invoice not found.', 404);
    }

    $summary = invoicePaymentSummary($conn, $invoiceNumber, (float) $invoice['invoice_total']);
    $oldStatus = (string) ($invoice['status'] ?? 'Pending');
    $newStatus = invoicePaymentStatus(
        (float) $summary['invoice_total'],
        (float) $summary['amount_paid'],
        (string) ($invoice['due_date'] ?? '')
    );

    $update = $conn->prepare(
        'UPDATE invoice_table
         SET paid = ?, status = ?, updated_at = NOW(), updated_by = ?
         WHERE invoice_number = ?'
    );
    if (!$update) {
        throw new RuntimeException('Unable to update the invoice payment state.', 500);
    }

    $amountPaid = (float) $summary['amount_paid'];
    $userEmail = trim((string) ($user['email'] ?? 'system'));
    $update->bind_param('dsss', $amountPaid, $newStatus, $userEmail, $invoiceNumber);
    $update->execute();
    $update->close();

    if ($oldStatus !== $newStatus) {
        recordInvoiceStatusHistory(
            $conn,
            $invoiceNumber,
            'payment',
            $oldStatus,
            $newStatus,
            $reason,
            $user
        );
    }

    $summary['status'] = $newStatus;
    return $summary;
}

function generateInvoicePaymentCode(mysqli $conn): string
{
    do {
        $code = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $conn->prepare('SELECT id FROM invoice_payments WHERE payment_code = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to generate a payment reference.', 500);
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);

    return $code;
}

/**
 * Return a compact list of recent invoice reminders for the view page.
 */
function fetchInvoiceReminders(mysqli $conn, string $invoiceNumber, int $limit = 12): array
{
    $limit = max(1, min($limit, 50));
    $stmt = $conn->prepare(
        'SELECT
            id,
            invoice_number,
            reminder_kind,
            reminder_mode,
            recipient_email,
            subject,
            message,
            scheduled_for,
            delivery_status,
            error_message,
            created_by_user_id,
            created_by_email,
            sent_by_user_id,
            sent_by_email,
            sent_at,
            cancelled_at,
            cancelled_by_user_id,
            cancelled_by_email,
            cancel_reason,
            created_at,
            updated_at
         FROM invoice_reminders
         WHERE invoice_number = ?
         ORDER BY
            CASE WHEN delivery_status = \'Scheduled\' THEN 0 ELSE 1 END,
            COALESCE(scheduled_for, sent_at, created_at) DESC,
            id DESC
         LIMIT ?'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('si', $invoiceNumber, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Return a single, paginated invoice activity stream.
 * The view page receives only the newest page and loads older entries on demand.
 *
 * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|bool>}
 */
function fetchInvoiceActivityPage(mysqli $conn, string $invoiceNumber, int $page = 1, int $limit = 8): array
{
    $page = max(1, $page);
    $limit = max(1, min($limit, 25));
    $offset = ($page - 1) * $limit;

    $countStmt = $conn->prepare(
        'SELECT (
            (SELECT COUNT(*) FROM invoice_status_history WHERE invoice_number = ?) +
            (SELECT COUNT(*) FROM invoice_email_history WHERE invoice_number = ?) +
            (SELECT COUNT(*) FROM invoice_reminders WHERE invoice_number = ?)
         ) AS total'
    );
    if (!$countStmt) {
        throw new RuntimeException('Unable to count invoice activity.', 500);
    }
    $countStmt->bind_param('sss', $invoiceNumber, $invoiceNumber, $invoiceNumber);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $activityStmt = $conn->prepare(
        'SELECT * FROM (
            SELECT
                CONCAT(\'status-\', id) AS activity_id,
                \'status\' AS source_type,
                status_type AS source_subtype,
                old_status,
                new_status,
                reason AS activity_note,
                changed_by_email AS actor_email,
                created_at AS activity_at,
                NULL AS recipient_email,
                NULL AS activity_subject,
                NULL AS delivery_status,
                NULL AS scheduled_for
            FROM invoice_status_history
            WHERE invoice_number = ?

            UNION ALL

            SELECT
                CONCAT(\'email-\', id) AS activity_id,
                \'email\' AS source_type,
                NULL AS source_subtype,
                NULL AS old_status,
                NULL AS new_status,
                COALESCE(NULLIF(subject, \'\'), error_message) AS activity_note,
                sent_by_email AS actor_email,
                sent_at AS activity_at,
                recipient_email,
                subject AS activity_subject,
                delivery_status,
                NULL AS scheduled_for
            FROM invoice_email_history
            WHERE invoice_number = ?

            UNION ALL

            SELECT
                CONCAT(\'reminder-\', id) AS activity_id,
                \'reminder\' AS source_type,
                reminder_kind AS source_subtype,
                NULL AS old_status,
                NULL AS new_status,
                COALESCE(NULLIF(message, \'\'), error_message, cancel_reason) AS activity_note,
                COALESCE(sent_by_email, cancelled_by_email, created_by_email) AS actor_email,
                COALESCE(sent_at, cancelled_at, created_at) AS activity_at,
                recipient_email,
                subject AS activity_subject,
                delivery_status,
                scheduled_for
            FROM invoice_reminders
            WHERE invoice_number = ?
        ) activity
        ORDER BY activity_at DESC, activity_id DESC
        LIMIT ? OFFSET ?'
    );
    if (!$activityStmt) {
        throw new RuntimeException('Unable to load invoice activity.', 500);
    }

    $activityStmt->bind_param('sssii', $invoiceNumber, $invoiceNumber, $invoiceNumber, $limit, $offset);
    $activityStmt->execute();
    $rows = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $activityStmt->close();

    $activities = array_map(static function (array $row): array {
        $source = (string) ($row['source_type'] ?? 'status');
        $deliveryStatus = (string) ($row['delivery_status'] ?? '');
        $type = 'workflow';
        $title = 'Invoice workflow updated';
        $description = '';

        if ($source === 'status') {
            $type = ($row['source_subtype'] ?? '') === 'payment' ? 'payment' : 'workflow';
            $title = $type === 'payment' ? 'Payment status updated' : 'Invoice workflow updated';
            $description = trim((string) ($row['old_status'] ?? '')) !== ''
                ? (string) $row['old_status'] . ' → ' . (string) ($row['new_status'] ?? '')
                : 'Created → ' . (string) ($row['new_status'] ?? '');
        } elseif ($source === 'email') {
            $failed = strtolower($deliveryStatus) === 'failed';
            $type = $failed ? 'error' : 'email';
            $title = $failed ? 'Invoice email failed' : 'Invoice email sent';
            $description = 'To ' . (string) ($row['recipient_email'] ?? '—');
        } else {
            $normalized = strtolower($deliveryStatus);
            $type = match ($normalized) {
                'failed' => 'reminder-error',
                'cancelled', 'skipped' => 'reminder-cancelled',
                'scheduled', 'processing' => 'reminder-scheduled',
                default => 'reminder',
            };
            $title = match ($normalized) {
                'scheduled' => 'Payment reminder scheduled',
                'processing' => 'Payment reminder processing',
                'failed' => 'Payment reminder failed',
                'cancelled' => 'Payment reminder cancelled',
                'skipped' => 'Payment reminder skipped',
                'logged' => 'Payment reminder logged',
                default => 'Payment reminder sent',
            };
            $description = 'To ' . (string) ($row['recipient_email'] ?? '—');
            if ($normalized === 'scheduled' && !empty($row['scheduled_for'])) {
                $description .= ' · scheduled for ' . (string) $row['scheduled_for'];
            }
        }

        return [
            'id' => (string) ($row['activity_id'] ?? uniqid('activity-', true)),
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'note' => (string) ($row['activity_note'] ?? ''),
            'user' => (string) ($row['actor_email'] ?? ''),
            'date' => (string) ($row['activity_at'] ?? ''),
        ];
    }, $rows);

    return [
        'data' => $activities,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + count($activities)) < $total,
        ],
    ];
}
