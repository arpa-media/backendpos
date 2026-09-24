<?php
use App\Http\Controllers\Api\V1\Purchasing\PurchasingReconciliationController;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/purchasing/reconciliation')->middleware(['api','auth:sanctum'])->group(function():void{
 Route::get('/catalogs',[PurchasingReconciliationController::class,'catalogs'])->middleware('permission_or_snapshot:purchasing.reconciliation.view')->name('purchasing.reconciliation.catalogs');
 Route::get('/runs',[PurchasingReconciliationController::class,'runs'])->middleware('permission_or_snapshot:purchasing.reconciliation.view')->name('purchasing.reconciliation.runs');
 Route::get('/',[PurchasingReconciliationController::class,'index'])->middleware('permission_or_snapshot:purchasing.reconciliation.view')->name('purchasing.reconciliation.index');
 Route::post('/scan',[PurchasingReconciliationController::class,'scan'])->middleware('permission_or_snapshot:purchasing.reconciliation.create,purchasing.reconciliation.scan')->name('purchasing.reconciliation.scan');
 Route::post('/apply',[PurchasingReconciliationController::class,'apply'])->middleware('permission_or_snapshot:purchasing.reconciliation.update,purchasing.reconciliation.apply')->name('purchasing.reconciliation.apply');
 Route::get('/{id}',[PurchasingReconciliationController::class,'show'])->middleware('permission_or_snapshot:purchasing.reconciliation.view')->name('purchasing.reconciliation.show');
 Route::post('/issues/{id}/resolve',[PurchasingReconciliationController::class,'resolve'])->middleware('permission_or_snapshot:purchasing.reconciliation.update,purchasing.reconciliation.resolve')->name('purchasing.reconciliation.issues.resolve');
});
