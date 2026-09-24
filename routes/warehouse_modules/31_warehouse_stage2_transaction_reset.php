<?php
use App\Http\Controllers\Api\V1\Warehouse\Admin\WarehouseTransactionResetController;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/v3/transaction-reset')->middleware(['api','auth:sanctum'])->group(function():void{
    Route::get('/',[WarehouseTransactionResetController::class,'preview'])->middleware('permission_or_snapshot:warehouse.reset_transactions.view')->name('warehouse.v3.transaction-reset.preview');
    Route::post('/',[WarehouseTransactionResetController::class,'reset'])->middleware('permission_or_snapshot:warehouse.reset_transactions.update')->name('warehouse.v3.transaction-reset.run');
});
