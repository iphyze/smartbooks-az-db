<?php
declare(strict_types=1);

function invoiceEmailEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function invoiceEmailMoney(float $amount, string $currency): string
{
    return invoiceEmailEscape($currency) . ' ' . number_format($amount, 2);
}

function invoiceEmailDate(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('F j, Y', $timestamp) : invoiceEmailEscape($date);
}

/**
 * Build the branded invoice email kept in api/utils as requested.
 */
function buildInvoiceEmailTemplate(array $invoice, string $customMessage = ''): array
{
    $company = is_array($invoice['company_data'] ?? null) ? $invoice['company_data'] : [];
    $client = is_array($invoice['clients_data'] ?? null) ? $invoice['clients_data'] : [];
    $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];

    $currency = trim((string) ($invoice['currency'] ?? 'NGN')) ?: 'NGN';
    $invoiceNumber = (string) ($invoice['invoice_number'] ?? '');
    $clientName = trim((string) ($invoice['clients_name'] ?? $client['clients_name'] ?? 'Valued Client'));
    $companyName = envString('COMPANY_NAME', 'A to Z Consultancy Ltd');
    $invoiceTotal = (float) ($invoice['invoice_amount'] ?? 0);
    $paid = (float) ($invoice['paid'] ?? 0);
    $balance = max($invoiceTotal - $paid, 0);
    $status = (string) ($invoice['status'] ?? 'Pending');
    $subject = "Invoice AZ-{$invoiceNumber} from {$companyName}";

    $rows = '';
    foreach ($items as $index => $item) {
        $description = invoiceEmailEscape($item['description'] ?? '');
        $amount = (float) ($item['amount'] ?? 0);
        $discount = (float) ($item['discount_percent'] ?? 0);
        $vat = (float) ($item['vat_percent'] ?? 0);

        $rows .= '<tr>'
            . '<td style="padding:14px 12px;border-bottom:1px solid #dce7ea;color:#60758a;font-size:13px;">' . ($index + 1) . '</td>'
            . '<td style="padding:14px 12px;border-bottom:1px solid #dce7ea;color:#1a2b3d;font-size:14px;line-height:1.5;">' . $description . '</td>'
            . '<td style="padding:14px 12px;border-bottom:1px solid #dce7ea;color:#60758a;font-size:13px;text-align:center;">' . number_format($discount, 2) . '%</td>'
            . '<td style="padding:14px 12px;border-bottom:1px solid #dce7ea;color:#60758a;font-size:13px;text-align:center;">' . number_format($vat, 2) . '%</td>'
            . '<td style="padding:14px 12px;border-bottom:1px solid #dce7ea;color:#142437;font-size:14px;font-weight:700;text-align:right;white-space:nowrap;">' . invoiceEmailMoney($amount, $currency) . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="5" style="padding:24px;text-align:center;color:#75879a;">No invoice lines were supplied.</td></tr>';
    }

    $messageBlock = '';
    $plainMessage = trim($customMessage);
    if ($plainMessage !== '') {
        $messageBlock = '<div style="margin:0 0 22px;padding:16px 18px;border-left:4px solid #10b7b1;background:#effbfa;border-radius:8px;color:#365366;font-size:14px;line-height:1.65;">'
            . nl2br(invoiceEmailEscape($plainMessage))
            . '</div>';
    }

    $bankBlock = '';
    if (trim((string) ($invoice['bank_name'] ?? '')) !== '') {
        $bankBlock = '<div style="margin-top:24px;padding:18px;border:1px solid #dce7ea;border-radius:12px;background:#f8fbfc;">'
            . '<div style="font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#10a7a2;margin-bottom:12px;">Payment details</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:13px;color:#4b6175;">'
            . '<tr><td style="padding:4px 0;font-weight:700;color:#24384b;">Account name</td><td style="padding:4px 0;text-align:right;">' . invoiceEmailEscape($invoice['account_name'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:4px 0;font-weight:700;color:#24384b;">Account number</td><td style="padding:4px 0;text-align:right;">' . invoiceEmailEscape($invoice['account_number'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:4px 0;font-weight:700;color:#24384b;">Bank</td><td style="padding:4px 0;text-align:right;">' . invoiceEmailEscape($invoice['bank_name'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:4px 0;font-weight:700;color:#24384b;">Currency</td><td style="padding:4px 0;text-align:right;">' . invoiceEmailEscape($invoice['account_currency'] ?? $currency) . '</td></tr>'
            . '</table></div>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#edf4f6;font-family:Arial,Helvetica,sans-serif;color:#1a2b3d;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#edf4f6;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#ffffff;border:1px solid #dce7ea;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(28,61,75,.10);">'
        . '<tr><td style="padding:26px 30px;background:#081827;">'
        . '<table role="presentation" width="100%"><tr><td>'
        . '<div style="font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:#22c9c2;">Smartbooks Accounting</div>'
        . '<div style="margin-top:7px;font-size:22px;font-weight:800;color:#ffffff;">' . invoiceEmailEscape($companyName) . '</div>'
        . '</td><td align="right"><div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#0b3f46;color:#65e6df;font-size:12px;font-weight:800;">Invoice AZ-' . invoiceEmailEscape($invoiceNumber) . '</div></td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:30px;">'
        . '<div style="font-size:15px;color:#60758a;margin-bottom:8px;">Hello ' . invoiceEmailEscape($clientName) . ',</div>'
        . '<h1 style="margin:0 0 10px;font-size:28px;line-height:1.2;color:#102337;">Your invoice is ready</h1>'
        . '<p style="margin:0 0 22px;color:#60758a;font-size:14px;line-height:1.65;">Please find a summary of invoice <strong style="color:#172d42;">AZ-' . invoiceEmailEscape($invoiceNumber) . '</strong> below. A PDF copy may also be attached to this email.</p>'
        . $messageBlock
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:22px;border-collapse:separate;border-spacing:0 8px;">'
        . '<tr><td style="padding:14px 16px;background:#f4f8fa;border-radius:10px;color:#60758a;font-size:13px;">Invoice date<br><strong style="display:block;margin-top:5px;color:#193047;font-size:15px;">' . invoiceEmailDate($invoice['invoice_date'] ?? null) . '</strong></td>'
        . '<td width="12"></td><td style="padding:14px 16px;background:#f4f8fa;border-radius:10px;color:#60758a;font-size:13px;">Due date<br><strong style="display:block;margin-top:5px;color:#193047;font-size:15px;">' . invoiceEmailDate($invoice['due_date'] ?? null) . '</strong></td>'
        . '<td width="12"></td><td style="padding:14px 16px;background:#f4f8fa;border-radius:10px;color:#60758a;font-size:13px;">Status<br><strong style="display:block;margin-top:5px;color:#0d9e98;font-size:15px;">' . invoiceEmailEscape($status) . '</strong></td></tr>'
        . '</table>'
        . '<div style="overflow-x:auto;border:1px solid #dce7ea;border-radius:12px;">'
        . '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;min-width:580px;">'
        . '<thead><tr style="background:#0a8f88;">'
        . '<th style="padding:13px 12px;color:#fff;font-size:12px;text-align:left;">S/N</th>'
        . '<th style="padding:13px 12px;color:#fff;font-size:12px;text-align:left;">Description</th>'
        . '<th style="padding:13px 12px;color:#fff;font-size:12px;text-align:center;">Discount</th>'
        . '<th style="padding:13px 12px;color:#fff;font-size:12px;text-align:center;">VAT</th>'
        . '<th style="padding:13px 12px;color:#fff;font-size:12px;text-align:right;">Amount</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:20px;"><tr><td></td><td width="310">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">'
        . '<tr><td style="padding:7px 0;color:#60758a;font-size:13px;">Invoice total</td><td style="padding:7px 0;text-align:right;font-weight:800;color:#172d42;">' . invoiceEmailMoney($invoiceTotal, $currency) . '</td></tr>'
        . '<tr><td style="padding:7px 0;color:#60758a;font-size:13px;">Paid</td><td style="padding:7px 0;text-align:right;font-weight:700;color:#60758a;">' . invoiceEmailMoney($paid, $currency) . '</td></tr>'
        . '<tr><td style="padding:14px 0 4px;border-top:1px solid #dce7ea;color:#172d42;font-size:15px;font-weight:800;">Balance due</td><td style="padding:14px 0 4px;border-top:1px solid #dce7ea;text-align:right;color:#0a8f88;font-size:20px;font-weight:900;">' . invoiceEmailMoney($balance, $currency) . '</td></tr>'
        . '</table></td></tr></table>'
        . $bankBlock
        . '<p style="margin:26px 0 0;color:#60758a;font-size:13px;line-height:1.65;">Thank you for doing business with us. Please reply to this email if you need any clarification.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 30px;background:#f4f8fa;border-top:1px solid #dce7ea;color:#7890a3;font-size:12px;line-height:1.6;">'
        . invoiceEmailEscape($company['office_address'] ?? '') . '<br>'
        . invoiceEmailEscape($company['email'] ?? envString('MAIL_FROM_ADDRESS')) . ' · ' . invoiceEmailEscape($company['tel'] ?? '')
        . '</td></tr></table></td></tr></table></body></html>';

    $text = "Hello {$clientName},\n\n"
        . "Invoice AZ-{$invoiceNumber} from {$companyName}\n"
        . "Invoice date: " . invoiceEmailDate($invoice['invoice_date'] ?? null) . "\n"
        . "Due date: " . invoiceEmailDate($invoice['due_date'] ?? null) . "\n"
        . "Status: {$status}\n"
        . "Total: {$currency} " . number_format($invoiceTotal, 2) . "\n"
        . "Balance due: {$currency} " . number_format($balance, 2) . "\n\n"
        . ($plainMessage !== '' ? $plainMessage . "\n\n" : '')
        . "Thank you for doing business with us.";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}

/**
 * Build the branded payment-reminder email used by manual and scheduled reminders.
 */
function buildInvoiceReminderEmailTemplate(array $invoice, string $customMessage = '', string $reminderKind = 'Friendly'): array
{
    $company = is_array($invoice['company_data'] ?? null) ? $invoice['company_data'] : [];
    $client = is_array($invoice['clients_data'] ?? null) ? $invoice['clients_data'] : [];

    $currency = trim((string) ($invoice['currency'] ?? 'NGN')) ?: 'NGN';
    $invoiceNumber = (string) ($invoice['invoice_number'] ?? '');
    $clientName = trim((string) ($invoice['clients_name'] ?? $client['clients_name'] ?? 'Valued Client'));
    $companyName = envString('COMPANY_NAME', 'A to Z Consultancy Ltd');
    $invoiceTotal = (float) ($invoice['invoice_amount'] ?? 0);
    $paid = (float) ($invoice['paid'] ?? 0);
    $balance = max($invoiceTotal - $paid, 0);
    $kind = trim($reminderKind) !== '' ? trim($reminderKind) : 'Friendly';
    $subject = "Payment reminder: Invoice AZ-{$invoiceNumber}";

    $defaultMessage = match (strtolower($kind)) {
        'final' => "This is a final reminder that payment for invoice AZ-{$invoiceNumber} remains outstanding. Please arrange settlement or contact us immediately if there is an issue requiring attention.",
        'overdue' => "Our records show that invoice AZ-{$invoiceNumber} is overdue. Kindly arrange payment at your earliest convenience or let us know if payment has already been made.",
        'due today' => "Invoice AZ-{$invoiceNumber} is due today. Kindly arrange payment using the details below.",
        default => "This is a friendly reminder that invoice AZ-{$invoiceNumber} has an outstanding balance. Kindly arrange payment by the due date shown below.",
    };
    $message = trim($customMessage) !== '' ? trim($customMessage) : $defaultMessage;

    $bankBlock = '';
    if (trim((string) ($invoice['bank_name'] ?? '')) !== '') {
        $bankBlock = '<div style="margin-top:22px;padding:18px;border:1px solid #dce7ea;border-radius:12px;background:#f8fbfc;">'
            . '<div style="font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#10a7a2;margin-bottom:12px;">Payment details</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:13px;color:#4b6175;">'
            . '<tr><td style="padding:5px 0;font-weight:700;color:#24384b;">Account name</td><td style="padding:5px 0;text-align:right;">' . invoiceEmailEscape($invoice['account_name'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:5px 0;font-weight:700;color:#24384b;">Account number</td><td style="padding:5px 0;text-align:right;">' . invoiceEmailEscape($invoice['account_number'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:5px 0;font-weight:700;color:#24384b;">Bank</td><td style="padding:5px 0;text-align:right;">' . invoiceEmailEscape($invoice['bank_name'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:5px 0;font-weight:700;color:#24384b;">Currency</td><td style="padding:5px 0;text-align:right;">' . invoiceEmailEscape($invoice['account_currency'] ?? $currency) . '</td></tr>'
            . '</table></div>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#edf4f6;font-family:Arial,Helvetica,sans-serif;color:#1a2b3d;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#edf4f6;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border:1px solid #dce7ea;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(28,61,75,.10);">'
        . '<tr><td style="padding:26px 30px;background:#081827;">'
        . '<table role="presentation" width="100%"><tr><td>'
        . '<div style="font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:#22c9c2;">Smartbooks Accounting</div>'
        . '<div style="margin-top:7px;font-size:22px;font-weight:800;color:#ffffff;">' . invoiceEmailEscape($companyName) . '</div>'
        . '</td><td align="right"><div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#0b3f46;color:#65e6df;font-size:12px;font-weight:800;">Payment reminder</div></td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:30px;">'
        . '<div style="font-size:15px;color:#60758a;margin-bottom:8px;">Hello ' . invoiceEmailEscape($clientName) . ',</div>'
        . '<h1 style="margin:0 0 10px;font-size:28px;line-height:1.2;color:#102337;">Invoice payment reminder</h1>'
        . '<p style="margin:0 0 22px;color:#60758a;font-size:14px;line-height:1.65;">' . nl2br(invoiceEmailEscape($message)) . '</p>'
        . '<div style="padding:20px;border:1px solid #dce7ea;border-radius:14px;background:#f7fafb;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">'
        . '<tr><td style="padding:7px 0;color:#60758a;font-size:13px;">Invoice</td><td style="padding:7px 0;text-align:right;font-weight:800;color:#172d42;">AZ-' . invoiceEmailEscape($invoiceNumber) . '</td></tr>'
        . '<tr><td style="padding:7px 0;color:#60758a;font-size:13px;">Invoice date</td><td style="padding:7px 0;text-align:right;font-weight:700;color:#172d42;">' . invoiceEmailDate($invoice['invoice_date'] ?? null) . '</td></tr>'
        . '<tr><td style="padding:7px 0;color:#60758a;font-size:13px;">Due date</td><td style="padding:7px 0;text-align:right;font-weight:700;color:#172d42;">' . invoiceEmailDate($invoice['due_date'] ?? null) . '</td></tr>'
        . '<tr><td style="padding:7px 0;color:#60758a;font-size:13px;">Invoice total</td><td style="padding:7px 0;text-align:right;font-weight:700;color:#172d42;">' . invoiceEmailMoney($invoiceTotal, $currency) . '</td></tr>'
        . '<tr><td style="padding:14px 0 4px;border-top:1px solid #dce7ea;color:#172d42;font-size:15px;font-weight:800;">Outstanding balance</td><td style="padding:14px 0 4px;border-top:1px solid #dce7ea;text-align:right;color:#0a8f88;font-size:21px;font-weight:900;">' . invoiceEmailMoney($balance, $currency) . '</td></tr>'
        . '</table></div>'
        . $bankBlock
        . '<p style="margin:24px 0 0;color:#60758a;font-size:13px;line-height:1.65;">Please disregard this message if payment has already been made. You may reply to this email with the payment reference or any question.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 30px;background:#f4f8fa;border-top:1px solid #dce7ea;color:#7890a3;font-size:12px;line-height:1.6;">'
        . invoiceEmailEscape($company['office_address'] ?? '') . '<br>'
        . invoiceEmailEscape($company['email'] ?? envString('MAIL_FROM_ADDRESS')) . ' · ' . invoiceEmailEscape($company['tel'] ?? '')
        . '</td></tr></table></td></tr></table></body></html>';

    $text = "Hello {$clientName},\n\n"
        . $message . "\n\n"
        . "Invoice: AZ-{$invoiceNumber}\n"
        . "Invoice date: " . invoiceEmailDate($invoice['invoice_date'] ?? null) . "\n"
        . "Due date: " . invoiceEmailDate($invoice['due_date'] ?? null) . "\n"
        . "Invoice total: {$currency} " . number_format($invoiceTotal, 2) . "\n"
        . "Outstanding balance: {$currency} " . number_format($balance, 2) . "\n\n"
        . "Please disregard this reminder if payment has already been made.";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
