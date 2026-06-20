<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/includes/security.php';

/**
 * Smartbooks transactional email transport.
 *
 * MAIL_DRIVER=smtp sends genuine email.
 * MAIL_DRIVER=log writes a readable copy to storage/mail.log for local diagnostics.
 *
 * Supported attachment shape:
 * [
 *   'content' => '<binary content>',
 *   'name' => 'invoice.pdf',
 *   'type' => 'application/pdf',
 * ]
 */
function smartbooksMailTransport(): string
{
    return strtolower(trim(envString('MAIL_DRIVER', 'smtp'))) === 'log' ? 'log' : 'smtp';
}

function smartbooksMailTextBody(string $htmlBody): string
{
    $withBreaks = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $htmlBody) ?? $htmlBody;
    $withParagraphs = preg_replace('/<\s*\/p\s*>/i', "\n\n", $withBreaks) ?? $withBreaks;

    return trim(html_entity_decode(strip_tags($withParagraphs), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function smartbooksRequiredMailSetting(string $key): string
{
    $value = trim(envString($key));
    if ($value === '') {
        throw new RuntimeException("{$key} is not configured.");
    }

    return $value;
}

function smartbooksMailAttachmentSummary(array $attachments): array
{
    return array_map(static function (array $attachment): array {
        return [
            'filename' => (string) ($attachment['name'] ?? 'attachment'),
            'mime_type' => (string) ($attachment['type'] ?? 'application/octet-stream'),
            'size_bytes' => strlen((string) ($attachment['content'] ?? '')),
        ];
    }, $attachments);
}

/**
 * Convert the private SMTP failure into a safe, actionable message for the UI.
 */
function smartbooksPublicMailError(string $error): string
{
    $normalized = strtolower($error);

    if (str_contains($normalized, 'smtp_host is not configured')
        || str_contains($normalized, 'smtp_username is not configured')
        || str_contains($normalized, 'smtp_password is not configured')
        || str_contains($normalized, 'mail_from_address is not configured')) {
        return 'Email delivery is not configured completely. Please check the Smartbooks mail settings.';
    }

    if (str_contains($normalized, 'authenticate')
        || str_contains($normalized, 'authentication')
        || str_contains($normalized, 'username and password not accepted')
        || str_contains($normalized, '535')) {
        return 'The mail server rejected the configured email credentials. Please verify the SMTP username and password.';
    }

    if (str_contains($normalized, 'could not connect')
        || str_contains($normalized, 'connection refused')
        || str_contains($normalized, 'connection timed out')
        || str_contains($normalized, 'timed out')
        || str_contains($normalized, 'getaddrinfo')
        || str_contains($normalized, 'network is unreachable')) {
        return 'Smartbooks could not connect to the configured mail server. Please verify the SMTP host, port and encryption settings.';
    }

    if (str_contains($normalized, 'certificate') || str_contains($normalized, 'crypto')) {
        return 'The mail server security certificate could not be verified. Please check the SMTP encryption and certificate settings.';
    }

    return 'The invoice email could not be delivered. The failed attempt has been logged for review.';
}

function buildSmartbooksSmtpMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->SMTPAuth = envBool('SMTP_AUTH', true);
    $mail->Host = smartbooksRequiredMailSetting('SMTP_HOST');
    $mail->Port = max(1, (int) envString('SMTP_PORT', '465'));

    if ($mail->SMTPAuth) {
        $mail->Username = smartbooksRequiredMailSetting('SMTP_USERNAME');
        $mail->Password = smartbooksRequiredMailSetting('SMTP_PASSWORD');
    }

    /*
     * Keep the default behaviour aligned with the mailer already working in the
     * user's other application: when SMTP_ENCRYPTION is omitted or set to auto,
     * PHPMailer is allowed to negotiate TLS itself. Explicit values still work.
     */
    
    // $encryption = strtolower(trim(envString('SMTP_ENCRYPTION', 'auto')));
    // if (in_array($encryption, ['smtps', 'ssl'], true)) {
    //     $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    //     $mail->SMTPAutoTLS = false;
    // } elseif (in_array($encryption, ['starttls', 'tls'], true)) {
    //     $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    //     $mail->SMTPAutoTLS = true;
    // } elseif (in_array($encryption, ['none', 'off', 'false'], true)) {
    //     $mail->SMTPSecure = false;
    //     $mail->SMTPAutoTLS = false;
    // }

    $mail->Timeout = max(5, min((int) envString('SMTP_TIMEOUT', '20'), 45));
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_BASE64;
    $mail->SMTPKeepAlive = false;

    if (envBool('SMTP_DEBUG', false)) {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = static function (string $message, int $level): void {
            error_log("[Smartbooks SMTP {$level}] " . trim($message));
        };
    }

    $fromAddress = trim(envString('MAIL_FROM_ADDRESS', $mail->Username));
    if ($fromAddress === '') {
        throw new RuntimeException('MAIL_FROM_ADDRESS is not configured.');
    }

    $fromName = trim(envString('MAIL_FROM_NAME', 'Smartbooks Accounting')) ?: 'Smartbooks Accounting';
    $mail->setFrom($fromAddress, $fromName);

    $replyTo = trim(envString('MAIL_REPLY_TO_ADDRESS'));
    if ($replyTo !== '') {
        $mail->addReplyTo(
            $replyTo,
            trim(envString('MAIL_REPLY_TO_NAME', $fromName)) ?: $fromName
        );
    }

    if (!envBool('SMTP_VERIFY_PEER', true)) {
        // Local-development escape hatch only. Keep peer verification enabled in production.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    return $mail;
}

/**
 * @return array{
 *   success: bool,
 *   status: string,
 *   transport: string,
 *   message_id: ?string,
 *   error: ?string,
 *   public_error: ?string
 * }
 */
function sendSmartbooksMail(array $options): array
{
    $transport = smartbooksMailTransport();
    $htmlBody = (string) ($options['html'] ?? '');
    $textBody = trim((string) ($options['text'] ?? ''));
    $attachments = is_array($options['attachments'] ?? null) ? $options['attachments'] : [];

    if ($transport === 'log') {
        try {
            $directory = dirname(__DIR__) . '/storage';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create the local mail log directory.');
            }

            $recipientSummary = implode(', ', array_map(
                static fn (array $recipient): string => trim((string) ($recipient['email'] ?? '')),
                is_array($options['to'] ?? null) ? $options['to'] : []
            ));
            $attachmentSummary = smartbooksMailAttachmentSummary($attachments);
            $attachmentNames = implode(', ', array_column($attachmentSummary, 'filename'));

            $entry = sprintf(
                "[%s] TO: %s | SUBJECT: %s%s\n%s\n\n",
                date('c'),
                $recipientSummary,
                (string) ($options['subject'] ?? 'Smartbooks notification'),
                $attachmentNames !== '' ? ' | ATTACHMENTS: ' . $attachmentNames : '',
                $textBody !== '' ? $textBody : smartbooksMailTextBody($htmlBody)
            );

            file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX);

            return [
                'success' => true,
                'status' => 'logged',
                'transport' => 'log',
                'message_id' => null,
                'error' => null,
                'public_error' => null,
            ];
        } catch (Throwable $error) {
            error_log('[Smartbooks Mail Log] ' . $error->getMessage());

            return [
                'success' => false,
                'status' => 'failed',
                'transport' => 'log',
                'message_id' => null,
                'error' => $error->getMessage(),
                'public_error' => 'The email could not be written to the local mail log.',
            ];
        }
    }

    try {
        $mail = buildSmartbooksSmtpMailer();

        foreach (($options['to'] ?? []) as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email !== '') {
                $mail->addAddress($email, trim((string) ($recipient['name'] ?? '')));
            }
        }
        foreach (($options['cc'] ?? []) as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email !== '') {
                $mail->addCC($email, trim((string) ($recipient['name'] ?? '')));
            }
        }
        foreach (($options['bcc'] ?? []) as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email !== '') {
                $mail->addBCC($email, trim((string) ($recipient['name'] ?? '')));
            }
        }

        if (count($mail->getToAddresses()) === 0) {
            throw new RuntimeException('At least one recipient email address is required.');
        }

        $mail->isHTML(true);
        $mail->Subject = (string) ($options['subject'] ?? 'Smartbooks notification');
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : smartbooksMailTextBody($htmlBody);

        foreach ($attachments as $attachment) {
            $content = (string) ($attachment['content'] ?? '');
            $filename = trim((string) ($attachment['name'] ?? 'attachment'));
            $mimeType = trim((string) ($attachment['type'] ?? 'application/octet-stream'));

            if ($content !== '' && $filename !== '') {
                $mail->addStringAttachment(
                    $content,
                    $filename,
                    PHPMailer::ENCODING_BASE64,
                    $mimeType !== '' ? $mimeType : 'application/octet-stream'
                );
            }
        }

        $mail->send();

        return [
            'success' => true,
            'status' => 'sent',
            'transport' => 'smtp',
            'message_id' => $mail->getLastMessageID() ?: null,
            'error' => null,
            'public_error' => null,
        ];
    } catch (MailException | RuntimeException $error) {
        $privateError = isset($mail) && $mail->ErrorInfo !== ''
            ? $mail->ErrorInfo
            : $error->getMessage();
        error_log('[Smartbooks Mail] ' . $privateError);

        return [
            'success' => false,
            'status' => 'failed',
            'transport' => 'smtp',
            'message_id' => null,
            'error' => $privateError,
            'public_error' => smartbooksPublicMailError($privateError),
        ];
    } catch (Throwable $error) {
        error_log('[Smartbooks Mail] ' . $error->getMessage());

        return [
            'success' => false,
            'status' => 'failed',
            'transport' => 'smtp',
            'message_id' => null,
            'error' => $error->getMessage(),
            'public_error' => smartbooksPublicMailError($error->getMessage()),
        ];
    }
}
