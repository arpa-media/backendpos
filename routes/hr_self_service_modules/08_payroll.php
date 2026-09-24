<?php

use App\Http\Controllers\Api\V1\HumanResource\HrPayrollSelfController;
use App\Services\HrUserDashboardService;
use App\Services\HumanResource\HrUserDashboardPayrollService;
use Illuminate\Support\Facades\Route;

app()->bind(HrUserDashboardService::class, HrUserDashboardPayrollService::class);

Route::prefix('api/v1/human-resource/self')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::get('/payroll-slips',[HrPayrollSelfController::class,'index'])->middleware('permission_or_snapshot:hr.payroll.self.view')->name('hr.self.payroll.index');
    Route::get('/payroll-slips/{id}',[HrPayrollSelfController::class,'show'])->middleware('permission_or_snapshot:hr.payroll.self.view')->name('hr.self.payroll.show');
});
