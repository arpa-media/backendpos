<?php

use App\Http\Controllers\Api\V1\Finance\FinanceCogsPostingController;
use App\Http\Controllers\Api\V1\Finance\FinanceGeneralLedgerProductionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/cogs-posting')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::get('/options',[FinanceCogsPostingController::class,'options'])->middleware('permission_or_snapshot:finance.cogs_posting.view')->name('finance.iter06.cogs.options');
    Route::get('/sources',[FinanceCogsPostingController::class,'sources'])->middleware('permission_or_snapshot:finance.cogs_posting.view')->name('finance.iter06.cogs.sources');
    Route::get('/mappings',[FinanceCogsPostingController::class,'mappings'])->middleware('permission_or_snapshot:finance.cogs_posting.view')->name('finance.iter06.cogs.mappings');
    Route::post('/mappings',[FinanceCogsPostingController::class,'createMapping'])->middleware('permission_or_snapshot:finance.cogs_posting.manage_mapping,finance.cogs_posting.update')->name('finance.iter06.cogs.mappings.create');
    Route::put('/mappings/{id}',[FinanceCogsPostingController::class,'updateMapping'])->middleware('permission_or_snapshot:finance.cogs_posting.manage_mapping,finance.cogs_posting.update')->name('finance.iter06.cogs.mappings.update');
    Route::delete('/mappings/{id}',[FinanceCogsPostingController::class,'deleteMapping'])->middleware('permission_or_snapshot:finance.cogs_posting.manage_mapping,finance.cogs_posting.delete')->name('finance.iter06.cogs.mappings.delete');
    Route::post('/drafts',[FinanceCogsPostingController::class,'createDraft'])->middleware('permission_or_snapshot:finance.cogs_posting.create')->name('finance.iter06.cogs.draft');
    Route::get('/{id}',[FinanceCogsPostingController::class,'show'])->middleware('permission_or_snapshot:finance.cogs_posting.view')->name('finance.iter06.cogs.show');
    Route::post('/{id}/refresh',[FinanceCogsPostingController::class,'refresh'])->middleware('permission_or_snapshot:finance.cogs_posting.update')->name('finance.iter06.cogs.refresh');
    Route::get('/{id}/preview',[FinanceCogsPostingController::class,'preview'])->middleware('permission_or_snapshot:finance.cogs_posting.view,finance.cogs_posting.update')->name('finance.iter06.cogs.preview');
    Route::post('/{id}/post',[FinanceCogsPostingController::class,'post'])->middleware('permission_or_snapshot:finance.cogs_posting.post,finance.cogs_posting.update')->name('finance.iter06.cogs.post');
    Route::post('/{id}/reopen',[FinanceCogsPostingController::class,'reopen'])->middleware('permission_or_snapshot:finance.cogs_posting.reopen,finance.cogs_posting.update')->name('finance.iter06.cogs.reopen');
    Route::delete('/{id}',[FinanceCogsPostingController::class,'destroy'])->middleware('permission_or_snapshot:finance.cogs_posting.delete')->name('finance.iter06.cogs.destroy');
});

Route::prefix('api/v1/finance/general-ledger')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::get('/production-tree',[FinanceGeneralLedgerProductionController::class,'tree'])
        ->middleware('permission_or_snapshot:finance.general_ledger.view')->name('finance.iter06.general-ledger.production-tree');
    Route::get('/accounts/{id}/export',[FinanceGeneralLedgerProductionController::class,'export'])
        ->middleware('permission_or_snapshot:finance.general_ledger.view')->name('finance.iter06.general-ledger.export');
});
