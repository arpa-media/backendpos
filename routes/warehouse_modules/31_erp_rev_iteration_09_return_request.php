<?php

use App\Http\Controllers\Api\V1\Warehouse\ReturnRequestV9\WarehouseReturnRequestV9Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/return-requests-v9')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options',[WarehouseReturnRequestV9Controller::class,'options'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.view')
            ->name('warehouse.return-v9.options.i09');

        Route::get('/',[WarehouseReturnRequestV9Controller::class,'index'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.view')
            ->name('warehouse.return-v9.index.i09');

        Route::post('/',[WarehouseReturnRequestV9Controller::class,'store'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.create')
            ->name('warehouse.return-v9.store.i09');

        Route::get('/valuation/{skuId}',[WarehouseReturnRequestV9Controller::class,'valuation'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.view')
            ->name('warehouse.return-v9.valuation.i09');

        Route::get('/{id}',[WarehouseReturnRequestV9Controller::class,'show'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.view')
            ->name('warehouse.return-v9.show.i09');

        Route::put('/{id}',[WarehouseReturnRequestV9Controller::class,'update'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.update')
            ->name('warehouse.return-v9.update.i09');

        Route::post('/{id}/submit',[WarehouseReturnRequestV9Controller::class,'submit'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.update')
            ->name('warehouse.return-v9.submit.i09');

        Route::post('/{id}/approve',[WarehouseReturnRequestV9Controller::class,'approve'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.approve,warehouse.procurement.return_request.update')
            ->name('warehouse.return-v9.approve.i09');

        Route::post('/{id}/execute',[WarehouseReturnRequestV9Controller::class,'execute'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.execute,warehouse.procurement.return_request.update')
            ->name('warehouse.return-v9.execute.i09');

        Route::post('/{id}/cancel',[WarehouseReturnRequestV9Controller::class,'cancel'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.cancel,warehouse.procurement.return_request.delete')
            ->name('warehouse.return-v9.cancel.i09');

        Route::delete('/{id}',[WarehouseReturnRequestV9Controller::class,'destroy'])
            ->middleware('permission_or_snapshot:warehouse.procurement.return_request.delete')
            ->name('warehouse.return-v9.destroy.i09');
    });
