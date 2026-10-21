<?php

use App\Http\Controllers\Api\V1\Cogs\CogsRecipeScopeV13I22Controller;
use Illuminate\Support\Facades\Route;

// Additive to Ingredient Recipe page; no new Access Matrix menu needed.
Route::prefix('api/v1/cogs/recipe-scope-v13-i22')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/options', [CogsRecipeScopeV13I22Controller::class, 'options'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view');
        Route::get('/source-recipes', [CogsRecipeScopeV13I22Controller::class, 'sourceRecipes'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view');
        Route::get('/coverage', [CogsRecipeScopeV13I22Controller::class, 'coverage'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view');
        Route::get('/versions', [CogsRecipeScopeV13I22Controller::class, 'versions'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view');
        Route::post('/drafts', [CogsRecipeScopeV13I22Controller::class, 'createDraft'])
            ->middleware('permission_or_snapshot:cogs.ingredient.create');
        Route::put('/drafts/{id}', [CogsRecipeScopeV13I22Controller::class, 'updateDraft'])
            ->middleware('permission_or_snapshot:cogs.ingredient.update');
        Route::post('/versions/{id}/publish', [CogsRecipeScopeV13I22Controller::class, 'publish'])
            ->middleware('permission_or_snapshot:cogs.ingredient.update');
    });
