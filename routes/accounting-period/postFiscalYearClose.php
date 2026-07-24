<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../utils/accounting_period_helpers.php';
require_once __DIR__ . '/../../utils/fx_helpers.php';
require_once __DIR__ . '/../../utils/notification_helpers.php';

$journalIdLockHeld = false;
try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }
    $user = authenticateUser();
    requireRole($user, [SMARTBOOKS_ROLE_ADMIN, SMARTBOOKS_ROLE_CONTROLLER], 'Only Admin or Controller users can post a fiscal-year close.');

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }
    $startDate = smartbooksPeriodValidateDate((string) ($data['period_start'] ?? $data['start_date'] ?? ''), 'fiscal-year start date');
    $endDate = smartbooksPeriodValidateDate((string) ($data['period_end'] ?? $data['end_date'] ?? ''), 'fiscal-year end date');
    $retainedLedger = (int) ($data['retained_earnings_ledger_number'] ?? SMARTBOOKS_RETAINED_EARNINGS_LEDGER);
    $previewToken = trim((string) ($data['preview_token'] ?? ''));
    $description = trim((string) ($data['journal_description'] ?? "Year-end closing transfer to Retained Earnings for {$startDate} to {$endDate}"));
    if ($previewToken === '') {
        throw new RuntimeException('Preview and confirm the fiscal-year closing journal before posting.', 428);
    }
    if (mb_strlen($description) < 5 || mb_strlen($description) > 1000) {
        throw new RuntimeException('Journal description must be between 5 and 1000 characters.', 422);
    }

    smartbooksRequirePeriodSchema($conn);
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
        $preview = smartbooksBuildFiscalYearClosePreview($conn, $startDate, $endDate, $retainedLedger, true);
        if (!hash_equals((string) $preview['preview_token'], $previewToken)) {
            throw new RuntimeException('The fiscal-year balances changed after the preview. Generate a new preview.', 409);
        }
        if (!$preview['can_post']) {
            throw new RuntimeException('Resolve the fiscal-year close preview blockers before posting.', 409);
        }

        $journalId = smartbooksNextJournalId($conn);
        $closingDate = $endDate;
        $rate = smartbooksPeriodRateSnapshot($conn, $closingDate);
        $journalType = 'Journal';
        $transactionType = 'Year End Closing';
        $journalCurrency = 'NGN';
        $costCenter = 'Overhead';
        $rateValue = 1.0;
        $totalDebit = round((float) $preview['total_debit_ngn'], 2);
        $totalCredit = round((float) $preview['total_credit_ngn'], 2);
        if (abs($totalDebit - $totalCredit) > 0.01 || $totalDebit <= 0) {
            throw new RuntimeException('The fiscal-year closing journal is not valid or balanced.', 409);
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
            $closingDate,
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

        $insertedLines = [];
        foreach ($preview['journal_lines'] as $line) {
            $debit = round((float) ($line['debit_ngn'] ?? 0), 2);
            $credit = round((float) ($line['credit_ngn'] ?? 0), 2);
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
                $closingDate,
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
            $line['journal_line_id'] = (int) $conn->insert_id;
            $insertedLines[] = $line;
        }
        $lineStmt->close();

        $closureCode = 'FYC-' . str_replace('-', '', $endDate) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $status = 'Posted';
        $netProfitLoss = round((float) $preview['net_profit_loss_ngn'], 2);
        $closureStmt = $conn->prepare(
            'INSERT INTO fiscal_year_closures
             (closure_code, period_start, period_end, closing_date, retained_earnings_ledger_number,
              net_profit_loss_ngn, total_debit_ngn, total_credit_ngn, journal_id,
              journal_description, status, posted_by_user_id, posted_by_email)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $closureStmt->bind_param(
            'ssssidddissis',
            $closureCode,
            $startDate,
            $endDate,
            $closingDate,
            $retainedLedger,
            $netProfitLoss,
            $totalDebit,
            $totalCredit,
            $journalId,
            $description,
            $status,
            $actorId,
            $actorEmail
        );
        $closureStmt->execute();
        $closureId = (int) $conn->insert_id;
        $closureStmt->close();

        $closureLineStmt = $conn->prepare(
            'INSERT INTO fiscal_year_closure_lines
             (closure_id, ledger_name, ledger_number, ledger_class, ledger_class_code,
              ledger_sub_class, ledger_type, balance_before_close_ngn, debit_ngn, credit_ngn, journal_line_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($insertedLines as $line) {
            $ledgerName = (string) $line['ledger_name'];
            $ledgerNumber = (int) $line['ledger_number'];
            $ledgerClass = (string) $line['ledger_class'];
            $ledgerClassCode = (int) $line['ledger_class_code'];
            $ledgerSubClass = (string) $line['ledger_sub_class'];
            $ledgerType = (string) $line['ledger_type'];
            $balanceBefore = round((float) ($line['balance_before_close_ngn'] ?? 0), 2);
            $debit = round((float) ($line['debit_ngn'] ?? 0), 2);
            $credit = round((float) ($line['credit_ngn'] ?? 0), 2);
            $journalLineId = (int) $line['journal_line_id'];
            $closureLineStmt->bind_param(
                'isisissdddi',
                $closureId,
                $ledgerName,
                $ledgerNumber,
                $ledgerClass,
                $ledgerClassCode,
                $ledgerSubClass,
                $ledgerType,
                $balanceBefore,
                $debit,
                $credit,
                $journalLineId
            );
            $closureLineStmt->execute();
        }
        $closureLineStmt->close();

        $action = "{$actorEmail} posted fiscal-year closure {$closureCode} for {$startDate} to {$endDate} as Journal #{$journalId}";
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actorId, $action, $actorEmail);
        $log->execute();
        $log->close();

        $resultLabel = $netProfitLoss > 0.009
            ? 'profit'
            : ($netProfitLoss < -0.009 ? 'loss' : 'break-even result');
        notifyAccountingUsers(
            $conn,
            'fiscal_year_closed',
            'accounting_period',
            "Fiscal year {$startDate} to {$endDate} was closed",
            "{$actorEmail} posted closing Journal #{$journalId}. Net {$resultLabel}: NGN " . number_format(abs($netProfitLoss), 2) . '.',
            'warning',
            'fiscal_year_closure',
            $closureId,
            '/lock-period/home',
            ['closure_code' => $closureCode, 'journal_id' => $journalId, 'net_profit_loss_ngn' => $netProfitLoss],
            $actorId
        );

        $conn->commit();
        $release = $conn->prepare("SELECT RELEASE_LOCK('smartbooks_journal_id_generation')");
        $release->execute();
        $release->close();
        $journalIdLockHeld = false;

        jsonResponse([
            'status' => 'Success',
            'message' => abs($netProfitLoss) > 0.009
                ? 'Fiscal year closed successfully and the net result was transferred to Retained Earnings.'
                : 'Fiscal year closed successfully. No Retained Earnings transfer was required because the net result was break-even.',
            'data' => [
                'closure_id' => $closureId,
                'closure_code' => $closureCode,
                'journal_id' => $journalId,
                'period_start' => $startDate,
                'period_end' => $endDate,
                'net_profit_loss_ngn' => $netProfitLoss,
                'total_debit_ngn' => $totalDebit,
                'total_credit_ngn' => $totalCredit,
            ],
        ], 201);
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
    error_log('[Smartbooks FiscalYearClose/Post] ' . $exception->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => publicErrorMessage($exception)], publicErrorStatus($exception));
}
