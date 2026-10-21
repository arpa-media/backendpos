<?php

use App\Http\Controllers\Api\V1\Cogs\HppV13I23Controller;
use Illuminate\Support\Facades\Route;

// Pure read-only HPP preview inside the existing Ingredient/Recipe Access Matrix scope.
Route::prefix('api/v1/cogs/hpp-v13-i23')
    ->middleware(['api','auth:sanctum','outlet_scope','outlet_timezone'])
    ->group(function (): void {
        Route::get('/overview',[HppV13I23Controller::class,'overview'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view');
        Route::get('/detail',[HppV13I23Controller::class,'detail'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view');
    });
