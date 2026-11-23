<?php

use Illuminate\Support\Facades\Route;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingAccountController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingBootstrapController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingDocumentMappingController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingExpenseCategoryMappingController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingJournalEntryController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingPaymentMethodMappingController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingReportController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingSettingsController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingTransactionController;
use Abdiaziizaweis\Accounting\Http\Controllers\AccountingTransferController;
use Abdiaziizaweis\Accounting\Http\Controllers\DocumentRegistryController;

Route::get('/registry/documents', [DocumentRegistryController::class, 'index']);

Route::post('/bootstrap', [AccountingBootstrapController::class, 'seed'])
    ->middleware('permission:manage accounting settings');
Route::get('/settings', [AccountingSettingsController::class, 'show'])
    ->middleware('permission:manage accounting settings');
Route::patch('/settings', [AccountingSettingsController::class, 'update'])
    ->middleware('permission:manage accounting settings');
Route::patch('/settings/close-period', [AccountingSettingsController::class, 'closePeriod'])
    ->middleware('permission:manage accounting settings');
Route::patch('/settings/reopen-period', [AccountingSettingsController::class, 'reopenPeriod'])
    ->middleware('permission:manage accounting settings');

Route::get('/accounts', [AccountingAccountController::class, 'index'])
    ->middleware('permission:view accounting accounts');
Route::post('/accounts', [AccountingAccountController::class, 'store'])
    ->middleware('permission:create accounting accounts');
Route::get('/accounts/trashed/list', [AccountingAccountController::class, 'trashed'])
    ->middleware('permission:view accounting accounts');
Route::get('/accounts/{id}', [AccountingAccountController::class, 'show'])
    ->middleware('permission:view accounting accounts');
Route::patch('/accounts/{id}', [AccountingAccountController::class, 'update'])
    ->middleware('permission:edit accounting accounts');
Route::delete('/accounts/{id}', [AccountingAccountController::class, 'destroy'])
    ->middleware('permission:delete accounting accounts');
Route::post('/accounts/{id}/restore', [AccountingAccountController::class, 'restore'])
    ->middleware('permission:edit accounting accounts');
Route::delete('/accounts/{id}/force', [AccountingAccountController::class, 'forceDestroy'])
    ->middleware('permission:delete accounting accounts');

Route::get('/journal-entries', [AccountingJournalEntryController::class, 'index'])
    ->middleware('permission:view accounting journal entries');
Route::post('/journal-entries', [AccountingJournalEntryController::class, 'store'])
    ->middleware('permission:create accounting journal entries');
Route::get('/journal-entries/trashed/list', [AccountingJournalEntryController::class, 'trashed'])
    ->middleware('permission:view accounting journal entries');
Route::get('/journal-entries/{id}', [AccountingJournalEntryController::class, 'show'])
    ->middleware('permission:view accounting journal entries');
Route::patch('/journal-entries/{id}', [AccountingJournalEntryController::class, 'update'])
    ->middleware('permission:edit accounting journal entries');
Route::delete('/journal-entries/{id}', [AccountingJournalEntryController::class, 'destroy'])
    ->middleware('permission:delete accounting journal entries');
Route::post('/journal-entries/{id}/restore', [AccountingJournalEntryController::class, 'restore'])
    ->middleware('permission:edit accounting journal entries');

Route::get('/transactions', [AccountingTransactionController::class, 'index'])
    ->middleware('permission:view accounting transactions');
Route::post('/transactions/bulk-map', [AccountingTransactionController::class, 'bulkMap'])
    ->middleware('permission:edit accounting transactions');
Route::get('/transactions/{id}', [AccountingTransactionController::class, 'show'])
    ->middleware('permission:view accounting transactions');
Route::post('/transactions/{id}/map', [AccountingTransactionController::class, 'map'])
    ->middleware('permission:edit accounting transactions');
Route::post('/transactions/{id}/unmap', [AccountingTransactionController::class, 'unmap'])
    ->middleware('permission:edit accounting transactions');

Route::get('/document-mappings', [AccountingDocumentMappingController::class, 'index'])
    ->middleware('permission:view accounting mappings');
Route::post('/document-mappings/upsert', [AccountingDocumentMappingController::class, 'upsert'])
    ->middleware('permission:edit accounting mappings');

Route::get('/payment-method-mappings', [AccountingPaymentMethodMappingController::class, 'index'])
    ->middleware('permission:view accounting mappings');
Route::post('/payment-method-mappings/upsert', [AccountingPaymentMethodMappingController::class, 'upsert'])
    ->middleware('permission:edit accounting mappings');

Route::get('/expense-category-mappings', [AccountingExpenseCategoryMappingController::class, 'index'])
    ->middleware('permission:view accounting mappings');
Route::post('/expense-category-mappings/upsert', [AccountingExpenseCategoryMappingController::class, 'upsert'])
    ->middleware('permission:edit accounting mappings');

Route::get('/transfers', [AccountingTransferController::class, 'index'])
    ->middleware('permission:view accounting transfers');
Route::post('/transfers', [AccountingTransferController::class, 'store'])
    ->middleware('permission:create accounting transfers');
Route::get('/transfers/{id}', [AccountingTransferController::class, 'show'])
    ->middleware('permission:view accounting transfers');
Route::delete('/transfers/{id}', [AccountingTransferController::class, 'destroy'])
    ->middleware('permission:delete accounting transfers');

Route::prefix('reports')->middleware('permission:view accounting reports')->group(function () {
    Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance']);
    Route::get('/profit-and-loss', [AccountingReportController::class, 'profitAndLoss']);
    Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet']);
    Route::get('/cash-flow', [AccountingReportController::class, 'cashFlow']);
    Route::get('/account-statement', [AccountingReportController::class, 'accountStatement']);
    Route::get('/journal-activity-by-month', [AccountingReportController::class, 'journalActivityByMonth']);
});