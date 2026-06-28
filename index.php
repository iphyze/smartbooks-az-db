<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authorization.php';

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim(envString('API_BASE_PATH', '/smartbooks-server/api'), '/');
$relativePath = '/' . ltrim(substr($requestUri, strlen($basePath)), '/');
if ($relativePath === '/') {
    $relativePath = '/';
}

$routes = [
    '/' => function () {
        echo json_encode(["message" => "Welcome to Smartbooks API 😊"]);
    },
    '/welcome' => 'routes/welcome.php',
    

    // Auth Pages
    '/auth/csrf' => 'routes/auth/csrf.php',
    '/auth/bootstrap' => 'routes/auth/bootstrap.php',
    '/auth/login' => 'routes/auth/login.php',
    '/auth/me' => 'routes/auth/me.php',
    '/auth/logout' => 'routes/auth/logout.php',

    // Notifications
    '/notifications/summary' => 'routes/notifications/summary.php',
    '/notifications/list' => 'routes/notifications/list.php',
    '/notifications/mark-read' => 'routes/notifications/markRead.php',
    '/notifications/mark-all-read' => 'routes/notifications/markAllRead.php',
    '/notifications/mark-seen' => 'routes/notifications/markSeen.php',
    '/notifications/dismiss' => 'routes/notifications/dismiss.php',

    // Activity Logs
    '/activity-logs/list' => 'routes/activity-logs/list.php',
    '/activity-logs/export' => 'routes/activity-logs/export.php',

    // Accounting Period Locking
    '/accounting-period/periods' => 'routes/accounting-period/fetchLockPeriods.php',
    '/accounting-period/create-period' => 'routes/accounting-period/createLockPeriod.php',
    '/accounting-period/update-lock-period' => 'routes/accounting-period/updateLockPeriod.php',


    // Accounting Type
    '/accounting-type/filtered-request' => 'routes/account/accounting-type/getFilteredRequest.php',
    '/accounting-type/create-account-type' => 'routes/account/accounting-type/CreateAccountType.php',
    '/accounting-type/edit-account-type' => 'routes/account/accounting-type/EditAccountType.php',
    '/accounting-type/delete-account-type' => 'routes/account/accounting-type/deleteAccountType.php',
    '/accounting-type/fetch-single-account' => 'routes/account/accounting-type/fetchSingleAccount.php',
    '/accounting-type/fetch-account' => 'routes/account/accounting-type/fetchAccount.php',


    // Bank Details
    '/bank/filtered-request' => 'routes/bank/getFilteredRequest.php',
    '/bank/create-bank-details' => 'routes/bank/CreateBankDetails.php',
    '/bank/edit-bank-details' => 'routes/bank/EditBankDetails.php',
    '/bank/delete-bank-details' => 'routes/bank/deleteBankDetails.php',
    '/bank/fetch-banks' => 'routes/bank/fetchBanks.php',
    '/bank/fetch-single-bank' => 'routes/bank/fetchSingleBank.php',


    // Clients Data
    '/clients/filtered-request' => 'routes/clients/getFilteredRequest.php',
    '/clients/create-clients' => 'routes/clients/CreateClients.php',
    '/clients/edit-clients' => 'routes/clients/EditClients.php',
    '/clients/delete-clients' => 'routes/clients/deleteClients.php',
    '/clients/fetch-clients' => 'routes/clients/fetchClients.php',
    '/clients/fetch-single-client' => 'routes/clients/fetchSingleClient.php',
    '/clients/fetch-last-client-id' => 'routes/clients/fetchLastClientId.php',


    // Rate Data
    '/rate/filtered-request' => 'routes/rate/getFilteredRequest.php',
    '/rate/create-rate' => 'routes/rate/CreateRate.php',
    '/rate/edit-rate' => 'routes/rate/EditRate.php',
    '/rate/delete-rate' => 'routes/rate/deleteRate.php',
    '/rate/fetch-rate' => 'routes/rate/fetchRate.php',
    '/rate/fetch-single-rate' => 'routes/rate/fetchSingleRate.php',


    // Invoice Data
    '/invoice/filtered-request' => 'routes/invoice/getFilteredRequest.php',
    '/invoice/create-invoice' => 'routes/invoice/CreateInvoice.php',
    '/invoice/edit-invoice' => 'routes/invoice/EditInvoice.php',
    '/invoice/delete-invoice' => 'routes/invoice/deleteInvoice.php',
    '/invoice/fetch-single-invoice' => 'routes/invoice/fetchSingleInvoice.php',
    '/invoice/update-invoice' => 'routes/invoice/updateInvoice.php',
    '/invoice/save-draft' => 'routes/invoice/saveInvoiceDraft.php',
    '/invoice/get-draft' => 'routes/invoice/getInvoiceDraft.php',
    '/invoice/delete-draft' => 'routes/invoice/deleteInvoiceDraft.php',
    '/invoice/duplicate-invoice' => 'routes/invoice/duplicateInvoice.php',
    '/invoice/send-invoice' => 'routes/invoice/sendInvoice.php',
    '/invoice/change-workflow-status' => 'routes/invoice/changeInvoiceWorkflow.php',
    '/invoice/record-payment' => 'routes/invoice/recordInvoicePayment.php',
    '/invoice/payment-journal-options' => 'routes/invoice/getPaymentJournalOptions.php',
    '/invoice/reverse-payment' => 'routes/invoice/reverseInvoicePayment.php',
    '/invoice/activity' => 'routes/invoice/getInvoiceActivity.php',
    '/invoice/create-reminder' => 'routes/invoice/createInvoiceReminder.php',
    '/invoice/cancel-reminder' => 'routes/invoice/cancelInvoiceReminder.php',
    '/invoice/service-catalogue' => 'routes/invoice/getServiceCatalogue.php',
    '/invoice/create-service' => 'routes/invoice/createServiceCatalogueItem.php',
    '/invoice/update-service' => 'routes/invoice/updateServiceCatalogueItem.php',
    '/invoice/client-preferences' => 'routes/invoice/getClientInvoicePreferences.php',
    '/invoice/save-client-preferences' => 'routes/invoice/saveClientInvoicePreferences.php',
    '/invoice/delete-single-invoice' => 'routes/invoice/deleteSingleInvoice.php',
    '/invoice/kpi-stats' => 'routes/invoice/reports/getInvoiceKpi.php',
    '/invoice/reports/invoice-aging' => 'routes/invoice/reports/InvoiceAging.php',
    '/invoice/reports/all-invoice-aging' => 'routes/invoice/reports/AllInvoiceAging.php',
    '/invoice/reports/invoice-aging-excel' => 'routes/invoice/reports/downloadInvoiceAgingExcel.php',


    // Timesheet Data
    '/timesheet/filtered-request' => 'routes/timesheet/getFilteredRequest.php',
    '/timesheet/create-timesheet' => 'routes/timesheet/CreateTimesheet.php',
    '/timesheet/edit-timesheet' => 'routes/timesheet/EditTimesheet.php',
    '/timesheet/delete-timesheet' => 'routes/timesheet/deleteTimesheet.php',
    '/timesheet/fetch-single-timesheet' => 'routes/timesheet/fetchSingleTimesheet.php',
    // '/timesheet/update-timesheet' => 'routes/timesheet/updateTimesheet.php',
    '/timesheet/delete-single-timesheet' => 'routes/timesheet/deleteSingleTimesheet.php',
    '/timesheet/reports/all-timesheet-report' => 'routes/timesheet/reports/AllTimesheetReport.php',
    '/timesheet/reports/timesheet-report' => 'routes/timesheet/reports/timesheetReport.php',
    '/timesheet/reports/timesheet-excel' => 'routes/timesheet/reports/downloadTimesheetExcel.php',
    '/timesheet/reference-data' => 'routes/timesheet/referenceData.php',
    
    
    // Journal Data
    '/journal/filtered-request' => 'routes/journal/getFilteredRequest.php',
    '/journal/create-journal' => 'routes/journal/CreateJournal.php',
    '/journal/edit-journal' => 'routes/journal/EditJournal.php',
    '/journal/delete-journal' => 'routes/journal/deleteJournal.php',
    // Canonical endpoint used by the active Edit Journal form.
    '/journal/delete-single-line' => 'routes/journal/deleteSingleJournal.php',
    // Backward-compatible alias retained for older builds/store calls.
    '/journal/delete-single-journal' => 'routes/journal/deleteSingleJournal.php',
    '/journal/fetch-single-journal' => 'routes/journal/fetchSingleJournal.php',
    '/journal/validate-import' => 'routes/journal/validateImport.php',
    '/journal/ledger-suggestions' => 'routes/journal/ledgerSuggestions.php',
    
    
    // Ledger Data
    '/ledger/filtered-request' => 'routes/ledger/getFilteredRequest.php',
    '/ledger/create-ledger' => 'routes/ledger/CreateLedger.php',
    '/ledger/edit-ledger' => 'routes/ledger/EditLedger.php',
    '/ledger/delete-ledger' => 'routes/ledger/deleteLedger.php',
    '/ledger/delete-single-ledger' => 'routes/ledger/deleteSingleLedger.php',
    '/ledger/fetch-single-ledger' => 'routes/ledger/fetchSingleLedger.php',
    '/ledger/fetch-ledger' => 'routes/ledger/fetchLedger.php',
    '/ledger/reports/ledger-reports' => 'routes/ledger/reports/ledgerReports.php',
    '/ledger/reports/ledger-reports-excel' => 'routes/ledger/reports/ledgerReportsExcel.php',
    '/ledger/reports/general-ledger-reports' => 'routes/ledger/reports/generalLedgerReports.php',
    '/ledger/reports/all-gl-reports' => 'routes/ledger/reports/allGlReports.php',
    '/ledger/reports/gl-reports-excel' => 'routes/ledger/reports/glReportsExcel.php',
    '/ledger/reports/trial-balance' => 'routes/ledger/reports/trialBalance.php',
    '/ledger/reports/trial-balance-excel' => 'routes/ledger/reports/trialBalanceExcel.php',
    '/ledger/reports/pl-reports' => 'routes/ledger/reports/profitLossReports.php',
    '/ledger/reports/pl-reports-excel' => 'routes/ledger/reports/profitLossReportsExcel.php',
    '/ledger/reports/balance-sheet-reports' => 'routes/ledger/reports/balanceSheetReports.php',
    '/ledger/reports/bs-reports-excel' => 'routes/ledger/reports/balanceSheetReportsExcel.php',


    // Staff Data
    '/staff/filtered-request' => 'routes/staff/getFilteredRequest.php',
    '/staff/create-staff' => 'routes/staff/CreateStaff.php',
    '/staff/edit-staff' => 'routes/staff/EditStaff.php',
    '/staff/delete-staff' => 'routes/staff/deleteStaff.php',
    '/staff/fetch-staff' => 'routes/staff/fetchStaff.php',
    '/staff/fetch-single-staff' => 'routes/staff/fetchSingleStaff.php',
    '/staff/fetch-last-staff-id' => 'routes/staff/fetchLastStaffId.php',
    

    // Exchange Gain or Loss
    '/exchange/get-revaluation' => 'routes/exchange-gain/getFxRevaluation.php',
    '/exchange/post-revaluation' => 'routes/exchange-gain/postFxRevaluation.php',
    '/exchange/reverse-revaluation' => 'routes/exchange-gain/reverseFxRevaluation.php',
    '/exchange/post-zero-revaluation' => 'routes/exchange-gain/postZeroFxRevaluation.php',

    
    // Project Data
    '/projects/filtered-request' => 'routes/projects/getFilteredRequest.php',
    '/projects/create-project' => 'routes/projects/createProjects.php',
    '/projects/edit-project' => 'routes/projects/editProjects.php',
    '/projects/delete-project' => 'routes/projects/deleteProjects.php',
    '/projects/fetch-projects' => 'routes/projects/fetchProjects.php',
    '/projects/fetch-single-project' => 'routes/projects/fetchSingleProject.php',
    '/projects/fetch-last-project-id' => 'routes/projects/fetchLastProjectId.php',



    // Reports
    // '/reports' => 'routes/reports/getDashboard.php',
    '/reports' => 'routes/reports/advancedDashboard.php',
    '/reports/dashboard-analytics' => 'routes/reports/dashboardAnalytics.php',

    // Users
    '/users/getFilteredRequest' => 'routes/users/getFilteredRequest.php',
    '/users/getSingleUser' => 'routes/users/getSingleUser.php',
    '/users/createUsers' => 'routes/users/CreateUsers.php',
    '/users/editUsers' => 'routes/users/EditUsers.php',
    '/users/deleteUsers' => 'routes/users/deleteUsers.php',
    '/users/updateProfile' => 'routes/users/UpdateProfile.php',


    // Bank Reconciliation
    '/bank-reconciliation/create-bank-reconciliation' => 'routes/bank-reconciliation/createBankReconciliation.php',
    '/bank-reconciliation/analyze-bank-reconciliation' => 'routes/bank-reconciliation/analyzeBankReconciliation.php',
    '/bank-reconciliation/fetch-bank-reconciliations' => 'routes/bank-reconciliation/fetchBankReconciliations.php',
    '/bank-reconciliation/fetch-single-bank-reconciliation' => 'routes/bank-reconciliation/fetchSingleBankReconciliation.php',
    '/bank-reconciliation/manual-match-bank-reconciliation' => 'routes/bank-reconciliation/manualMatchBankReconciliation.php',
    '/bank-reconciliation/mark-bank-reconciliation-adjustment' => 'routes/bank-reconciliation/markBankReconciliationAdjustment.php',
    '/bank-reconciliation/download-bank-reconciliation-excel' => 'routes/bank-reconciliation/downloadBankReconciliationExcel.php',


    // Bank Reconciliation Two
    '/bank-recon/list' => 'routes/bank-recon/listReconciliations.php',
    '/bank-recon/create' => 'routes/bank-recon/createReconciliation.php',
    '/bank-recon/get' => 'routes/bank-recon/getReconciliation.php',
    '/bank-recon/match' => 'routes/bank-recon/matchLines.php',
    '/bank-recon/unmatch' => 'routes/bank-recon/unmatchLines.php',
    '/bank-recon/classify' => 'routes/bank-recon/classifyBankLine.php',
    '/bank-recon/export-excel' => 'routes/bank-recon/exportReconExcel.php',
    '/bank-recon/match-selected-lines' => 'routes/bank-recon/matchSelectedLines.php',
    '/bank-recon/classify-selected-lines' => 'routes/bank-recon/classifySelectedLines.php',
    '/bank-recon/update' => 'routes/bank-recon/updateReconciliation.php',
    '/bank-recon/delete' => 'routes/bank-recon/deleteReconciliation.php',
    '/bank-recon/update-line' => 'routes/bank-recon/updateLine.php',
    '/bank-recon/add-line'        => 'routes/bank-recon/addLine.php',
    '/bank-recon/delete-line'     => 'routes/bank-recon/deleteLine.php',
    '/bank-recon/append-lines'    => 'routes/bank-recon/appendLines.php',
    '/bank-recon/unclassify-line' => 'routes/bank-recon/unclassifyLine.php',
    '/bank-recon/auto-rules'     => 'routes/bank-recon/autoRules.php',


];


if (array_key_exists($relativePath, $routes)) {
    $databaseFreeRoutes = ['/', '/welcome', '/auth/csrf', '/auth/bootstrap'];
    if (!in_array($relativePath, $databaseFreeRoutes, true)) {
        require_once __DIR__ . '/includes/connection.php';
    }

    enforceApiRouteAccess($relativePath);
    if (is_callable($routes[$relativePath])) {
        $routes[$relativePath]();
    } else {
        include_once $routes[$relativePath];
    }
    exit;
}

jsonResponse([
    'status' => 'Failed',
    'message' => 'Page not found.'
], 404);
