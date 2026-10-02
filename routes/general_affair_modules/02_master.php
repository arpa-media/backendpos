<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairMasterCategoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/master')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        $definitions = [
            'damage-categories' => ['type' => 'damage', 'permission' => 'ga.master.damage'],
            'cctv-categories' => ['type' => 'cctv', 'permission' => 'ga.master.cctv'],
            'costing-categories' => ['type' => 'costing', 'permission' => 'ga.master.costing'],
        ];

        foreach ($definitions as $slug => $definition) {
            Route::get($slug, [GeneralAffairMasterCategoryController::class, 'index'])
                ->defaults('masterType', $definition['type'])
                ->middleware('permission_or_snapshot:'.$definition['permission'].'.view')
                ->name('general-affair.i02.'.$definition['type'].'.index');

            Route::post($slug, [GeneralAffairMasterCategoryController::class, 'store'])
                ->defaults('masterType', $definition['type'])
                ->middleware('permission_or_snapshot:'.$definition['permission'].'.create')
                ->name('general-affair.i02.'.$definition['type'].'.store');

            Route::put($slug.'/{id}', [GeneralAffairMasterCategoryController::class, 'update'])
                ->defaults('masterType', $definition['type'])
                ->middleware('permission_or_snapshot:'.$definition['permission'].'.update')
                ->name('general-affair.i02.'.$definition['type'].'.update');

            Route::delete($slug.'/{id}', [GeneralAffairMasterCategoryController::class, 'destroy'])
                ->defaults('masterType', $definition['type'])
                ->middleware('permission_or_snapshot:'.$definition['permission'].'.delete')
                ->name('general-affair.i02.'.$definition['type'].'.destroy');
        }
    });
