<?php

use App\Http\Controllers\Api\V1\Warehouse\FinanceV3\WarehouseFinanceV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/finance-v3')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/incoming/options', [WarehouseFinanceV3Controller::class, 'options'])
            ->middleware('permission_or_snapshot:warehouse.purchasing.invoice.view')
            ->name('warehouse.finance-v3.incoming.options');

        Route::get('/outgoing/options', [WarehouseFinanceV3Controller::class, 'options'])
            ->middleware('permission_or_snapshot:warehouse.finance.invoice.view')
            ->name('warehouse.finance-v3.outgoing.options');

        Route::get('/incoming/invoices', [WarehouseFinanceV3Controller::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.purchasing.invoice.view')
            ->name('warehouse.finance-v3.incoming.invoices.index');

        Route::get('/outgoing/invoices', [WarehouseFinanceV3Controller::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.finance.invoice.view')
            ->name('warehouse.finance-v3.outgoing.invoices.index');

        Route::post('/incoming/invoices/manual', [WarehouseFinanceV3Controller::class, 'storeManual'])
            ->middleware('permission_or_snapshot:warehouse.purchasing.invoice.create')
            ->name('warehouse.finance-v3.incoming.manual.store');

        Route::post('/outgoing/invoices/manual', [WarehouseFinanceV3Controller::class, 'storeManual'])
            ->middleware('permission_or_snapshot:warehouse.finance.invoice.create')
            ->name('warehouse.finance-v3.outgoing.manual.store');

        Route::get('/incoming/invoices/{source}/{id}', [WarehouseFinanceV3Controller::class, 'show'])
            ->where('source', 'auto_incoming|manual')
            ->middleware('permission_or_snapshot:warehouse.purchasing.invoice.view')
            ->name('warehouse.finance-v3.incoming.show');

        Route::get('/outgoing/invoices/{source}/{id}', [WarehouseFinanceV3Controller::class, 'show'])
            ->where('source', 'auto_outgoing|legacy_outgoing|manual')
            ->middleware('permission_or_snapshot:warehouse.finance.invoice.view')
            ->name('warehouse.finance-v3.outgoing.show');

        Route::post('/incoming/invoices/{source}/{id}/approve', [WarehouseFinanceV3Controller::class, 'approve'])
            ->where('source', 'auto_incoming|manual')
            ->middleware('permission_or_snapshot:warehouse.purchasing.invoice.update')
            ->name('warehouse.finance-v3.incoming.approve');

        Route::post('/outgoing/invoices/{source}/{id}/approve', [WarehouseFinanceV3Controller::class, 'approve'])
            ->where('source', 'auto_outgoing|manual')
            ->middleware('permission_or_snapshot:warehouse.finance.invoice.update')
            ->name('warehouse.finance-v3.outgoing.approve');

        Route::post('/payment-accounts', [WarehouseFinanceV3Controller::class, 'storePaymentAccount'])
            ->middleware('permission_or_snapshot:warehouse.purchasing.invoice.update,warehouse.finance.invoice.update')
            ->name('warehouse.finance-v3.payment-accounts.store');
    });
