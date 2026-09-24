<?php

use App\Http\Controllers\Api\V1\Finance\FinanceSettlementController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/settlements')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::get('/options',[FinanceSettlementController::class,'options'])->middleware('permission_or_snapshot:finance.settlement.view')->name('finance.iter06.settlement.options');
    Route::get('/mappings',[FinanceSettlementController::class,'mappings'])->middleware('permission_or_snapshot:finance.settlement.view')->name('finance.iter06.settlement.mappings');
    Route::post('/mappings',[FinanceSettlementController::class,'createMapping'])->middleware('permission_or_snapshot:finance.settlement.manage_mapping,finance.settlement.create')->name('finance.iter06.settlement.mappings.create');
    Route::put('/mappings/{id}',[FinanceSettlementController::class,'updateMapping'])->middleware('permission_or_snapshot:finance.settlement.manage_mapping,finance.settlement.update')->name('finance.iter06.settlement.mappings.update');
    Route::delete('/mappings/{id}',[FinanceSettlementController::class,'deleteMapping'])->middleware('permission_or_snapshot:finance.settlement.manage_mapping,finance.settlement.delete')->name('finance.iter06.settlement.mappings.delete');
    Route::get('/sources',[FinanceSettlementController::class,'sources'])->middleware('permission_or_snapshot:finance.settlement.view')->name('finance.iter06.settlement.sources');
    Route::get('/',[FinanceSettlementController::class,'index'])->middleware('permission_or_snapshot:finance.settlement.view')->name('finance.iter06.settlement.index');
    Route::post('/bulk/preview',[FinanceSettlementController::class,'bulkPreview'])->middleware('permission_or_snapshot:finance.settlement.create')->name('finance.iter06.settlement.bulk-preview');
    Route::post('/bulk/post',[FinanceSettlementController::class,'bulkPost'])->middleware('permission_or_snapshot:finance.settlement.post,finance.settlement.update')->name('finance.iter06.settlement.bulk-post');
    Route::post('/draft',[FinanceSettlementController::class,'createDraft'])->middleware('permission_or_snapshot:finance.settlement.create')->name('finance.iter06.settlement.draft');
    Route::get('/{id}',[FinanceSettlementController::class,'show'])->middleware('permission_or_snapshot:finance.settlement.view')->name('finance.iter06.settlement.show');
    Route::put('/{id}',[FinanceSettlementController::class,'update'])->middleware('permission_or_snapshot:finance.settlement.update')->name('finance.iter06.settlement.update');
    Route::post('/{id}/preview',[FinanceSettlementController::class,'preview'])->middleware('permission_or_snapshot:finance.settlement.view,finance.settlement.update')->name('finance.iter06.settlement.preview');
    Route::post('/{id}/post',[FinanceSettlementController::class,'post'])->middleware('permission_or_snapshot:finance.settlement.post,finance.settlement.update')->name('finance.iter06.settlement.post');
    Route::post('/{id}/reopen',[FinanceSettlementController::class,'reopen'])->middleware('permission_or_snapshot:finance.settlement.reopen,finance.settlement.update')->name('finance.iter06.settlement.reopen');
    Route::delete('/{id}',[FinanceSettlementController::class,'destroy'])->middleware('permission_or_snapshot:finance.settlement.delete')->name('finance.iter06.settlement.destroy');
});
