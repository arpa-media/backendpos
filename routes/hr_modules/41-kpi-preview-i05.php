<?php

use App\Http\Controllers\Api\V1\HumanResource\HrKpiPreviewI05Controller;
use Illuminate\Support\Facades\Route;

Route::get('/kpi-mapping/preview',[HrKpiPreviewI05Controller::class,'index'])
    ->middleware('permission_or_snapshot:hr.kpi.mapping.view')
    ->name('hr.kpi.i05.preview');
