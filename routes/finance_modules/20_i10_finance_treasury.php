<?php

use App\Http\Controllers\Api\V1\Finance\FinanceTreasuryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/treasury')->middleware(['api','auth:sanctum'])->group(function():void{
    Route::get('/options',[FinanceTreasuryController::class,'options'])->middleware('permission_or_snapshot:finance.treasury.cash.in.view,finance.treasury.cash.out.view,finance.treasury.bank.in.view,finance.treasury.bank.out.view,finance.treasury.book.transfer.view');
    Route::post('/accounts',[FinanceTreasuryController::class,'storeAccount'])->middleware('permission_or_snapshot:finance.treasury.book.transfer.update,finance.treasury.cash.in.update,finance.treasury.cash.out.update,finance.treasury.bank.in.update,finance.treasury.bank.out.update');
    foreach(['cash_in','cash_out','bank_in','bank_out','book_transfer'] as $type){$perm='finance.treasury.'.str_replace('_','.',$type);
        Route::get("/{$type}",[FinanceTreasuryController::class,'index'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.view");
        Route::post("/{$type}",[FinanceTreasuryController::class,'store'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.create");
        Route::get("/{$type}/{id}",[FinanceTreasuryController::class,'show'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.view");
        Route::put("/{$type}/{id}",[FinanceTreasuryController::class,'update'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.update");
        Route::post("/{$type}/{id}/submit",[FinanceTreasuryController::class,'submit'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.submit,{$perm}.update");
        Route::post("/{$type}/{id}/approve",[FinanceTreasuryController::class,'approve'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.approve,{$perm}.update");
        Route::post("/{$type}/{id}/reject",[FinanceTreasuryController::class,'reject'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.approve,{$perm}.update");
        Route::post("/{$type}/{id}/cancel",[FinanceTreasuryController::class,'cancel'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.update");
    }
});
