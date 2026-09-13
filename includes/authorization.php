<?php
declare(strict_types=1);

require_once __DIR__ . '/authMiddleware.php';

const SMARTBOOKS_ROLE_SUPER_ADMIN = 'Super Admin';
const SMARTBOOKS_ROLE_ADMIN = 'Admin';
const SMARTBOOKS_ROLE_CONTROLLER = 'Controller';
const SMARTBOOKS_ROLE_USER = 'User';
const SMARTBOOKS_ROLE_TIMESHEET = 'Timesheet';
const SMARTBOOKS_ALLOWED_ROLES = [
    SMARTBOOKS_ROLE_SUPER_ADMIN,
    SMARTBOOKS_ROLE_ADMIN,
    SMARTBOOKS_ROLE_CONTROLLER,
    SMARTBOOKS_ROLE_USER,
    SMARTBOOKS_ROLE_TIMESHEET,
];

function userRole(array $user): string
{
    return trim((string) ($user['integrity'] ?? ''));
}

function userHasRole(array $user, array $roles): bool
{
    if (rbacIsSuperAdmin($user)) {
        return true;
    }

    return in_array(userRole($user), $roles, true);
}

function requireRole(array $user, array $roles, string $message = 'You are not authorised to perform this action.'): void
{
    if (!userHasRole($user, $roles)) {
        throw new RuntimeException($message, 403);
    }
}

function isTimesheetOnlyUser(array $user): bool
{
    return userRole($user) === SMARTBOOKS_ROLE_TIMESHEET;
}

/**
 * Returns the staff profile explicitly linked to a Timesheet-only user.
 * A missing or invalid mapping must not silently fall back to an email/name lookup.
 */
function requireLinkedTimesheetStaff(mysqli $conn, array $user): array
{
    requireRole($user, [SMARTBOOKS_ROLE_TIMESHEET], 'A Timesheet staff profile is required.');

    $staffId = (int) ($user['staff_id'] ?? 0);
    if ($staffId <= 0) {
        throw new RuntimeException(
            'Your Timesheet account has not been linked to a staff profile. Please contact an Admin.',
            403
        );
    }

    $stmt = $conn->prepare(
        'SELECT id, staff_id, staff_name, staff_email
         FROM staff_table
         WHERE staff_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate staff access.', 500);
    }

    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff) {
        throw new RuntimeException(
            'Your linked staff profile is no longer available. Please contact an Admin.',
            403
        );
    }

    $staff['id'] = (int) $staff['id'];
    $staff['staff_id'] = (int) $staff['staff_id'];
    return $staff;
}

/**
 * Returns an ownership scope for Timesheet-only users, or null for every other permitted role.
 */
function timesheetStaffScope(mysqli $conn, array $user): ?array
{
    if (strtolower(trim((string) ($user['cost_center_access_mode'] ?? 'all'))) === 'restricted') {
        throw new RuntimeException('Timesheets are not yet cost-centre partitioned. This module requires All Cost Centres access.', 403);
    }

    return isTimesheetOnlyUser($user) ? requireLinkedTimesheetStaff($conn, $user) : null;
}

function assertTimesheetEntryAccess(mysqli $conn, array $user, int $entryId): void
{
    if (!isTimesheetOnlyUser($user)) {
        return;
    }

    $scope = requireLinkedTimesheetStaff($conn, $user);
    $staffId = (int) $scope['staff_id'];
    $stmt = $conn->prepare('SELECT id FROM timesheet_table WHERE id = ? AND staff_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to validate timesheet access.', 500);
    }
    $stmt->bind_param('ii', $entryId, $staffId);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$entry) {
        // Return not-found to avoid exposing another staff member's record existence.
        throw new RuntimeException('Timesheet entry not found.', 404);
    }
}

/**
 * Router-level deny-by-default RBAC enforcement. Endpoint-level checks remain in place
 * for object ownership, cost-centre scope and action-specific validation.
 */
function enforceApiRouteAccess(string $relativePath): void
{
    global $conn;

    $publicPaths = ['/', '/welcome', '/pwa/service-worker.js', '/auth/csrf', '/auth/bootstrap', '/auth/login'];
    if (in_array($relativePath, $publicPaths, true)) {
        return;
    }

    $user = authenticateUser();

    // Session introspection and logout remain available so an account with a retired role can exit safely.
    if (in_array($relativePath, ['/auth/me', '/auth/logout'], true)) {
        return;
    }

    if (!empty($user['must_change_password'])) {
        if ($relativePath === '/users/updateProfile') {
            return;
        }

        jsonResponse([
            'status' => 'Failed',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
            'message' => 'You must change your temporary password before accessing Smartbooks.'
        ], 403);
    }

    requireRole($user, SMARTBOOKS_ALLOWED_ROLES, 'Your account does not have an active Smartbooks role.');

    // RBAC Batch 11: notifications remain personal to the authenticated recipient,
    // but reading and mutation actions are permission-driven. Endpoint queries still
    // scope every operation to the current user's ID and existing cost-centre visibility.
    if (in_array($relativePath, ['/notifications/summary', '/notifications/list'], true)) {
        requirePermission($conn, $user, 'notification.view', 'You do not have permission to view notifications.');
        return;
    }

    if (in_array($relativePath, ['/notifications/mark-read', '/notifications/mark-all-read', '/notifications/mark-seen'], true)) {
        requirePermission($conn, $user, 'notification.view', 'You do not have permission to view notifications.');
        requirePermission($conn, $user, 'notification.mark_read', 'You do not have permission to update notification read status.');
        return;
    }

    if ($relativePath === '/notifications/dismiss') {
        requirePermission($conn, $user, 'notification.view', 'You do not have permission to view notifications.');
        requirePermission($conn, $user, 'notification.dismiss', 'You do not have permission to dismiss notifications.');
        return;
    }

    // RBAC Batch 10: Activity Logs are permission-driven. The endpoints also
    // require All Cost Centres because legacy audit rows cannot be partitioned
    // safely by division. Export requires view permission as well as export.
    if ($relativePath === '/activity-logs/list') {
        requirePermission($conn, $user, 'activity_log.view', 'You do not have permission to view activity logs.');
        return;
    }

    if ($relativePath === '/activity-logs/export') {
        requirePermission($conn, $user, 'activity_log.view', 'You do not have permission to view activity logs.');
        requirePermission($conn, $user, 'activity_log.export', 'You do not have permission to export activity logs.');
        return;
    }

    if ($relativePath === '/permissions/me') {
        return;
    }

    if ($relativePath === '/permissions/catalog') {
        requireAnyPermission(
            $conn,
            $user,
            ['permission.view', 'user.manage_permissions'],
            'You do not have permission to view the permission catalogue.'
        );
        return;
    }

    if ($relativePath === '/permissions/roles') {
        requireAnyPermission(
            $conn,
            $user,
            ['role.view', 'user.manage_permissions'],
            'You do not have permission to view RBAC roles.'
        );
        return;
    }

    if (in_array($relativePath, ['/permissions/user', '/permissions/update-user'], true)) {
        requirePermission(
            $conn,
            $user,
            'user.manage_permissions',
            'You do not have permission to manage user permissions.'
        );
        return;
    }

    if (str_starts_with($relativePath, '/permissions/')) {
        requirePermission(
            $conn,
            $user,
            'permission.manage',
            'You do not have permission to manage the permission system.'
        );
        return;
    }

    if ($relativePath === '/users/updateProfile') {
        return;
    }

    if ($relativePath === '/users/getSingleUser') {
        // The endpoint permits self-profile access and otherwise enforces user.view.
        return;
    }

    if ($relativePath === '/users/getFilteredRequest') {
        requirePermission($conn, $user, 'user.view', 'You do not have permission to view users.');
        return;
    }

    if ($relativePath === '/users/createUsers') {
        requirePermission($conn, $user, 'user.create', 'You do not have permission to create users.');
        return;
    }

    if ($relativePath === '/users/editUsers') {
        requirePermission($conn, $user, 'user.edit', 'You do not have permission to edit users.');
        return;
    }

    if ($relativePath === '/users/deleteUsers') {
        requirePermission($conn, $user, 'user.delete', 'You do not have permission to delete users.');
        return;
    }

    if (str_starts_with($relativePath, '/users/')) {
        requirePermission($conn, $user, 'user.view', 'You do not have permission to access user administration.');
        return;
    }

    // RBAC Batch 12: Dashboard, Exchange Rates and Staff master data.
    if (in_array($relativePath, ['/reports', '/reports/dashboard-analytics'], true)) {
        requirePermission($conn, $user, 'dashboard.view', 'You do not have permission to view the dashboard.');
        return;
    }

    $exchangeRatePermissionMap = [
        '/rate/filtered-request' => 'exchange_rate.view',
        '/rate/create-rate' => 'exchange_rate.create',
        '/rate/edit-rate' => 'exchange_rate.edit',
        '/rate/delete-rate' => 'exchange_rate.delete',
    ];
    if (isset($exchangeRatePermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $exchangeRatePermissionMap[$relativePath],
            'You do not have permission to perform this exchange-rate action.'
        );
        return;
    }

    if ($relativePath === '/rate/fetch-single-rate') {
        requireAnyPermission(
            $conn,
            $user,
            ['exchange_rate.view', 'exchange_rate.edit'],
            'You do not have permission to access this exchange-rate record.'
        );
        return;
    }

    $staffPermissionMap = [
        '/staff/filtered-request' => 'staff.view',
        '/staff/fetch-staff' => 'staff.view',
        '/staff/fetch-last-staff-id' => 'staff.create',
        '/staff/create-staff' => 'staff.create',
        '/staff/edit-staff' => 'staff.edit',
        '/staff/delete-staff' => 'staff.delete',
    ];
    if (isset($staffPermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $staffPermissionMap[$relativePath],
            'You do not have permission to perform this staff action.'
        );
        return;
    }

    if ($relativePath === '/staff/fetch-single-staff') {
        requireAnyPermission(
            $conn,
            $user,
            ['staff.view', 'staff.edit'],
            'You do not have permission to access this staff record.'
        );
        return;
    }

    // RBAC Batch 4: Invoice and receivables permissions are action-specific.
    $invoicePermissionMap = [
        '/invoice/filtered-request' => 'invoice.view',
        '/invoice/kpi-stats' => 'invoice.view',
        '/invoice/create-invoice' => 'invoice.create',
        '/invoice/edit-invoice' => 'invoice.edit',
        '/invoice/delete-invoice' => 'invoice.delete',
        '/invoice/delete-single-line' => 'invoice.edit',
        '/invoice/delete-single-invoice' => 'invoice.edit',
        '/invoice/duplicate-invoice' => 'invoice.duplicate',
        '/invoice/send-invoice' => 'invoice.send',
        '/invoice/change-workflow-status' => 'invoice.workflow',
        '/invoice/update-invoice' => 'invoice.workflow',
        '/invoice/record-payment' => 'invoice.payment_record',
        '/invoice/payment-journal-options' => 'invoice.payment_record',
        '/invoice/reverse-payment' => 'invoice.payment_reverse',
        '/invoice/activity' => 'invoice.view',
        '/invoice/create-reminder' => 'invoice.reminder_manage',
        '/invoice/cancel-reminder' => 'invoice.reminder_manage',
        '/invoice/create-service' => 'invoice.catalogue_manage',
        '/invoice/update-service' => 'invoice.catalogue_manage',
    ];

    if (isset($invoicePermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $invoicePermissionMap[$relativePath],
            'You do not have permission to perform this invoice action.'
        );
        return;
    }

    if ($relativePath === '/invoice/fetch-single-invoice') {
        requireAnyPermission(
            $conn,
            $user,
            ['invoice.view', 'invoice.edit'],
            'You do not have permission to access this invoice.'
        );
        return;
    }

    if (in_array($relativePath, ['/invoice/save-draft', '/invoice/get-draft', '/invoice/delete-draft'], true)) {
        requireAnyPermission(
            $conn,
            $user,
            ['invoice.create', 'invoice.edit'],
            'You do not have permission to manage invoice drafts.'
        );
        return;
    }

    if ($relativePath === '/invoice/service-catalogue') {
        requireAnyPermission(
            $conn,
            $user,
            ['invoice.create', 'invoice.edit', 'invoice.catalogue_manage'],
            'You do not have permission to load the invoice service catalogue.'
        );
        return;
    }

    if (in_array($relativePath, ['/invoice/client-preferences', '/invoice/save-client-preferences'], true)) {
        requireAnyPermission(
            $conn,
            $user,
            ['invoice.create', 'invoice.edit'],
            'You do not have permission to use invoice client defaults.'
        );
        return;
    }

    if (in_array(
        $relativePath,
        [
            '/invoice/manual-payment-journal-candidates',
            '/invoice/preview-manual-payment-journal-link',
            '/invoice/link-manual-payment-journal',
            '/invoice/unlink-manual-payment-journal',
        ],
        true
    )) {
        requirePermission(
            $conn,
            $user,
            'invoice.payment_record',
            'You do not have permission to manage invoice payments.'
        );
        requirePermission(
            $conn,
            $user,
            'journal.payment_link',
            'You do not have permission to manage invoice-payment journal links.'
        );
        return;
    }

    // Read-only master data required by the invoice builder. These allowances do
    // not grant create/edit access to the underlying master-data modules.
    if ($relativePath === '/clients/fetch-clients') {
        requireAnyPermission(
            $conn,
            $user,
            ['client.view', 'invoice.create', 'invoice.edit', 'journal.create', 'journal.edit', 'journal.import'],
            'You do not have permission to load client reference data.'
        );
        return;
    }

    if ($relativePath === '/projects/fetch-projects') {
        requireAnyPermission(
            $conn,
            $user,
            ['project.view', 'invoice.create', 'invoice.edit'],
            'You do not have permission to load project reference data.'
        );
        return;
    }

    if ($relativePath === '/bank/fetch-banks') {
        requireAnyPermission(
            $conn,
            $user,
            ['bank.view', 'invoice.create', 'invoice.edit', 'invoice.payment_record'],
            'You do not have permission to load bank reference data.'
        );
        return;
    }

    // Invoice builder quick-create actions remain governed by the permission
    // of the master-data module being changed. Only these exact dependencies
    // are opened here; the rest of each module is handled in its own RBAC batch.
    $invoiceBuilderCreatePermissionMap = [
        '/clients/create-clients' => 'client.create',
        '/clients/fetch-last-client-id' => 'client.create',
        '/projects/create-project' => 'project.create',
        '/projects/fetch-last-project-id' => 'project.create',
        '/bank/create-bank-details' => 'bank.create',
        '/rate/create-rate' => 'exchange_rate.create',
    ];

    if (isset($invoiceBuilderCreatePermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $invoiceBuilderCreatePermissionMap[$relativePath],
            'You do not have permission to create this reference record.'
        );
        return;
    }

    // RBAC Batch 5: Client and project master-data permissions are action-specific.
    $clientProjectPermissionMap = [
        '/clients/filtered-request' => 'client.view',
        '/clients/edit-clients' => 'client.edit',
        '/clients/delete-clients' => 'client.delete',
        '/projects/filtered-request' => 'project.view',
        '/projects/edit-project' => 'project.edit',
        '/projects/delete-project' => 'project.delete',
    ];

    if (isset($clientProjectPermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $clientProjectPermissionMap[$relativePath],
            'You do not have permission to perform this master-data action.'
        );
        return;
    }

    if ($relativePath === '/clients/fetch-single-client') {
        requireAnyPermission(
            $conn,
            $user,
            ['client.view', 'client.edit'],
            'You do not have permission to access this client.'
        );
        return;
    }

    if ($relativePath === '/projects/fetch-single-project') {
        requireAnyPermission(
            $conn,
            $user,
            ['project.view', 'project.edit'],
            'You do not have permission to access this project.'
        );
        return;
    }

    // RBAC Batch 6: Bank, account-type and ledger master-data permissions.
    // Reference-only fetches keep workflow dependencies separate from module access.
    if ($relativePath === '/accounting-type/fetch-account') {
        requireAnyPermission(
            $conn,
            $user,
            ['account.view', 'ledger.create', 'ledger.edit'],
            'You do not have permission to load account-type reference data.'
        );
        return;
    }

    $financeMasterPermissionMap = [
        '/bank/filtered-request' => 'bank.view',
        '/bank/edit-bank-details' => 'bank.edit',
        '/bank/delete-bank-details' => 'bank.delete',
        '/accounting-type/filtered-request' => 'account.view',
        '/accounting-type/create-account-type' => 'account.create',
        '/accounting-type/edit-account-type' => 'account.edit',
        '/accounting-type/delete-account-type' => 'account.delete',
        '/ledger/filtered-request' => 'ledger.view',
        '/ledger/create-ledger' => 'ledger.create',
        '/ledger/edit-ledger' => 'ledger.edit',
        '/ledger/delete-ledger' => 'ledger.delete',
        '/ledger/delete-single-ledger' => 'ledger.delete',
    ];

    if (isset($financeMasterPermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $financeMasterPermissionMap[$relativePath],
            'You do not have permission to perform this finance master-data action.'
        );
        return;
    }

    if ($relativePath === '/bank/fetch-single-bank') {
        requireAnyPermission(
            $conn,
            $user,
            ['bank.view', 'bank.edit'],
            'You do not have permission to access this bank account.'
        );
        return;
    }

    if ($relativePath === '/accounting-type/fetch-single-account') {
        requireAnyPermission(
            $conn,
            $user,
            ['account.view', 'account.edit'],
            'You do not have permission to access this account type.'
        );
        return;
    }

    if ($relativePath === '/ledger/fetch-single-ledger') {
        requireAnyPermission(
            $conn,
            $user,
            ['ledger.view', 'ledger.edit'],
            'You do not have permission to access this ledger.'
        );
        return;
    }

    if ($relativePath === '/bank-recon/get') {
        requireAnyPermission(
            $conn,
            $user,
            ['bank_reconciliation.view', 'bank_reconciliation.edit'],
            'You do not have permission to load this bank reconciliation.'
        );
        return;
    }

    // RBAC Batch 9: Accounting Period / Fiscal Close permissions.
    // The shared update endpoint is resolved more precisely inside the endpoint
    // because it can perform either a normal edit or a lock/unlock action.
    if ($relativePath === '/accounting-period/periods') {
        requireAnyPermission(
            $conn,
            $user,
            ['accounting_period.view', 'accounting_period.edit', 'accounting_period.lock', 'accounting_period.close'],
            'You do not have permission to access accounting periods.'
        );
        return;
    }

    if ($relativePath === '/accounting-period/create-period') {
        requirePermission($conn, $user, 'accounting_period.create', 'You do not have permission to create accounting periods.');
        return;
    }

    if ($relativePath === '/accounting-period/preview-lock-period') {
        requirePermission($conn, $user, 'accounting_period.lock', 'You do not have permission to preview accounting-period locks.');
        return;
    }

    if ($relativePath === '/accounting-period/update-lock-period') {
        requireAnyPermission(
            $conn,
            $user,
            ['accounting_period.edit', 'accounting_period.lock'],
            'You do not have permission to edit or lock accounting periods.'
        );
        return;
    }

    if ($relativePath === '/accounting-period/fiscal-year-closures') {
        requireAnyPermission(
            $conn,
            $user,
            ['accounting_period.view', 'accounting_period.close', 'accounting_period.reverse'],
            'You do not have permission to access fiscal-year closures.'
        );
        return;
    }

    if (in_array($relativePath, ['/accounting-period/preview-fiscal-year-close', '/accounting-period/post-fiscal-year-close'], true)) {
        requirePermission($conn, $user, 'accounting_period.close', 'You do not have permission to perform a fiscal-year close.');
        return;
    }

    if (in_array($relativePath, ['/accounting-period/preview-fiscal-year-close-reversal', '/accounting-period/reverse-fiscal-year-close'], true)) {
        requirePermission($conn, $user, 'accounting_period.reverse', 'You do not have permission to reverse a fiscal-year close.');
        return;
    }

    // RBAC Batch 8: Bank Reconciliation and FX/Revaluation permissions.
    // Current and compatibility reconciliation APIs are both covered so a legacy
    // route cannot bypass the application-wide permission model.
    $bankReconPermissionMap = [
        '/bank-recon/list' => 'bank_reconciliation.view',
        '/bank-recon/create' => 'bank_reconciliation.create',
        '/bank-recon/update' => 'bank_reconciliation.edit',
        '/bank-recon/update-line' => 'bank_reconciliation.edit',
        '/bank-recon/add-line' => 'bank_reconciliation.edit',
        '/bank-recon/delete-line' => 'bank_reconciliation.edit',
        '/bank-recon/append-lines' => 'bank_reconciliation.edit',
        '/bank-recon/delete' => 'bank_reconciliation.delete',
        '/bank-recon/match' => 'bank_reconciliation.match',
        '/bank-recon/unmatch' => 'bank_reconciliation.match',
        '/bank-recon/match-selected-lines' => 'bank_reconciliation.match',
        '/bank-recon/classify' => 'bank_reconciliation.match',
        '/bank-recon/classify-selected-lines' => 'bank_reconciliation.match',
        '/bank-recon/unclassify-line' => 'bank_reconciliation.match',
        '/bank-recon/auto-rules' => 'bank_reconciliation.match',
        '/bank-reconciliation/create-bank-reconciliation' => 'bank_reconciliation.create',
        '/bank-reconciliation/analyze-bank-reconciliation' => 'bank_reconciliation.match',
        '/bank-reconciliation/fetch-bank-reconciliations' => 'bank_reconciliation.view',
        '/bank-reconciliation/fetch-single-bank-reconciliation' => 'bank_reconciliation.view',
        '/bank-reconciliation/manual-match-bank-reconciliation' => 'bank_reconciliation.match',
        '/bank-reconciliation/mark-bank-reconciliation-adjustment' => 'bank_reconciliation.match',
    ];

    if (isset($bankReconPermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $bankReconPermissionMap[$relativePath],
            'You do not have permission to perform this bank reconciliation action.'
        );
        return;
    }

    if (in_array($relativePath, ['/bank-recon/export-excel', '/bank-reconciliation/download-bank-reconciliation-excel'], true)) {
        requirePermission($conn, $user, 'bank_reconciliation.view', 'You do not have permission to view bank reconciliations.');
        requirePermission($conn, $user, 'bank_reconciliation.export', 'You do not have permission to export bank reconciliations.');
        return;
    }

    if ($relativePath === '/exchange/get-realized') {
        requirePermission($conn, $user, 'fx.view', 'You do not have permission to view FX gain/loss information.');
        return;
    }

    if ($relativePath === '/exchange/get-revaluation') {
        requirePermission($conn, $user, 'fx.view', 'You do not have permission to view FX gain/loss information.');
        requirePermission($conn, $user, 'fx.preview', 'You do not have permission to preview FX revaluations.');
        return;
    }

    if (in_array($relativePath, ['/exchange/post-revaluation', '/exchange/post-zero-revaluation'], true)) {
        requirePermission($conn, $user, 'fx.post', 'You do not have permission to post FX revaluations.');
        return;
    }

    if ($relativePath === '/exchange/reverse-revaluation') {
        requirePermission($conn, $user, 'fx.reverse', 'You do not have permission to reverse FX revaluations.');
        return;
    }

    // RBAC Batch 7: Financial-report permissions are report/action specific.
    $financialReportViewPermissionMap = [
        '/ledger/reports/ledger-reports' => 'ledger_statement.view',
        '/ledger/reports/general-ledger-reports' => 'general_ledger.view',
        '/ledger/reports/all-gl-reports' => 'general_ledger.view',
        '/ledger/reports/trial-balance' => 'trial_balance.view',
        '/ledger/reports/pl-reports' => 'profit_loss.view',
        '/ledger/reports/balance-sheet-reports' => 'balance_sheet.view',
        '/invoice/reports/invoice-aging' => 'invoice_aging.view',
        '/invoice/reports/all-invoice-aging' => 'invoice_aging.view',
    ];

    if (isset($financialReportViewPermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $financialReportViewPermissionMap[$relativePath],
            'You do not have permission to view this financial report.'
        );
        return;
    }

    $financialReportExportPermissionMap = [
        '/ledger/reports/ledger-reports-excel' => ['ledger_statement.view', 'ledger_statement.export'],
        '/ledger/reports/gl-reports-excel' => ['general_ledger.view', 'general_ledger.export'],
        '/ledger/reports/trial-balance-excel' => ['trial_balance.view', 'trial_balance.export'],
        '/ledger/reports/pl-reports-excel' => ['profit_loss.view', 'profit_loss.export'],
        '/ledger/reports/bs-reports-excel' => ['balance_sheet.view', 'balance_sheet.export'],
        '/invoice/reports/invoice-aging-excel' => ['invoice_aging.view', 'invoice_aging.export'],
    ];

    if (isset($financialReportExportPermissionMap[$relativePath])) {
        foreach ($financialReportExportPermissionMap[$relativePath] as $permissionCode) {
            requirePermission(
                $conn,
                $user,
                $permissionCode,
                'You do not have permission to export this financial report.'
            );
        }
        return;
    }

    // RBAC Batch 3: Journal permissions are action-specific.
    $journalPermissionMap = [
        '/journal/filtered-request' => 'journal.view',
        '/journal/kpi-stats' => 'journal.view',
        '/journal/create-journal' => 'journal.create',
        '/journal/edit-journal' => 'journal.edit',
        '/journal/delete-journal' => 'journal.delete',
        '/journal/delete-single-line' => 'journal.edit',
        '/journal/delete-single-journal' => 'journal.edit',
        '/journal/duplicate-journal' => 'journal.duplicate',
        '/journal/validate-import' => 'journal.import',
        '/journal/invoice-payment-registration-options' => 'journal.payment_link',
        '/journal/preview-invoice-payment-registration' => 'journal.payment_link',
        '/journal/register-existing-invoice-payment' => 'journal.payment_link',
        '/journal/update-linked-invoice-payment' => 'journal.payment_link',
    ];

    if (isset($journalPermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $journalPermissionMap[$relativePath],
            'You do not have permission to perform this journal action.'
        );
        return;
    }

    if ($relativePath === '/journal/fetch-single-journal') {
        requireAnyPermission(
            $conn,
            $user,
            ['journal.view', 'journal.edit'],
            'You do not have permission to access this journal.'
        );
        return;
    }

    if ($relativePath === '/journal/ledger-suggestions') {
        requireAnyPermission(
            $conn,
            $user,
            ['journal.create', 'journal.edit', 'journal.import'],
            'You do not have permission to load journal ledger suggestions.'
        );
        return;
    }

    // Read-only reference data required by the journal builder. These do not
    // grant access to the Ledger, FX Rate or Cost Centre administration pages.
    if ($relativePath === '/ledger/fetch-ledger') {
        requireAnyPermission(
            $conn,
            $user,
            ['ledger.view', 'journal.create', 'journal.edit', 'journal.import', 'ledger_statement.view'],
            'You do not have permission to load ledger reference data.'
        );
        return;
    }

    if ($relativePath === '/rate/fetch-rate') {
        requireAnyPermission(
            $conn,
            $user,
            ['exchange_rate.view', 'journal.create', 'journal.edit', 'journal.import', 'invoice.create', 'invoice.edit', 'fx.view', 'fx.preview'],
            'You do not have permission to load exchange-rate reference data.'
        );
        return;
    }

    // RBAC Batch 10: the cost-centre reference list remains scope-aware and is
    // available only to workflows that genuinely need a cost-centre selector.
    // Cost Centre Administration itself is handled separately below.
    if ($relativePath === '/cost-centres/list') {
        requireAnyPermission(
            $conn,
            $user,
            [
                'cost_centre.view',
                'journal.create', 'journal.edit', 'journal.import',
                'invoice.create', 'invoice.edit',
                'bank_reconciliation.create', 'bank_reconciliation.edit',
                'user.create', 'user.edit'
            ],
            'You do not have permission to load cost-centre reference data.'
        );
        return;
    }

    if ($relativePath === '/cost-centres/manage-list') {
        requireAnyPermission(
            $conn,
            $user,
            ['cost_centre.view', 'cost_centre.edit', 'cost_centre.delete'],
            'You do not have permission to browse Cost Centre Administration.'
        );
        return;
    }

    if ($relativePath === '/cost-centres/get') {
        requireAnyPermission(
            $conn,
            $user,
            ['cost_centre.view', 'cost_centre.edit', 'cost_centre.delete'],
            'You do not have permission to access this cost centre.'
        );
        return;
    }

    $costCentrePermissionMap = [
        '/cost-centres/create' => 'cost_centre.create',
        '/cost-centres/update' => 'cost_centre.edit',
        '/cost-centres/delete' => 'cost_centre.delete',
    ];
    if (isset($costCentrePermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $costCentrePermissionMap[$relativePath],
            'You do not have permission to perform this cost-centre administration action.'
        );
        return;
    }

    // RBAC Batch 11: Timesheets are permission-driven. Ownership for the legacy
    // Timesheet role and the existing All Cost Centres requirement are enforced in
    // the endpoints through timesheetStaffScope().
    $timesheetPermissionMap = [
        '/timesheet/filtered-request' => 'timesheet.view',
        '/timesheet/create-timesheet' => 'timesheet.create',
        '/timesheet/edit-timesheet' => 'timesheet.edit',
        '/timesheet/delete-timesheet' => 'timesheet.delete',
        '/timesheet/delete-single-timesheet' => 'timesheet.delete',
        '/timesheet/reports/timesheet-report' => 'timesheet.view',
    ];
    if (isset($timesheetPermissionMap[$relativePath])) {
        requirePermission(
            $conn,
            $user,
            $timesheetPermissionMap[$relativePath],
            'You do not have permission to perform this timesheet action.'
        );
        return;
    }

    if ($relativePath === '/timesheet/fetch-single-timesheet') {
        requireAnyPermission(
            $conn,
            $user,
            ['timesheet.view', 'timesheet.edit'],
            'You do not have permission to access this timesheet entry.'
        );
        return;
    }

    if ($relativePath === '/timesheet/reference-data') {
        requireAnyPermission(
            $conn,
            $user,
            ['timesheet.view', 'timesheet.create', 'timesheet.edit', 'user.create', 'user.edit'],
            'You do not have permission to load timesheet reference data.'
        );
        return;
    }

    if (in_array($relativePath, ['/timesheet/reports/all-timesheet-report', '/timesheet/reports/timesheet-excel'], true)) {
        requirePermission($conn, $user, 'timesheet.view', 'You do not have permission to view timesheet reports.');
        requirePermission($conn, $user, 'timesheet.export', 'You do not have permission to export timesheet reports.');
        return;
    }

    // Final RBAC hardening: every active API route above must have an explicit
    // permission policy. New routes fail closed for normal users until they are
    // registered here. Super Admin remains the only automatic full-access bypass.
    if (rbacIsSuperAdmin($user)) {
        return;
    }

    throw new RuntimeException(
        'This SmartBooks API route does not have an RBAC policy configured.',
        403
    );
}
