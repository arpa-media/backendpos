<?php

use App\Http\Controllers\Api\V1\Finance\FinanceChartOfAccountController;
use App\Http\Controllers\Api\V1\Finance\FinanceCompanyOutletMappingController;
use App\Http\Controllers\Api\V1\Finance\FinanceJournalTemplateController;
use App\Http\Controllers\Api\V1\Finance\FinanceManualJournalController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::prefix('coas')->group(function (): void {
            Route::get('/options', [FinanceChartOfAccountController::class, 'options'])
                ->middleware('permission_or_snapshot:finance.coa.view')
                ->name('finance.iter03.coa.options');
            Route::get('/', [FinanceChartOfAccountController::class, 'index'])
                ->middleware('permission_or_snapshot:finance.coa.view')
                ->name('finance.iter03.coa.index');
            Route::post('/', [FinanceChartOfAccountController::class, 'store'])
                ->middleware('permission_or_snapshot:finance.coa.create')
                ->name('finance.iter03.coa.store');
            Route::put('/{id}', [FinanceChartOfAccountController::class, 'update'])
                ->middleware('permission_or_snapshot:finance.coa.update')
                ->name('finance.iter03.coa.update');
            Route::delete('/{id}', [FinanceChartOfAccountController::class, 'destroy'])
                ->middleware('permission_or_snapshot:finance.coa.delete')
                ->name('finance.iter03.coa.destroy');
        });

        Route::prefix('scope/outlets')->group(function (): void {
            Route::get('/', [FinanceCompanyOutletMappingController::class, 'index'])
                ->middleware('permission_or_snapshot:finance.coa.view')
                ->name('finance.iter03.scope.outlets.index');
            Route::put('/{outletId}', [FinanceCompanyOutletMappingController::class, 'update'])
                ->middleware('permission_or_snapshot:finance.coa.update')
                ->name('finance.iter03.scope.outlets.update');
        });

        Route::prefix('journals')->group(function (): void {
            Route::get('/options', [FinanceManualJournalController::class, 'options'])
                ->middleware('permission_or_snapshot:finance.journal.view,finance.general_posting.view')
                ->name('finance.iter03.journal.options');
            Route::get('/', [FinanceManualJournalController::class, 'index'])
                ->middleware('permission_or_snapshot:finance.journal.view,finance.general_posting.view')
                ->name('finance.iter03.journal.index');
            Route::get('/{id}', [FinanceManualJournalController::class, 'show'])
                ->middleware('permission_or_snapshot:finance.journal.view,finance.general_posting.view')
                ->name('finance.iter03.journal.show');
            Route::post('/', [FinanceManualJournalController::class, 'store'])
                ->middleware('permission_or_snapshot:finance.journal.create,finance.general_posting.create')
                ->name('finance.iter03.journal.store');
            Route::put('/{id}', [FinanceManualJournalController::class, 'update'])
                ->middleware('permission_or_snapshot:finance.journal.update,finance.general_posting.update')
                ->name('finance.iter03.journal.update');
            Route::delete('/{id}', [FinanceManualJournalController::class, 'destroy'])
                ->middleware('permission_or_snapshot:finance.journal.delete,finance.general_posting.delete')
                ->name('finance.iter03.journal.destroy');
            Route::post('/{id}/post', [FinanceManualJournalController::class, 'post'])
                ->middleware('permission_or_snapshot:finance.journal.post,finance.journal.update,finance.general_posting.post,finance.general_posting.update')
                ->name('finance.iter03.journal.post');
            Route::post('/{id}/reverse', [FinanceManualJournalController::class, 'reverse'])
                ->middleware('permission_or_snapshot:finance.journal.reverse,finance.journal.update,finance.general_posting.reopen,finance.general_posting.update')
                ->name('finance.iter03.journal.reverse');
            Route::post('/template-preview', [FinanceManualJournalController::class, 'previewTemplate'])
                ->middleware('permission_or_snapshot:finance.journal.create,finance.general_posting.create,finance.journal.update')
                ->name('finance.iter03.journal.template-preview');
        });

        Route::prefix('journal-templates')->group(function (): void {
            Route::get('/options', [FinanceJournalTemplateController::class, 'options'])
                ->middleware('permission_or_snapshot:finance.journal_template.view')
                ->name('finance.iter03.template.options');
            Route::get('/', [FinanceJournalTemplateController::class, 'index'])
                ->middleware('permission_or_snapshot:finance.journal_template.view')
                ->name('finance.iter03.template.index');
            Route::post('/', [FinanceJournalTemplateController::class, 'store'])
                ->middleware('permission_or_snapshot:finance.journal_template.create')
                ->name('finance.iter03.template.store');
            Route::put('/{id}', [FinanceJournalTemplateController::class, 'update'])
                ->middleware('permission_or_snapshot:finance.journal_template.update')
                ->name('finance.iter03.template.update');
            Route::delete('/{id}', [FinanceJournalTemplateController::class, 'destroy'])
                ->middleware('permission_or_snapshot:finance.journal_template.delete')
                ->name('finance.iter03.template.destroy');
            Route::post('/{id}/preview', [FinanceJournalTemplateController::class, 'preview'])
                ->middleware('permission_or_snapshot:finance.journal_template.view,finance.journal_template.create,finance.journal_template.update')
                ->name('finance.iter03.template.preview');
        });
    });
