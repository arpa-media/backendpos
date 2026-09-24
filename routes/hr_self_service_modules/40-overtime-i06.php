<?php

use App\Http\Controllers\Api\V1\HumanResource\HrOvertimeSelfI06Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/attendance/overtime')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/context', [HrOvertimeSelfI06Controller::class, 'context'])->name('attendance.overtime-i06.context');
        Route::post('/start', [HrOvertimeSelfI06Controller::class, 'start'])->middleware('throttle:30,1')->name('attendance.overtime-i06.start');
        Route::post('/finish', [HrOvertimeSelfI06Controller::class, 'finish'])->middleware('throttle:30,1')->name('attendance.overtime-i06.finish');
    });
