<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairBillDueDateController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/bill-due-date')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::get('/meta', [GeneralAffairBillDueDateController::class,'meta'])->middleware('permission_or_snapshot:ga.bill_due_date.view');
    Route::get('/analytics', [GeneralAffairBillDueDateController::class,'analytics'])->middleware('permission_or_snapshot:ga.bill_due_date.view');
    Route::get('/template-xlsx', [GeneralAffairBillDueDateController::class,'template'])->middleware('permission_or_snapshot:ga.bill_due_date.view,ga.bill_due_date.create');
    Route::get('/export-xlsx', [GeneralAffairBillDueDateController::class,'export'])->middleware('permission_or_snapshot:ga.bill_due_date.export,ga.bill_due_date.view');
    Route::post('/import-xlsx', [GeneralAffairBillDueDateController::class,'import'])->middleware(['permission_or_snapshot:ga.bill_due_date.import,ga.bill_due_date.create','permission_or_snapshot:ga.bill_due_date.import,ga.bill_due_date.update']);
    Route::post('/bulk', [GeneralAffairBillDueDateController::class,'bulkStore'])->middleware(['permission_or_snapshot:ga.bill_due_date.create','permission_or_snapshot:ga.bill_due_date.update']);
    Route::get('/', [GeneralAffairBillDueDateController::class,'index'])->middleware('permission_or_snapshot:ga.bill_due_date.view');
    Route::post('/', [GeneralAffairBillDueDateController::class,'store'])->middleware('permission_or_snapshot:ga.bill_due_date.create');
    Route::put('/{id}', [GeneralAffairBillDueDateController::class,'update'])->middleware('permission_or_snapshot:ga.bill_due_date.update');
    Route::delete('/{id}', [GeneralAffairBillDueDateController::class,'destroy'])->middleware('permission_or_snapshot:ga.bill_due_date.delete');
});
