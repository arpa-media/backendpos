<?php
use App\Http\Controllers\Api\V1\Warehouse\Reports\WarehouseReportControlController;use App\Http\Middleware\ResolveWarehouseScope;use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/reports-v2')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function(){
 Route::get('/executive',[WarehouseReportControlController::class,'executive'])->middleware('permission_or_snapshot:warehouse.report.executive.view');
 Route::get('/operations',[WarehouseReportControlController::class,'operations'])->middleware('permission_or_snapshot:warehouse.report.operations.view');
 Route::get('/finance',[WarehouseReportControlController::class,'finance'])->middleware('permission_or_snapshot:warehouse.report.finance.view');
 Route::get('/audit',[WarehouseReportControlController::class,'audit'])->middleware('permission_or_snapshot:warehouse.report.audit.view');
 Route::get('/export',[WarehouseReportControlController::class,'export'])->middleware('permission_or_snapshot:warehouse.report.export');
 Route::get('/reconciliation',[WarehouseReportControlController::class,'reconciliation'])->middleware('permission_or_snapshot:warehouse.control.reconciliation.view');
 Route::post('/reconciliation/run',[WarehouseReportControlController::class,'run'])->middleware('permission_or_snapshot:warehouse.control.reconciliation.run');
 Route::post('/reconciliation/exceptions/{id}/resolve',[WarehouseReportControlController::class,'resolve'])->middleware('permission_or_snapshot:warehouse.control.reconciliation.resolve');
});
