<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/invoice_helpers.php';
require_once __DIR__ . '/../../utils/email_template.php';
require_once __DIR__ . '/../../utils/mailer.php';

function normalizeInvoiceEmailList(mixed $value, int $limit = 10): array
{
    if (is_string($value)) {
        $value = preg_split('/[,;\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($value)) {
        return [];
    }

    $emails = [];
    foreach ($value as $item) {
        $email = is_array($item) ? trim((string) ($item['email'] ?? '')) : trim((string) $item);
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email address: {$email}", 400);
        }
        $emails[strtolower($email)] = ['email' => $email, 'name' => ''];
        if (count($emails) >= $limit) {
            break;
        }
    }

    return array_values($emails);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new RuntimeException('Route not found.', 405);
}

$user = authenticateUser();
requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER]);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    throw new RuntimeException('Invalid request body.', 400);
}

$invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    throw new RuntimeException('Invoice number is required.', 400);
}

$invoice = fetchInvoiceBundle($conn, $invoiceNumber);
if (in_array((string) ($invoice['workflow_status'] ?? ''), ['Cancelled', 'Void'], true)) {
    throw new RuntimeException('A cancelled or void invoice cannot be sent.', 409);
}

$defaultRecipient = trim((string) ($invoice['clients_data']['clients_email'] ?? ''));
$recipient = trim((string) ($data['recipient_email'] ?? $defaultRecipient));
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('A valid recipient email address is required.', 400);
}

$cc = normalizeInvoiceEmailList($data['cc_emails'] ?? []);
$bcc = normalizeInvoiceEmailList($data['bcc_emails'] ?? []);
$message = trim((string) ($data['message'] ?? ''));
$messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
if ($messageLength > 3000) {
    throw new RuntimeException('The email message must not exceed 3,000 characters.', 400);
}

$template = buildInvoiceEmailTemplate($invoice, $message);
$subject = trim((string) ($data['subject'] ?? $template['subject']));
if ($subject === '') {
    $subject = $template['subject'];
}
$subjectLength = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
if ($subjectLength > 255) {
    throw new RuntimeException('The email subject must not exceed 255 characters.', 400);
}

$attachments = [];
$attachmentIncluded = 0;
$attachmentName = null;
$attachPdf = filter_var($data['attach_pdf'] ?? false, FILTER_VALIDATE_BOOLEAN);
if ($attachPdf) {
    $encoded = trim((string) ($data['pdf_base64'] ?? ''));
    if ($encoded === '') {
        throw new RuntimeException('The PDF attachment could not be prepared.', 400);
    }

    $encoded = preg_replace('#^data:application/pdf;base64,#i', '', $encoded);
    $binary = base64_decode((string) $encoded, true);
    if ($binary === false || strncmp($binary, '%PDF', 4) !== 0) {
        throw new RuntimeException('The supplied attachment is not a valid PDF.', 400);
    }
    if (strlen($binary) > 8 * 1024 * 1024) {
        throw new RuntimeException('The PDF attachment must not exceed 8 MB.', 413);
    }

    $safeClient = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($invoice['clients_name'] ?? 'client'));
    $safeClient = trim((string) $safeClient, '-') ?: 'client';
    $attachmentName = "Invoice-AZ-{$invoiceNumber}-{$safeClient}.pdf";
    $attachments[] = [
        'content' => $binary,
        'name' => $attachmentName,
        'type' => 'application/pdf',
    ];
    $attachmentIncluded = 1;
}

$userId = (int) $user['id'];
$userEmail = (string) $user['email'];
$ccJson = json_encode(array_column($cc, 'email'), JSON_UNESCAPED_SLASHES) ?: '[]';
$bccJson = json_encode(array_column($bcc, 'email'), JSON_UNESCAPED_SLASHES) ?: '[]';

$result = sendSmartbooksMail([
    'to' => [[
        'email' => $recipient,
        'name' => (string) ($invoice['clients_name'] ?? ''),
    ]],
    'cc' => $cc,
    'bcc' => $bcc,
    'subject' => $subject,
    'html' => $template['html'],
    'text' => $template['text'],
    'attachments' => $attachments,
]);

$deliveryStatus = match ((string) ($result['status'] ?? 'failed')) {
    'sent' => 'Sent',
    'logged' => 'Logged',
    default => 'Failed',
};
$errorMessage = $result['success']
    ? null
    : substr((string) ($result['error'] ?? 'Unknown mail delivery error.'), 0, 1000);

$historyStmt = $conn->prepare(
    'INSERT INTO invoice_email_history
        (invoice_number, recipient_email, cc_emails, bcc_emails, subject, message,
         attachment_included, attachment_name, delivery_status, error_message,
         sent_by_user_id, sent_by_email)
     VALUES (?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?)'
);
if (!$historyStmt) {
    throw new RuntimeException('Unable to record the email delivery attempt.', 500);
}
$historyStmt->bind_param(
    'ssssssisssis',
    $invoiceNumber,
    $recipient,
    $ccJson,
    $bccJson,
    $subject,
    $message,
    $attachmentIncluded,
    $attachmentName,
    $deliveryStatus,
    $errorMessage,
    $userId,
    $userEmail
);
$historyStmt->execute();
$emailHistoryId = (int) $conn->insert_id;
$historyStmt->close();

if (!$result['success']) {
    jsonResponse([
        'status' => 'Failed',
        'code' => 'MAIL_DELIVERY_FAILED',
        'message' => (string) ($result['public_error'] ?? 'The invoice email could not be delivered.'),
        'data' => [
            'history_id' => $emailHistoryId,
            'delivery_status' => 'Failed',
        ],
    ], 424);
}

$isActuallySent = ($result['status'] ?? '') === 'sent';
if ($isActuallySent) {
    $updateStmt = $conn->prepare(
        'UPDATE invoice_table
         SET last_sent_at = CURRENT_TIMESTAMP,
             sent_count = sent_count + 1,
             updated_by = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE invoice_number = ?'
    );
    if (!$updateStmt) {
        throw new RuntimeException('The email was delivered, but the invoice delivery status could not be updated.', 500);
    }
    $updateStmt->bind_param('ss', $userEmail, $invoiceNumber);
    $updateStmt->execute();
    $updateStmt->close();
}

$logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
if ($logStmt) {
    $action = $isActuallySent
        ? "{$userEmail} sent Invoice #{$invoiceNumber} to {$recipient}"
        : "{$userEmail} logged Invoice #{$invoiceNumber} email for {$recipient}";
    $logStmt->bind_param('iss', $userId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();
}

jsonResponse([
    'status' => 'Success',
    'message' => $isActuallySent
        ? 'Invoice email sent successfully.'
        : 'The invoice email was written to the local mail log. Set MAIL_DRIVER=smtp to deliver it externally.',
    'data' => [
        'history_id' => $emailHistoryId,
        'recipient_email' => $recipient,
        'attachment_included' => (bool) $attachmentIncluded,
        'delivery_status' => $deliveryStatus,
        'transport' => (string) ($result['transport'] ?? smartbooksMailTransport()),
        'sent_at' => date('Y-m-d H:i:s'),
    ],
]);
