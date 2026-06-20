<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../utils/invoice_helpers.php';
require_once __DIR__ . '/../utils/invoice_reminder_helpers.php';

$batchSize = max(1, min((int) envString('INVOICE_REMINDER_BATCH_SIZE', '40'), 100));

// Recover reminders left in Processing if a previous task stopped unexpectedly.
$conn->query(
    "UPDATE invoice_reminders
     SET delivery_status = 'Scheduled', updated_at = CURRENT_TIMESTAMP
     WHERE delivery_status = 'Processing'
       AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
);
$stmt = $conn->prepare(
    "SELECT id
     FROM invoice_reminders
     WHERE delivery_status = 'Scheduled'
       AND scheduled_for IS NOT NULL
       AND scheduled_for <= NOW()
     ORDER BY scheduled_for ASC, id ASC
     LIMIT ?"
);
if (!$stmt) {
    fwrite(STDERR, "Unable to load due invoice reminders.\n");
    exit(1);
}
$stmt->bind_param('i', $batchSize);
$stmt->execute();
$ids = array_map(
    static fn (array $row): int => (int) $row['id'],
    $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
);
$stmt->close();

$sent = 0;
$failed = 0;
$skipped = 0;

foreach ($ids as $reminderId) {
    $reminder = null;
    $claim = $conn->prepare(
        "UPDATE invoice_reminders
         SET delivery_status = 'Processing', updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND delivery_status = 'Scheduled'"
    );
    if (!$claim) {
        $failed++;
        continue;
    }
    $claim->bind_param('i', $reminderId);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    if (!$claimed) {
        continue;
    }

    try {
        $reminder = fetchInvoiceReminderById($conn, $reminderId);
        if (!$reminder) {
            $failed++;
            continue;
        }
        $invoice = fetchInvoiceBundle($conn, (string) $reminder['invoice_number']);
        $result = deliverInvoiceReminder($conn, $reminder, $invoice, null, 'Smartbooks Scheduler');
        if ($result['success']) {
            $sent++;
        } elseif ($result['status'] === 'Skipped') {
            $skipped++;
        } else {
            $failed++;
        }
    } catch (Throwable $error) {
        $failed++;
        $message = substr($error->getMessage(), 0, 1000);
        $status = 'Failed';
        $update = $conn->prepare(
            'UPDATE invoice_reminders
             SET delivery_status = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if ($update) {
            $update->bind_param('ssi', $status, $message, $reminderId);
            $update->execute();
            $update->close();
        }
        if (is_array($reminder)) {
            $invoiceNumber = trim((string) ($reminder['invoice_number'] ?? ''));
            notifyScheduledInvoiceReminderOwner(
                $conn,
                $reminder,
                'invoice_reminder_failed',
                'Scheduled reminder failed',
                "The scheduled payment reminder for Invoice #{$invoiceNumber} could not be processed.",
                'critical',
                ['delivery_status' => 'Failed']
            );
        }
        error_log('[Smartbooks Reminder Cron] ' . $error->getMessage());
    }
}

fwrite(STDOUT, sprintf(
    "[%s] Processed %d reminder(s): %d sent/logged, %d skipped, %d failed.\n",
    date('c'),
    count($ids),
    $sent,
    $skipped,
    $failed
));
exit($failed > 0 ? 1 : 0);
