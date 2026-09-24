<?php

use App\Http\Controllers\Api\V1\Warehouse\FinanceV3\WarehouseFinancePaymentPostingIteration04Controller;
use App\Http\Controllers\Api\V1\Warehouse\FinanceV4\WarehouseTreasuryV4Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use App\Http\Middleware\WarehouseTreasuryInvoicePaymentV4 as AutoTreasury;
use Illuminate\Support\Facades\Route;

/*
 | Iterasi 06 is intentionally loaded after Iterasi 05 (7 z's > 6 z's).
 | The invoice-payment route is re-declared so Treasury wraps the Iterasi 05
 | Finance auto-posting transaction without editing accepted patch files.
 */

Route::prefix('api/v1/warehouse/finance-v4/treasury')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function():void{
        Route::get('/options',[WarehouseTreasuryV4Controller::class,'options'])
            ->middleware('permission_or_snapshot:warehouse.finance.cash.in.view,warehouse.finance.cash.out.view,warehouse.finance.bank.in.view,warehouse.finance.bank.out.view,warehouse.finance.book.transfer.view')
            ->name('warehouse.finance-v4.treasury.options');
        Route::post('/accounts',[WarehouseTreasuryV4Controller::class,'storeAccount'])
            ->middleware('permission_or_snapshot:warehouse.finance.cash.in.create,warehouse.finance.cash.out.create,warehouse.finance.bank.in.create,warehouse.finance.bank.out.create,warehouse.finance.book.transfer.create')
            ->name('warehouse.finance-v4.treasury.accounts.store');

        foreach(['cash_in','cash_out','bank_in','bank_out','book_transfer'] as $type){
            $perm='warehouse.finance.'.str_replace('_','.',$type);
            Route::get("/{$type}",[WarehouseTreasuryV4Controller::class,'index'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.view")->name("warehouse.finance-v4.treasury.{$type}.index");
            Route::post("/{$type}",[WarehouseTreasuryV4Controller::class,'store'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.create")->name("warehouse.finance-v4.treasury.{$type}.store");
            Route::get("/{$type}/{id}",[WarehouseTreasuryV4Controller::class,'show'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.view")->name("warehouse.finance-v4.treasury.{$type}.show");
            Route::put("/{$type}/{id}",[WarehouseTreasuryV4Controller::class,'update'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.update")->name("warehouse.finance-v4.treasury.{$type}.update");
            Route::post("/{$type}/{id}/submit",[WarehouseTreasuryV4Controller::class,'submit'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.submit,{$perm}.update")->name("warehouse.finance-v4.treasury.{$type}.submit");
            Route::post("/{$type}/{id}/approve",[WarehouseTreasuryV4Controller::class,'approve'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.approve,{$perm}.update")->name("warehouse.finance-v4.treasury.{$type}.approve");
            Route::post("/{$type}/{id}/reject",[WarehouseTreasuryV4Controller::class,'reject'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.approve,{$perm}.update")->name("warehouse.finance-v4.treasury.{$type}.reject");
            Route::post("/{$type}/{id}/cancel",[WarehouseTreasuryV4Controller::class,'cancel'])->defaults('type',$type)->middleware("permission_or_snapshot:{$perm}.update")->name("warehouse.finance-v4.treasury.{$type}.cancel");
        }
    });

// Final invoice payment route: Treasury owns account-level payment GP and Treasury document atomically.
Route::prefix('api/v1/warehouse/finance-v3-lifecycle')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    foreach(['incoming','outgoing'] as $direction){
        $update=$direction==='incoming'?'warehouse.purchasing.invoice.update':'warehouse.finance.invoice.update';
        Route::post("/{$direction}/invoices/{source}/{id}/payments",[WarehouseFinancePaymentPostingIteration04Controller::class,'payment'])
            ->where('source','auto_incoming|auto_outgoing|legacy_outgoing|manual')->defaults('direction',$direction)
            ->middleware(["permission_or_snapshot:{$update}",AutoTreasury::class])
            ->name("warehouse.finance-v3-lifecycle.{$direction}.payment");
    }
});
