<?php

require_once __DIR__ . '/vendor/autoload.php';

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include_once('includes/connection.php');


// Normalize request URI
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/smartbooks-server/api';
$relativePath = str_replace($basePath, '', $requestUri);



$routes = [
    '/' => function () {
        echo json_encode(["message" => "Welcome to Smartbooks API 😊"]);
    },
    '/welcome' => 'routes/welcome.php',
    

    // Auth Pages
    '/auth/login' => 'routes/auth/login.php',
    '/auth/register' => 'routes/auth/register.php',

    // Accounting Period Locking
    '/accounting-period/update-lock-period' => 'routes/accounting-period/updateLockPeriod.php',


    // Accounting Type
    '/accounting-type/filtered-request' => 'routes/account/accounting-type/getFilteredRequest.php',
    '/accounting-type/create-account-type' => 'routes/account/accounting-type/CreateAccountType.php',
    '/accounting-type/edit-account-type' => 'routes/account/accounting-type/EditAccountType.php',
    '/accounting-type/delete-account-type' => 'routes/account/accounting-type/DeleteAccountType.php',


    // Bank Details
    '/bank/filtered-request' => 'routes/bank/getFilteredRequest.php',
    '/bank/create-bank-details' => 'routes/bank/CreateBankDetails.php',
    '/bank/edit-bank-details' => 'routes/bank/EditBankDetails.php',
    '/bank/delete-bank-details' => 'routes/bank/DeleteBankDetails.php',
    '/bank/fetch-banks' => 'routes/bank/fetchBanks.php',


    // Clients Data
    '/clients/filtered-request' => 'routes/clients/getFilteredRequest.php',
    '/clients/create-clients' => 'routes/clients/CreateClients.php',
    '/clients/edit-clients' => 'routes/clients/EditClients.php',
    '/clients/delete-clients' => 'routes/clients/DeleteClients.php',
    '/clients/fetch-clients' => 'routes/clients/fetchClients.php',
    '/clients/fetch-single-client' => 'routes/clients/fetchSingleClient.php',
    '/clients/fetch-last-client-id' => 'routes/clients/fetchLastClientId.php',


    // Rate Data
    '/rate/filtered-request' => 'routes/rate/getFilteredRequest.php',
    '/rate/create-rate' => 'routes/rate/CreateRate.php',
    '/rate/edit-rate' => 'routes/rate/EditRate.php',
    '/rate/delete-rate' => 'routes/rate/DeleteRate.php',
    '/rate/fetch-rate' => 'routes/rate/fetchRate.php',
    '/rate/fetch-single-rate' => 'routes/rate/fetchSingleRate.php',


    // Invoice Data
    '/invoice/filtered-request' => 'routes/invoice/getFilteredRequest.php',
    '/invoice/create-invoice' => 'routes/invoice/CreateInvoice.php',
    '/invoice/edit-invoice' => 'routes/invoice/EditInvoice.php',
    '/invoice/delete-invoice' => 'routes/invoice/DeleteInvoice.php',
    '/invoice/fetch-single-invoice' => 'routes/invoice/fetchSingleInvoice.php',
    '/invoice/update-invoice' => 'routes/invoice/updateInvoice.php',
    '/invoice/delete-single-invoice' => 'routes/invoice/deleteSingleInvoice.php',
    '/invoice/reports/invoice-aging' => 'routes/invoice/reports/InvoiceAging.php',
    '/invoice/reports/all-invoice-aging' => 'routes/invoice/reports/AllInvoiceAging.php',
    '/invoice/reports/invoice-aging-excel' => 'routes/invoice/reports/downloadInvoiceAgingExcel.php',


    // Timesheet Data
    '/timesheet/filtered-request' => 'routes/timesheet/getFilteredRequest.php',
    '/timesheet/create-timesheet' => 'routes/timesheet/CreateTimesheet.php',
    '/timesheet/edit-timesheet' => 'routes/timesheet/EditTimesheet.php',
    '/timesheet/delete-timesheet' => 'routes/timesheet/DeleteTimesheet.php',
    '/timesheet/fetch-single-timesheet' => 'routes/timesheet/fetchSingleTimesheet.php',
    '/timesheet/update-timesheet' => 'routes/timesheet/updateTimesheet.php',
    '/timesheet/delete-single-timesheet' => 'routes/timesheet/deleteSingleTimesheet.php',
    '/timesheet/reports/all-timesheet-report' => 'routes/timesheet/reports/AllTimesheetReport.php',
    '/timesheet/reports/timesheet-report' => 'routes/timesheet/reports/timesheetReport.php',
    '/timesheet/reports/timesheet-excel' => 'routes/timesheet/reports/downloadTimesheetExcel.php',
    
    
    // Journal Data
    '/journal/filtered-request' => 'routes/journal/getFilteredRequest.php',
    '/journal/create-journal' => 'routes/journal/CreateJournal.php',
    '/journal/edit-journal' => 'routes/journal/EditJournal.php',
    '/journal/delete-journal' => 'routes/journal/DeleteJournal.php',
    '/journal/delete-single-journal' => 'routes/journal/deleteSingleJournal.php',
    '/journal/fetch-single-journal' => 'routes/journal/fetchSingleJournal.php',
    
    
    // Ledger Data
    '/ledger/filtered-request' => 'routes/ledger/getFilteredRequest.php',
    '/ledger/create-ledger' => 'routes/ledger/CreateLedger.php',
    '/ledger/edit-ledger' => 'routes/ledger/EditLedger.php',
    '/ledger/delete-ledger' => 'routes/ledger/DeleteLedger.php',
    '/ledger/fetch-single-ledger' => 'routes/ledger/fetchSingleLedger.php',
    '/ledger/fetch-ledger' => 'routes/ledger/fetchLedger.php',
    '/ledger/reports/ledger-reports' => 'routes/ledger/reports/ledgerReports.php',
    '/ledger/reports/ledger-reports-excel' => 'routes/ledger/reports/ledgerReportsExcel.php',
    '/ledger/reports/general-ledger-reports' => 'routes/ledger/reports/generalledgerReports.php',
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
    '/staff/delete-staff' => 'routes/staff/DeleteStaff.php',
    '/staff/fetch-staff' => 'routes/staff/fetchStaff.php',
    

    // Exchange Gain or Loss
    '/exchange/get-revaluation' => 'routes/exchange-gain/getFxRevaluation.php',
    '/exchange/post-revaluation' => 'routes/exchange-gain/postFxRevaluation.php',

    // Projects
    '/projects/fetch-projects' => 'routes/projects/fetchProjects.php',


    // Reports
    // '/reports' => 'routes/reports/getDashboard.php',
    '/reports' => 'routes/reports/advancedDashboard.php',

    // Users
    '/users/getFilteredRequest' => 'routes/users/getFilteredRequest.php',
    '/users/getSingleUser' => 'routes/users/getSingleUser.php',
    '/users/createUsers' => 'routes/users/CreateUsers.php',
    '/users/editUsers' => 'routes/users/EditUsers.php',
    '/users/deleteUsers' => 'routes/users/deleteUsers.php',
    '/users/updateProfile' => 'routes/users/UpdateProfile.php',


];


if (array_key_exists($relativePath, $routes)) {
    if (is_callable($routes[$relativePath])) {
        $routes[$relativePath](); // Execute function
    } else {
        include_once($routes[$relativePath]);
    }
    exit;
}

http_response_code(404);
echo json_encode([
    "status" => "Failed",
    "message" => "Page not found!"
    ]);
exit;

// Close connection
mysqli_close($conn);

?>