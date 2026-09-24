<?php

use App\Http\Controllers\Api\V1\Warehouse\FinanceV4\WarehouseFinanceFoundationV4Controller as FinanceV4;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/finance-v4')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options', [FinanceV4::class, 'options'])
            ->middleware('permission_or_snapshot:warehouse.finance.coa.view,warehouse.finance.posting_template.view,warehouse.finance.general_posting.view')
            ->name('warehouse.finance-v4.options');

        Route::get('/coa', [FinanceV4::class, 'coaIndex'])
            ->middleware('permission_or_snapshot:warehouse.finance.coa.view')
            ->name('warehouse.finance-v4.coa.index');
        Route::post('/coa', [FinanceV4::class, 'coaStore'])
            ->middleware('permission_or_snapshot:warehouse.finance.coa.create')
            ->name('warehouse.finance-v4.coa.store');
        Route::put('/coa/{id}', [FinanceV4::class, 'coaUpdate'])
            ->middleware('permission_or_snapshot:warehouse.finance.coa.update')
            ->name('warehouse.finance-v4.coa.update');

        Route::get('/posting-templates', [FinanceV4::class, 'templateIndex'])
            ->middleware('permission_or_snapshot:warehouse.finance.posting_template.view')
            ->name('warehouse.finance-v4.templates.index');
        Route::post('/posting-templates', [FinanceV4::class, 'templateStore'])
            ->middleware('permission_or_snapshot:warehouse.finance.posting_template.create')
            ->name('warehouse.finance-v4.templates.store');
        Route::put('/posting-templates/{id}', [FinanceV4::class, 'templateUpdate'])
            ->middleware('permission_or_snapshot:warehouse.finance.posting_template.update')
            ->name('warehouse.finance-v4.templates.update');

        Route::get('/general-postings', [FinanceV4::class, 'postingIndex'])
            ->middleware('permission_or_snapshot:warehouse.finance.general_posting.view')
            ->name('warehouse.finance-v4.postings.index');
        Route::get('/general-postings/{id}', [FinanceV4::class, 'postingShow'])
            ->middleware('permission_or_snapshot:warehouse.finance.general_posting.view')
            ->name('warehouse.finance-v4.postings.show');
        Route::post('/general-postings', [FinanceV4::class, 'postingStore'])
            ->middleware('permission_or_snapshot:warehouse.finance.general_posting.create')
            ->name('warehouse.finance-v4.postings.store');
        Route::post('/general-postings/from-template', [FinanceV4::class, 'postingFromTemplate'])
            ->middleware('permission_or_snapshot:warehouse.finance.general_posting.create')
            ->name('warehouse.finance-v4.postings.from-template');
        Route::post('/general-postings/{id}/post', [FinanceV4::class, 'postingPost'])
            ->middleware('permission_or_snapshot:warehouse.finance.general_posting.post')
            ->name('warehouse.finance-v4.postings.post');
        Route::post('/general-postings/{id}/reverse', [FinanceV4::class, 'postingReverse'])
            ->middleware('permission_or_snapshot:warehouse.finance.general_posting.reverse')
            ->name('warehouse.finance-v4.postings.reverse');
    });
