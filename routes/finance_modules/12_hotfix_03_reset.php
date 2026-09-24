<?php
use App\Http\Controllers\Api\V1\Finance\FinanceResetController;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/finance/reset')->middleware(['api','auth:sanctum','permission_or_snapshot:finance.reset.manage'])->group(function():void{
    Route::get('/',[FinanceResetController::class,'index'])->name('finance.hotfix03.reset.index');
    Route::post('/reconciliation/{id}',[FinanceResetController::class,'reconciliation'])->name('finance.hotfix03.reset.reconciliation');
    Route::post('/settlement/{kind}/{id}',[FinanceResetController::class,'settlement'])->name('finance.hotfix03.reset.settlement');
});
