<?php

use Abdiaziizaweis\Accounting\Http\Controllers\AccountingAccountController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingBootstrapController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingHealthController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingJournalEntryController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingReportController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingSettingsController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingTransactionController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingTransferController;
use Abdiaziizaweis\Accounting\Http\Controllers\CustomReportController;
use Abdiaziizaweis\Accounting\Http\Controllers\OperationMappingController;
use Illuminate\Support\Facades\Route;

/*
| Routes sit behind config('accounting.middleware'), usually auth + context.
| They do not require permission Gates. Add your own middleware later if you want.
*/

Route::get('/health', [AccountingHealthController::class, 'index']);

Route::post('/bootstrap', [AccountingBootstrapController::class, 'seed']);
Route::get('/settings', [AccountingSettingsController::class, 'show']);
Route::patch('/settings', [AccountingSettingsController::class, 'update']);
Route::patch('/settings/close-period', [AccountingSettingsController::class, 'closePeriod']);
Route::patch('/settings/reopen-period', [AccountingSettingsController::class, 'reopenPeriod']);

Route::get('/accounts', [AccountingAccountController::class, 'index']);
Route::post('/accounts', [AccountingAccountController::class, 'store']);
Route::get('/accounts/trashed/list', [AccountingAccountController::class, 'trashed']);
Route::get('/accounts/{id}', [AccountingAccountController::class, 'show']);
Route::patch('/accounts/{id}', [AccountingAccountController::class, 'update']);
Route::delete('/accounts/{id}', [AccountingAccountController::class, 'destroy']);
Route::post('/accounts/{id}/restore', [AccountingAccountController::class, 'restore']);
Route::delete('/accounts/{id}/force', [AccountingAccountController::class, 'forceDestroy']);

Route::get('/journal-entries', [AccountingJournalEntryController::class, 'index']);
Route::post('/journal-entries', [AccountingJournalEntryController::class, 'store']);
Route::get('/journal-entries/trashed/list', [AccountingJournalEntryController::class, 'trashed']);
Route::get('/journal-entries/{id}', [AccountingJournalEntryController::class, 'show']);
Route::patch('/journal-entries/{id}', [AccountingJournalEntryController::class, 'update']);
Route::delete('/journal-entries/{id}', [AccountingJournalEntryController::class, 'destroy']);
Route::post('/journal-entries/{id}/restore', [AccountingJournalEntryController::class, 'restore']);

Route::get('/transactions', [AccountingTransactionController::class, 'index']);
Route::get('/transactions/unmapped', [AccountingTransactionController::class, 'unmapped']);
Route::post('/transactions/bulk-map', [AccountingTransactionController::class, 'bulkMap']);
Route::get('/transactions/{id}', [AccountingTransactionController::class, 'show']);
Route::post('/transactions/{id}/map', [AccountingTransactionController::class, 'map']);
Route::post('/transactions/{id}/unmap', [AccountingTransactionController::class, 'unmap']);

Route::get('/operations', [OperationMappingController::class, 'index']);
Route::get('/operations/{operationKey}', [OperationMappingController::class, 'show'])
    ->where('operationKey', '.*');
Route::put('/operations/{operationKey}', [OperationMappingController::class, 'replace'])
    ->where('operationKey', '.*');

Route::get('/transfers', [AccountingTransferController::class, 'index']);
Route::post('/transfers', [AccountingTransferController::class, 'store']);
Route::get('/transfers/{id}', [AccountingTransferController::class, 'show']);
Route::delete('/transfers/{id}', [AccountingTransferController::class, 'destroy']);

Route::prefix('reports')->group(function () {
    Route::get('/custom', [CustomReportController::class, 'index']);
    Route::get('/custom/{name}', [CustomReportController::class, 'show']);
    Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance']);
    Route::get('/profit-and-loss', [AccountingReportController::class, 'profitAndLoss']);
    Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet']);
    Route::get('/cash-flow', [AccountingReportController::class, 'cashFlow']);
    Route::get('/account-statement', [AccountingReportController::class, 'accountStatement']);
    Route::get('/journal-activity-by-month', [AccountingReportController::class, 'journalActivityByMonth']);
});
