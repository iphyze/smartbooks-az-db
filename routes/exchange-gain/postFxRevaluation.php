<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/fx_helpers.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $integrity = (string) ($userData['integrity'] ?? '');
    $userEmail = trim((string) ($userData['email'] ?? $userData['username'] ?? 'system'));
    if (!in_array($integrity, ['Admin', 'Controller'], true)) {
        throw new RuntimeException('Only Admin or Controller users can post FX revaluations.', 403);
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    $dateFrom = smartbooksFxValidateDate((string) ($body['datefrom'] ?? ''), 'start date');
    $dateTo = smartbooksFxValidateDate((string) ($body['dateto'] ?? ''), 'end date');
    if ($dateFrom > $dateTo) {
        throw new RuntimeException('The start date cannot be later than the end date.', 422);
    }

    $currency = smartbooksFxNormaliseCurrency((string) ($body['currency'] ?? ''));
    $journalDate = smartbooksFxValidateDate((string) ($body['journal_date'] ?? ''), 'journal date');
    if ($journalDate !== $dateTo) {
        throw new RuntimeException('The FX revaluation journal date must match the closing date.', 422);
    }

    $description = trim((string) ($body['journal_description'] ?? ''));
    if ($description === '') {
        throw new RuntimeException('Journal description is required.', 422);
    }
    if (mb_strlen($description) > 1000) {
        throw new RuntimeException('Journal description cannot exceed 1,000 characters.', 422);
    }
    $costCenter = trim((string) ($body['cost_center'] ?? ''));
    if (mb_strlen($costCenter) > 255) {
        throw new RuntimeException('Cost centre cannot exceed 255 characters.', 422);
    }

    smartbooksFxRequireSchema($conn);
    $conn->begin_transaction();
    smartbooksFxAssertPostingDateOpen($conn, $journalDate);

    $requestedRateIdRaw = trim((string) ($body['rate_id'] ?? ''));
    if ($requestedRateIdRaw !== '' && !ctype_digit($requestedRateIdRaw)) {
        throw new RuntimeException('Select a valid closing-rate record.', 422);
    }
    $requestedRateId = $requestedRateIdRaw !== '' ? (int) $requestedRateIdRaw : 0;
    $requestedRateDate = trim((string) ($body['rate_date'] ?? ''));
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

    $gainLedger = smartbooksFxRequiredLedger(
        $conn,
        SMARTBOOKS_FX_UNREALIZED_GAIN_LEDGER,
        'Exchange Gain',
        'Revenue'
    );
    $lossLedger = smartbooksFxRequiredLedger(
        $conn,
        SMARTBOOKS_FX_UNREALIZED_LOSS_LEDGER,
        'Exchange Loss',
        'Expense'
    );

    $batchLockStmt = $conn->prepare(
        'SELECT id
         FROM fx_revaluation_batches
         WHERE currency = ? AND date_to = ?
         FOR UPDATE'
    );
    if (!$batchLockStmt) {
        throw new RuntimeException('Unable to lock the FX closing period.', 500);
    }
    $batchLockStmt->bind_param('ss', $currency, $dateTo);
    $batchLockStmt->execute();
    $batchLockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $batchLockStmt->close();

    $activeBatch = smartbooksFxActiveBatchForClosingDate($conn, $currency, $dateTo);
    if ($activeBatch) {
        if (!empty($activeBatch['reversal_journal_id'])) {
            throw new RuntimeException(
                "FX revaluation journal {$activeBatch['journal_id']} was reversed on {$activeBatch['reversal_date']}, after the {$dateTo} closing date. It therefore remains part of the historical {$dateTo} balance and cannot be replaced by another journal dated {$dateTo}. Use a later closing date, or reverse on the original closing date before posting a same-date replacement.",
                409
            );
        }
        throw new RuntimeException(
            "An active {$currency} revaluation already exists for {$dateTo} under journal {$activeBatch['journal_id']}. Reverse it on the same closing date before posting a replacement.",
            409
        );
    }

    $preview = smartbooksFxBuildRevaluationPreview(
        $conn,
        $dateFrom,
        $dateTo,
        $currency,
        $rateData
    );

    $submittedToken = trim((string) ($body['preview_token'] ?? ''));
    if ($submittedToken === '') {
        throw new RuntimeException(
            'Preview and confirm the FX revaluation journal before posting.',
            428
        );
    }
    if (!hash_equals($preview['preview_token'], $submittedToken)) {
        throw new RuntimeException(
            'The ledger balances or exchange rate changed after the preview was generated. Refresh the preview before posting.',
            409
        );
    }

    $pending = $preview['pending_journals'];
    if (empty($pending)) {
        $conn->rollback();
        http_response_code(200);
        echo json_encode([
            'status' => 'Success',
            'message' => 'No FX differences were found. No journal was posted.',
            'posted' => 0,
            'summary' => [
                'total_gain_ngn' => 0.0,
                'total_loss_ngn' => 0.0,
                'net_fx_ngn' => 0.0,
                'net_label' => 'Net Exchange Gain',
            ],
        ], JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    $totalGain = (float) $preview['summary']['grand_total_gain'];
    $totalLoss = (float) $preview['summary']['grand_total_loss'];
    $netFx = (float) $preview['summary']['grand_total_net'];
    $totalDebit = round($totalGain + $totalLoss, 2);
    $totalCredit = $totalDebit;

    if (abs($totalDebit - $totalCredit) > 0.009) {
        throw new RuntimeException('The generated FX journal is not balanced.', 500);
    }

    $journalId = smartbooksFxNextJournalId($conn);
    smartbooksFxInsertJournalHeader(
        $conn,
        $journalId,
        'FX Revaluation',
        $journalDate,
        'NGN',
        $description,
        $totalDebit,
        $totalCredit,
        (string) $rateData['rate_date'],
        1.0,
        0.0,
        0.0,
        $costCenter,
        $userEmail
    );

    $batchCode = sprintf(
        'FXR-%s-%s-%s',
        $currency,
        str_replace('-', '', $dateTo),
        strtoupper(bin2hex(random_bytes(4)))
    );

    $batchStmt = $conn->prepare(
        "INSERT INTO fx_revaluation_batches
            (batch_code, currency, date_from, date_to, closing_rate_date, closing_rate,
             closing_rate_id, closing_rate_recorded_at, closing_rate_recorded_by,
             closing_rate_source, closing_rate_source_reference,
             journal_date, journal_id, status, journal_description, cost_center,
             total_gain_ngn, total_loss_ngn, net_fx_ngn, posted_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Posted', ?, ?, ?, ?, ?, ?)"
    );
    if (!$batchStmt) {
        throw new RuntimeException('Unable to prepare the FX revaluation batch. Apply the historical closing-rate migration first.', 503);
    }
    $rateDate = (string) $rateData['effective_date'];
    $closingRate = (float) $rateData['closing_rate'];
    $closingRateId = (int) $rateData['id'];
    $rateRecordedAt = $rateData['recorded_at'];
    $rateRecordedBy = $rateData['recorded_by'];
    $rateSource = (string) $rateData['rate_source'];
    $rateSourceReference = $rateData['source_reference'];
    $batchStmt->bind_param(
        'sssssdisssssissddds',
        $batchCode,
        $currency,
        $dateFrom,
        $dateTo,
        $rateDate,
        $closingRate,
        $closingRateId,
        $rateRecordedAt,
        $rateRecordedBy,
        $rateSource,
        $rateSourceReference,
        $journalDate,
        $journalId,
        $description,
        $costCenter,
        $totalGain,
        $totalLoss,
        $netFx,
        $userEmail
    );
    $batchStmt->execute();
    $batchId = (int) $batchStmt->insert_id;
    $batchStmt->close();

    $lineMetaStmt = $conn->prepare(
        'INSERT INTO fx_revaluation_lines
            (batch_id, ledger_name, ledger_number, ledger_class, ledger_class_code,
             ledger_sub_class, ledger_type, is_asset, fcy_balance,
             base_carrying_ngn, prior_revaluation_adjustment_ngn, carrying_before_ngn,
             closing_value_ngn, adjustment_ngn, gain_loss, contra_ledger_number,
             ledger_journal_line_id, contra_journal_line_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$lineMetaStmt) {
        throw new RuntimeException('Unable to prepare the FX revaluation detail.', 500);
    }

    $postedLineCount = 0;
    foreach ($pending as $item) {
        $adjustment = round((float) $item['fx_difference'], 2);
        if (abs($adjustment) < 0.01) {
            continue;
        }

        $revaluedLedger = [
            'ledger_name' => (string) $item['ledger_name'],
            'ledger_number' => (int) $item['ledger_number'],
            'ledger_class' => (string) $item['ledger_class'],
            'ledger_class_code' => (int) $item['ledger_class_code'],
            'ledger_sub_class' => (string) $item['ledger_sub_class'],
            'ledger_type' => (string) $item['ledger_type'],
        ];
        $contraLedger = $adjustment > 0 ? $gainLedger : $lossLedger;
        $amount = abs($adjustment);
        $ledgerDebit = $adjustment > 0 ? $amount : 0.0;
        $ledgerCredit = $adjustment < 0 ? $amount : 0.0;
        $contraDebit = $adjustment < 0 ? $amount : 0.0;
        $contraCredit = $adjustment > 0 ? $amount : 0.0;

        $ledgerLineId = smartbooksFxInsertJournalLine(
            $conn,
            $journalId,
            'FX Revaluation',
            $journalDate,
            'NGN',
            $description,
            $ledgerDebit,
            $ledgerCredit,
            $rateDate,
            1.0,
            $ledgerDebit,
            $ledgerCredit,
            $rateData,
            $costCenter,
            $revaluedLedger,
            $userEmail
        );
        $contraLineId = smartbooksFxInsertJournalLine(
            $conn,
            $journalId,
            'FX Revaluation',
            $journalDate,
            'NGN',
            $description,
            $contraDebit,
            $contraCredit,
            $rateDate,
            1.0,
            $contraDebit,
            $contraCredit,
            $rateData,
            $costCenter,
            $contraLedger,
            $userEmail
        );

        $ledgerName = (string) $item['ledger_name'];
        $ledgerNumber = (int) $item['ledger_number'];
        $ledgerClass = (string) $item['ledger_class'];
        $ledgerClassCode = (int) $item['ledger_class_code'];
        $ledgerSubClass = (string) $item['ledger_sub_class'];
        $ledgerType = (string) $item['ledger_type'];
        $isAsset = !empty($item['is_asset']) ? 1 : 0;
        $fcyBalance = (float) $item['fcy_net'];
        $baseCarrying = (float) $item['base_carrying_ngn'];
        $priorAdjustment = (float) $item['prior_revaluation_adjustment_ngn'];
        $carryingBefore = (float) $item['current_carrying_ngn'];
        $closingValue = (float) $item['closing_value_ngn'];
        $gainLoss = $adjustment > 0 ? 'Gain' : 'Loss';
        $contraLedgerNumber = (int) $contraLedger['ledger_number'];

        $lineMetaStmt->bind_param(
            'isisissiddddddsiii',
            $batchId,
            $ledgerName,
            $ledgerNumber,
            $ledgerClass,
            $ledgerClassCode,
            $ledgerSubClass,
            $ledgerType,
            $isAsset,
            $fcyBalance,
            $baseCarrying,
            $priorAdjustment,
            $carryingBefore,
            $closingValue,
            $adjustment,
            $gainLoss,
            $contraLedgerNumber,
            $ledgerLineId,
            $contraLineId
        );
        $lineMetaStmt->execute();
        $postedLineCount += 2;
    }
    $lineMetaStmt->close();

    $conn->commit();

    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX revaluation journal posted successfully.',
        'journal_id' => $journalId,
        'batch_id' => $batchId,
        'batch_code' => $batchCode,
        'posted' => $postedLineCount,
        'summary' => [
            'total_gain_ngn' => round($totalGain, 2),
            'total_loss_ngn' => round($totalLoss, 2),
            'net_fx_ngn' => round($netFx, 2),
            'net_label' => $netFx >= 0 ? 'Net Exchange Gain' : 'Net Exchange Loss',
            'gain_posted_to' => $totalGain > 0
                ? SMARTBOOKS_FX_UNREALIZED_GAIN_LEDGER . ' — ' . $gainLedger['ledger_name']
                : null,
            'loss_posted_to' => $totalLoss > 0
                ? SMARTBOOKS_FX_UNREALIZED_LOSS_LEDGER . ' — ' . $lossLedger['ledger_name']
                : null,
            'fx_type' => 'unrealized',
        ],
        'closing_rate_info' => [
            'rate_id' => $closingRateId,
            'currency' => $currency,
            'closing_rate' => $closingRate,
            'effective_date' => $rateDate,
            'rate_record_date' => !empty($rateRecordedAt) ? substr((string) $rateRecordedAt, 0, 10) : null,
            'recorded_at' => $rateRecordedAt,
            'recorded_by' => $rateRecordedBy,
            'rate_source' => $rateSource,
            'source_reference' => $rateSourceReference,
            'entered_after_effective_date' => (bool) $rateData['entered_after_effective_date'],
        ],
        'meta' => [
            'currency' => $currency,
            'journal_date' => $journalDate,
            'datefrom' => $dateFrom,
            'dateto' => $dateTo,
            'period_year' => (int) date('Y', strtotime($dateTo)),
            'rate_id' => $closingRateId,
            'rate_effective_date' => $rateDate,
            'rate_recorded_at' => $rateRecordedAt,
            'posted_by' => $userEmail,
            'preview_token' => $preview['preview_token'],
        ],
    ], JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log('FX Revaluation Post Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => publicErrorMessage($error),
    ]);
}
