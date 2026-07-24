<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/fx_helpers.php';
require_once 'utils/realized_fx_reporting_helpers.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $integrity = (string) ($userData['integrity'] ?? '');
    if (!in_array($integrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can preview FX revaluations.', 403);
    }

    $dateFrom = smartbooksFxValidateDate((string) ($_GET['datefrom'] ?? ''), 'start date');
    $dateTo = smartbooksFxValidateDate((string) ($_GET['dateto'] ?? ''), 'end date');
    if ($dateFrom > $dateTo) {
        throw new RuntimeException('The start date cannot be later than the end date.', 422);
    }

    $currency = smartbooksFxNormaliseCurrency((string) ($_GET['currency'] ?? ''));
    $requestedRateIdRaw = trim((string) ($_GET['rate_id'] ?? ''));
    if ($requestedRateIdRaw !== '' && !ctype_digit($requestedRateIdRaw)) {
        throw new RuntimeException('Select a valid closing-rate record.', 422);
    }
    $requestedRateId = $requestedRateIdRaw !== '' ? (int) $requestedRateIdRaw : 0;
    $requestedRateDate = trim((string) ($_GET['rate_date'] ?? ''));

    if ($requestedRateId > 0) {
        $rateData = smartbooksFxRateById($conn, $currency, $requestedRateId);
    } else {
        if ($requestedRateDate !== '') {
            $requestedRateDate = smartbooksFxValidateDate($requestedRateDate, 'rate effective date');
        }
        $rateData = smartbooksFxRateForDate(
            $conn,
            $currency,
            $requestedRateDate !== '' ? $requestedRateDate : $dateTo,
            $requestedRateDate !== ''
        );
    }

    if ((string) $rateData['effective_date'] > $dateTo) {
        throw new RuntimeException('The closing rate effective date cannot be later than the closing date.', 422);
    }

    $preview = smartbooksFxBuildRevaluationPreview(
        $conn,
        $dateFrom,
        $dateTo,
        $currency,
        $rateData
    );

    $lockedPeriod = smartbooksFxLockedPeriod($conn, $dateTo);
    $activeBatch = smartbooksFxActiveBatchForClosingDate($conn, $currency, $dateTo);
    $schemaReady = smartbooksFxSchemaReady($conn);
    $realizedSchemaReady = smartbooksRealizedFxSchemaReady($conn);
    $realizedReport = $realizedSchemaReady
        ? smartbooksRealizedFxBuildReport($conn, $dateFrom, $dateTo, $currency)
        : [
            'data' => [],
            'summary' => smartbooksRealizedFxEmptySummary($currency, $dateFrom, $dateTo),
            'pending_manual_journals' => [],
            'pending_manual_summary' => [
                'payment_count' => 0,
                'expected_gain_ngn' => 0.0,
                'expected_loss_ngn' => 0.0,
                'expected_net_ngn' => 0.0,
                'net_label' => 'No Unposted Realized FX',
                'included_in_realized_totals' => false,
            ],
            'warnings' => [],
            'meta' => [
                'currency' => $currency,
                'datefrom' => $dateFrom,
                'dateto' => $dateTo,
                'fx_type' => 'realized',
                'posting_scope' => 'read_only_report',
                'schema_ready' => false,
            ],
        ];
    $combinedSummary = smartbooksFxBuildCombinedSummary($realizedReport['summary'], $preview['summary']);

    $warnings = [];
    if ((string) $rateData['effective_date'] < $dateTo) {
        $warnings[] = "The selected closing rate is effective {$rateData['effective_date']}, before the {$dateTo} closing date. Confirm that this is the approved closing rate before posting.";
    }
    if (!$schemaReady) {
        $warnings[] = 'The FX migration is not installed. Preview is available, but posting and tracked reversals are disabled.';
    }
    if (!$realizedSchemaReady) {
        $warnings[] = 'Realized FX reporting is unavailable until the mixed-currency payment and manual journal-linking migrations are applied.';
    }
    foreach ($realizedReport['warnings'] as $realizedWarning) {
        $warnings[] = is_array($realizedWarning)
            ? (string) ($realizedWarning['message'] ?? 'Realized FX review note.')
            : (string) $realizedWarning;
    }
    if ($activeBatch) {
        if (!empty($activeBatch['reversal_journal_id'])) {
            $warnings[] = "FX journal {$activeBatch['journal_id']} was reversed on {$activeBatch['reversal_date']}, after this closing date. It remains in the historical {$dateTo} balance and cannot be replaced with another journal on the same date.";
        } else {
            $warnings[] = "An active {$currency} revaluation already exists for {$dateTo}. Reverse it on the same closing date before posting a replacement.";
        }
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX revaluation preview generated successfully.',
        // Existing keys remain the unrealized preview for backward compatibility.
        'data' => $preview['data'],
        'summary' => $preview['summary'],
        'pending_journals' => $preview['pending_journals'],
        'preview_token' => $preview['preview_token'],
        'unrealized' => [
            'data' => $preview['data'],
            'summary' => $preview['summary'],
            'pending_journals' => $preview['pending_journals'],
            'preview_token' => $preview['preview_token'],
            'posting_scope' => 'postable_preview',
        ],
        'realized' => [
            'data' => $realizedReport['data'],
            'summary' => $realizedReport['summary'],
            'pending_manual_journals' => $realizedReport['pending_manual_journals'],
            'pending_manual_summary' => $realizedReport['pending_manual_summary'],
            'posting_scope' => 'read_only_report',
        ],
        'realized_data' => $realizedReport['data'],
        'realized_summary' => $realizedReport['summary'],
        'unrealized_summary' => $preview['summary'],
        'combined_summary' => $combinedSummary,
        'closing_rate_info' => [
            'rate_id' => (int) $rateData['id'],
            'currency' => $currency,
            'closing_rate' => (float) $rateData['closing_rate'],
            'effective_date' => (string) $rateData['effective_date'],
            // Kept for compatibility with the earlier frontend response.
            'rate_record_date' => !empty($rateData['recorded_at'])
                ? substr((string) $rateData['recorded_at'], 0, 10)
                : null,
            'recorded_at' => $rateData['recorded_at'],
            'recorded_by' => $rateData['recorded_by'],
            'rate_source' => (string) $rateData['rate_source'],
            'source_reference' => $rateData['source_reference'],
            'entered_after_effective_date' => (bool) $rateData['entered_after_effective_date'],
            'is_exact_closing_date' => (string) $rateData['effective_date'] === $dateTo,
        ],
        'period_status' => [
            'is_locked' => $lockedPeriod !== null,
            'lock_reason' => $lockedPeriod['lock_reason'] ?? null,
            'already_posted' => $activeBatch !== null,
            'posted_journal_id' => $activeBatch ? (int) $activeBatch['journal_id'] : null,
            'batch_code' => $activeBatch['batch_code'] ?? null,
            'reversal_journal_id' => $activeBatch && $activeBatch['reversal_journal_id'] !== null
                ? (int) $activeBatch['reversal_journal_id']
                : null,
            'reversal_date' => $activeBatch['reversal_date'] ?? null,
            'can_reverse' => $activeBatch !== null && empty($activeBatch['reversal_journal_id']),
            'can_replace_on_closing_date' => $activeBatch === null,
        ],
        'meta' => [
            'currency' => $currency,
            'datefrom' => $dateFrom,
            'dateto' => $dateTo,
            'period_year' => (int) date('Y', strtotime($dateTo)),
            'rate_id' => (int) $rateData['id'],
            'rate_date' => (string) $rateData['effective_date'],
            'rate_effective_date' => (string) $rateData['effective_date'],
            'rate_recorded_at' => $rateData['recorded_at'],
            'posting_date' => $dateTo,
            'schema_ready' => $schemaReady,
            'realized_reporting_schema_ready' => $realizedSchemaReady,
            'fx_type' => 'unrealized',
            'includes_realized_report' => true,
            'posting_scope' => 'The preview token and post action apply only to unrealized FX.',
        ],
        'warnings' => $warnings,
    ], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    error_log('FX Revaluation Preview Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($error),
    ]);
}
