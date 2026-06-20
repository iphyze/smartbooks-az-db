<?php
declare(strict_types=1);

require_once __DIR__ . '/invoice_helpers.php';
require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notification_helpers.php';


function notifyScheduledInvoiceReminderOwner(
    mysqli $conn,
    array $reminder,
    string $notificationType,
    string $title,
    string $message,
    string $priority = 'info',
    array $metadata = []
): void {
    $recipientUserId = (int) ($reminder['created_by_user_id'] ?? 0);
    $invoiceNumber = trim((string) ($reminder['invoice_number'] ?? ''));
    if ($recipientUserId <= 0 || $invoiceNumber === '') {
        return;
    }

    notifyUser(
        $conn,
        $recipientUserId,
        $notificationType,
        'invoice',
        $title,
        $message,
        $priority,
        'invoice',
        $invoiceNumber,
        '/invoice/view/' . rawurlencode($invoiceNumber),
        array_merge([
            'reminder_id' => (int) ($reminder['id'] ?? 0),
            'invoice_number' => $invoiceNumber,
        ], $metadata)
    );
}

function fetchInvoiceReminderById(mysqli $conn, int $reminderId): ?array
{
    $stmt = $conn->prepare(
        'SELECT * FROM invoice_reminders WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load the invoice reminder.', 500);
    }
    $stmt->bind_param('i', $reminderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function invoiceReminderCanBeSent(array $invoice): bool
{
    $workflow = (string) ($invoice['workflow_status'] ?? 'Issued');
    $total = (float) ($invoice['invoice_amount'] ?? 0);
    $paid = (float) ($invoice['paid'] ?? 0);
    return !in_array($workflow, ['Cancelled', 'Void'], true) && max($total - $paid, 0) > 0.009;
}

/**
 * Deliver one existing reminder record and update its audit fields.
 *
 * @return array{success: bool, status: string, message: string}
 */
function deliverInvoiceReminder(
    mysqli $conn,
    array $reminder,
    array $invoice,
    ?int $sentByUserId = null,
    ?string $sentByEmail = null
): array {
    $reminderId = (int) ($reminder['id'] ?? 0);
    if ($reminderId <= 0) {
        throw new InvalidArgumentException('A valid reminder record is required.');
    }

    if (!invoiceReminderCanBeSent($invoice)) {
        $reason = in_array((string) ($invoice['workflow_status'] ?? ''), ['Cancelled', 'Void'], true)
            ? 'The invoice is cancelled or void.'
            : 'The invoice no longer has an outstanding balance.';
        $status = 'Skipped';
        $stmt = $conn->prepare(
            'UPDATE invoice_reminders
             SET delivery_status = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('ssi', $status, $reason, $reminderId);
            $stmt->execute();
            $stmt->close();
        }

        if ($sentByUserId === null) {
            $invoiceNumber = trim((string) ($reminder['invoice_number'] ?? ''));
            notifyScheduledInvoiceReminderOwner(
                $conn,
                $reminder,
                'invoice_reminder_skipped',
                'Scheduled reminder skipped',
                "The scheduled payment reminder for Invoice #{$invoiceNumber} was skipped. {$reason}",
                'warning',
                ['delivery_status' => 'Skipped', 'reason' => $reason]
            );
        }

        return ['success' => false, 'status' => 'Skipped', 'message' => $reason];
    }

    $recipient = trim((string) ($reminder['recipient_email'] ?? ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The reminder recipient email address is invalid.', 400);
    }

    $template = buildInvoiceReminderEmailTemplate(
        $invoice,
        (string) ($reminder['message'] ?? ''),
        (string) ($reminder['reminder_kind'] ?? 'Friendly')
    );
    $subject = trim((string) ($reminder['subject'] ?? '')) ?: $template['subject'];

    $result = sendSmartbooksMail([
        'to' => [[
            'email' => $recipient,
            'name' => (string) ($invoice['clients_name'] ?? ''),
        ]],
        'subject' => $subject,
        'html' => $template['html'],
        'text' => $template['text'],
    ]);

    $deliveryStatus = match ((string) ($result['status'] ?? 'failed')) {
        'sent' => 'Sent',
        'logged' => 'Logged',
        default => 'Failed',
    };
    $privateError = $result['success']
        ? null
        : substr((string) ($result['error'] ?? 'Unknown mail delivery error.'), 0, 1000);
    $actorEmail = trim((string) ($sentByEmail ?? ''));
    if ($actorEmail === '') {
        $actorEmail = 'Smartbooks Scheduler';
    }

    $stmt = $conn->prepare(
        'UPDATE invoice_reminders
         SET delivery_status = ?,
             error_message = ?,
             sent_by_user_id = ?,
             sent_by_email = ?,
             sent_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('The reminder was processed, but its delivery status could not be saved.', 500);
    }
    $stmt->bind_param('ssisi', $deliveryStatus, $privateError, $sentByUserId, $actorEmail, $reminderId);
    $stmt->execute();
    $stmt->close();

    if ($sentByUserId === null) {
        $invoiceNumber = trim((string) ($reminder['invoice_number'] ?? ''));
        $wasSuccessful = (bool) ($result['success'] ?? false);
        notifyScheduledInvoiceReminderOwner(
            $conn,
            $reminder,
            $wasSuccessful ? 'invoice_reminder_sent' : 'invoice_reminder_failed',
            $wasSuccessful ? 'Scheduled reminder processed' : 'Scheduled reminder failed',
            $wasSuccessful
                ? "The scheduled payment reminder for Invoice #{$invoiceNumber} was processed successfully."
                : "The scheduled payment reminder for Invoice #{$invoiceNumber} could not be delivered.",
            $wasSuccessful ? 'info' : 'critical',
            [
                'delivery_status' => $deliveryStatus,
                'recipient_email' => $recipient,
            ]
        );
    }

    return [
        'success' => (bool) ($result['success'] ?? false),
        'status' => $deliveryStatus,
        'message' => $result['success']
            ? ($deliveryStatus === 'Logged'
                ? 'The reminder was written to the local mail log.'
                : 'Payment reminder sent successfully.')
            : (string) ($result['public_error'] ?? 'The payment reminder could not be delivered.'),
    ];
}
