<?php

use App\Http\Controllers\Api\V1\Warehouse\Documents\WarehouseCanonicalDocumentController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/canonical-documents')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/purchase-request/{id}', [WarehouseCanonicalDocumentController::class, 'purchaseRequest'])
            ->middleware('permission_or_snapshot:warehouse.procurement.request.view')->name('warehouse.canonical.pr.show');
        Route::get('/purchase-request/{id}/pdf', [WarehouseCanonicalDocumentController::class, 'purchaseRequestPdf'])
            ->middleware('permission_or_snapshot:warehouse.procurement.request.view')->name('warehouse.canonical.pr.pdf');

        Route::get('/purchase-order/{id}', [WarehouseCanonicalDocumentController::class, 'purchaseOrder'])
            ->middleware('permission_or_snapshot:warehouse.procurement.order.view')->name('warehouse.canonical.po.show');
        Route::get('/purchase-order/{id}/pdf', [WarehouseCanonicalDocumentController::class, 'purchaseOrderPdf'])
            ->middleware('permission_or_snapshot:warehouse.procurement.order.view')->name('warehouse.canonical.po.pdf');

        Route::get('/goods-receipt/{id}', [WarehouseCanonicalDocumentController::class, 'goodsReceipt'])
            ->middleware('permission_or_snapshot:warehouse.receiving.goods_receipt.print,warehouse.receiving.monitor.view')->name('warehouse.canonical.gr.show');
        Route::get('/goods-receipt/{id}/pdf', [WarehouseCanonicalDocumentController::class, 'goodsReceiptPdf'])
            ->middleware('permission_or_snapshot:warehouse.receiving.goods_receipt.print,warehouse.receiving.monitor.view')->name('warehouse.canonical.gr.pdf');

        foreach (['cash_in','cash_out','bank_in','bank_out'] as $type) {
            $perm = 'warehouse.finance.'.str_replace('_', '.', $type).'.view';
            Route::get("/treasury/{$type}/{id}", [WarehouseCanonicalDocumentController::class, 'treasury'])
                ->defaults('type', $type)->middleware("permission_or_snapshot:{$perm}")->name("warehouse.canonical.treasury.{$type}.show");
            Route::get("/treasury/{$type}/{id}/pdf", [WarehouseCanonicalDocumentController::class, 'treasuryPdf'])
                ->defaults('type', $type)->middleware("permission_or_snapshot:{$perm}")->name("warehouse.canonical.treasury.{$type}.pdf");
        }
    });
