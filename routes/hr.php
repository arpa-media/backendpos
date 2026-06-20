<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HumanResource\HrMasterDataController;
use App\Http\Controllers\Api\V1\HumanResource\HrSquadController;
use App\Http\Controllers\Api\V1\HumanResource\HrOutletController;
use App\Http\Controllers\Api\V1\HumanResource\HrDashboardController;
use App\Http\Controllers\Api\V1\HumanResource\AttendancePortalController;

Route::prefix('api/v1')->middleware(['api'])->group(function () {
    Route::prefix('attendance')->group(function () {
        Route::post('/login', [AttendancePortalController::class, 'login']);

        Route::middleware(['auth:sanctum'])->group(function () {
            Route::get('/me', [AttendancePortalController::class, 'me']);
            Route::get('/dashboard', [AttendancePortalController::class, 'dashboard']);
            Route::post('/logout', [AttendancePortalController::class, 'logout']);
        });
    });

    /*
     * Template import dibuat seperti HR.zip: endpoint download template tidak
     * bergantung ke auth redirect. Ini mencegah error "Route [login] not defined"
     * ketika tombol download membuka URL langsung dari browser/window.open.
     * Endpoint ini hanya berisi header + sample format, bukan data karyawan.
     */
    Route::prefix('human-resource')->group(function () {
        Route::get('/squads/template', [HrSquadController::class, 'template']);
        Route::get('/squads/template-xlsx', [HrSquadController::class, 'templateXlsx']);
    });

    Route::middleware(['auth:sanctum', 'outlet_scope', 'outlet_timezone'])->group(function () {
        Route::prefix('human-resource')->middleware('permission:auth.me')->group(function () {
            Route::get('/dashboard', [HrDashboardController::class, 'index']);
            Route::get('/outlets', [HrOutletController::class, 'index']);
            Route::post('/outlets', [HrOutletController::class, 'store']);
            Route::get('/outlets/{id}', [HrOutletController::class, 'show']);
            Route::put('/outlets/{id}', [HrOutletController::class, 'update']);
            Route::post('/outlets/{id}', [HrOutletController::class, 'update']);
            Route::delete('/outlets/{id}', [HrOutletController::class, 'destroy']);

            Route::get('/master-data/options', [HrMasterDataController::class, 'options']);
            Route::get('/master-data', [HrMasterDataController::class, 'index']);
            Route::post('/master-data', [HrMasterDataController::class, 'store']);
            Route::put('/master-data/{id}', [HrMasterDataController::class, 'update']);
            Route::delete('/master-data/{id}', [HrMasterDataController::class, 'destroy']);

            Route::get('/salary-tiers', [HrMasterDataController::class, 'salaryTierIndex']);
            Route::post('/salary-tiers', [HrMasterDataController::class, 'salaryTierStore']);
            Route::put('/salary-tiers/{id}', [HrMasterDataController::class, 'salaryTierUpdate']);
            Route::delete('/salary-tiers/{id}', [HrMasterDataController::class, 'salaryTierDestroy']);

            Route::get('/squads/export', [HrSquadController::class, 'export']);
            Route::post('/squads/import', [HrSquadController::class, 'import']);
            Route::get('/squads', [HrSquadController::class, 'index']);
            Route::post('/squads', [HrSquadController::class, 'store']);
            Route::post('/squads/{id}/user/link', [HrSquadController::class, 'linkUser']);
            Route::post('/squads/{id}/user', [HrSquadController::class, 'createUser']);
            Route::get('/squads/{id}', [HrSquadController::class, 'show']);
            Route::post('/squads/{id}', [HrSquadController::class, 'update']);
            Route::put('/squads/{id}', [HrSquadController::class, 'update']);
            Route::delete('/squads/{id}', [HrSquadController::class, 'destroy']);
        });
    });
});
