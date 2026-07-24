<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/accounting_period_helpers.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';

$journalIdLockHeld = false;
try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can reverse a fiscal-year close.');

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }
    $closureId = (int) ($data['closure_id'] ?? 0);
    $previewToken = trim((string) ($data['preview_token'] ?? ''));
    $reason = trim((string) ($data['reason'] ?? $data['reversal_reason'] ?? ''));
    if ($closureId <= 0) {
        throw new RuntimeException('Select a valid fiscal-year closure.', 422);
    }
    if ($previewToken === '') {
        throw new RuntimeException('Preview and confirm the fiscal-year close reversal first.', 428);
    }
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
        throw new RuntimeException('Enter a reversal reason between 5 and 500 characters.', 422);
    }

    $actorId = (int) ($user['id'] ?? 0);
    $actorEmail = trim((string) ($user['email'] ?? 'system'));

    $lockStmt = $conn->prepare("SELECT GET_LOCK('smartbooks_journal_id_generation', 10) AS acquired");
    $lockStmt->execute();
    $journalIdLockHeld = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    $lockStmt->close();
    if (!$journalIdLockHeld) {
        throw new RuntimeException('Unable to reserve a journal number. Please try again.', 503);
    }

    $conn->begin_transaction();
    try {
        $preview = smartbooksBuildFiscalYearCloseReversalPreview($conn, $closureId, true);
        if (!hash_equals((string) $preview['preview_token'], $previewToken)) {
            throw new RuntimeException('The fiscal-year closure changed after the preview. Generate a new reversal preview.', 409);
        }
        if (!$preview['can_reverse']) {
            throw new RuntimeException('This fiscal-year closure cannot be reversed.', 409);
        }

        $closure = $preview['closure'];
        $reversalDate = smartbooksPeriodValidateDate((string) $preview['reversal_date'], 'reversal date');
        $journalId = smartbooksNextJournalId($conn);
        $rate = smartbooksPeriodRateSnapshot($conn, $reversalDate);
        $journalType = 'Journal';
        $transactionType = 'Year End Closing Reversal';
        $journalCurrency = 'NGN';
        $costCenter = 'Overhead';
        $rateValue = 1.0;
        $description = "Reversal of fiscal-year closure {$closure['closure_code']}: {$reason}";
        $totalDebit = round((float) $preview['total_debit_ngn'], 2);
        $totalCredit = round((float) $preview['total_credit_ngn'], 2);
        if (abs($totalDebit - $totalCredit) > 0.01 || $totalDebit <= 0) {
            throw new RuntimeException('The fiscal-year closing reversal is not valid or balanced.', 409);
        }

        $header = $conn->prepare(
            'INSERT INTO journal_table
             (journal_id, journal_type, transaction_type, journal_date, journal_currency,
              journal_description, debit, credit, rate_date, rate, debit_ngn, credit_ngn,
              debit_others, credit_others, cost_center, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)'
        );
        $header->bind_param(
            'isssssddsdddsss',
            $journalId,
            $journalType,
            $transactionType,
            $reversalDate,
            $journalCurrency,
            $description,
            $totalDebit,
            $totalCredit,
            $rate['rate_date'],
            $rateValue,
            $totalDebit,
            $totalCredit,
            $costCenter,
            $actorEmail,
            $actorEmail
        );
        $header->execute();
        $header->close();

        $lineStmt = $conn->prepare(
            'INSERT INTO main_journal_table
             (journal_id, journal_type, transaction_type, journal_date, journal_currency,
              journal_description, debit, credit, rate_date, rate, debit_ngn, credit_ngn,
              ngn_rate, usd_rate, eur_rate, gbp_rate, cost_center,
              ledger_name, ledger_number, ledger_class, ledger_class_code,
              ledger_sub_class, ledger_type, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($preview['journal_lines'] as $line) {
            $debit = round((float) $line['debit_ngn'], 2);
            $credit = round((float) $line['credit_ngn'], 2);
            $ledgerName = (string) $line['ledger_name'];
            $ledgerNumber = (int) $line['ledger_number'];
            $ledgerClass = (string) $line['ledger_class'];
            $ledgerClassCode = (int) $line['ledger_class_code'];
            $ledgerSubClass = (string) $line['ledger_sub_class'];
            $ledgerType = (string) $line['ledger_type'];
            $lineStmt->bind_param(
                'isssssddsdddddddssisissss',
                $journalId,
                $journalType,
                $transactionType,
                $reversalDate,
                $journalCurrency,
                $description,
                $debit,
                $credit,
                $rate['rate_date'],
                $rateValue,
                $debit,
                $credit,
                $rate['ngn_rate'],
                $rate['usd_rate'],
                $rate['eur_rate'],
                $rate['gbp_rate'],
                $costCenter,
                $ledgerName,
                $ledgerNumber,
                $ledgerClass,
                $ledgerClassCode,
                $ledgerSubClass,
                $ledgerType,
                $actorEmail,
                $actorEmail
            );
            $lineStmt->execute();
        }
        $lineStmt->close();

        $status = 'Reversed';
        $update = $conn->prepare(
            'UPDATE fiscal_year_closures
             SET status = ?, reversal_journal_id = ?, reversal_reason = ?,
                 reversed_by_user_id = ?, reversed_by_email = ?, reversed_at = NOW()
             WHERE id = ? AND status = \'Posted\' AND reversal_journal_id IS NULL'
        );
        $update->bind_param('sisisi', $status, $journalId, $reason, $actorId, $actorEmail, $closureId);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('The fiscal-year closure was already changed by another user.', 409);
        }
        $update->close();

        $action = "{$actorEmail} reversed fiscal-year closure {$closure['closure_code']} with Journal #{$journalId}. Reason: {$reason}";
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actorId, $action, $actorEmail);
        $log->execute();
        $log->close();

        notifyAccountingUsers(
            $conn,
            'fiscal_year_close_reversed',
            'accounting_period',
            "Fiscal-year closure {$closure['closure_code']} was reversed",
            "{$actorEmail} posted reversal Journal #{$journalId}. Reason: {$reason}",
            'warning',
            'fiscal_year_closure',
            $closureId,
            '/lock-period/home',
            ['closure_code' => $closure['closure_code'], 'reversal_journal_id' => $journalId, 'reason' => $reason],
            $actorId
        );

        $conn->commit();
        $release = $conn->prepare("SELECT RELEASE_LOCK('smartbooks_journal_id_generation')");
        $release->execute();
        $release->close();
        $journalIdLockHeld = false;

        jsonResponse([
            'status' => 'Success',
            'message' => 'Fiscal-year closure reversed successfully. The affected accounting periods may now be unlocked through the controlled unlock action.',
            'data' => [
                'closure_id' => $closureId,
                'closure_code' => (string) $closure['closure_code'],
                'reversal_journal_id' => $journalId,
                'reversal_date' => $reversalDate,
            ],
        ]);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
} catch (Throwable $exception) {
    if ($journalIdLockHeld ?? false) {
        try {
            $release = $conn->prepare("SELECT RELEASE_LOCK('smartbooks_journal_id_generation')");
            $release->execute();
            $release->close();
        } catch (Throwable) {
        }
    }
    error_log('[Smartbooks FiscalYearClose/Reverse] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
