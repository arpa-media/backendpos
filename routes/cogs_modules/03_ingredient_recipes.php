<?php

use App\Http\Controllers\Api\V1\Cogs\IngredientRecipeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/ingredient-recipes')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/catalogs', [IngredientRecipeController::class, 'catalogs'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view,cogs.ingredient.create,cogs.ingredient.update')
            ->name('cogs.ingredient-recipes.catalogs');
        Route::get('/template', [IngredientRecipeController::class, 'template'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view')
            ->name('cogs.ingredient-recipes.template');
        Route::get('/export', [IngredientRecipeController::class, 'export'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view')
            ->name('cogs.ingredient-recipes.export');
        Route::post('/import', [IngredientRecipeController::class, 'import'])
            ->middleware([
                'permission_or_snapshot:cogs.ingredient.create',
                'permission_or_snapshot:cogs.ingredient.update',
            ])
            ->name('cogs.ingredient-recipes.import');
        Route::post('/reset-all', [IngredientRecipeController::class, 'resetAll'])
            ->middleware('permission_or_snapshot:cogs.ingredient.delete')
            ->name('cogs.ingredient-recipes.reset-all');
        Route::post('/bulk/generate-draft', [IngredientRecipeController::class, 'bulkGenerateDraft'])
            ->middleware('permission_or_snapshot:cogs.ingredient.create')
            ->name('cogs.ingredient-recipes.bulk-generate-draft');
        Route::post('/bulk/publish', [IngredientRecipeController::class, 'bulkPublish'])
            ->middleware('permission_or_snapshot:cogs.ingredient.update')
            ->name('cogs.ingredient-recipes.bulk.publish');
        Route::post('/bulk/draft', [IngredientRecipeController::class, 'bulkDraft'])
            ->middleware(['permission_or_snapshot:cogs.ingredient.create', 'permission_or_snapshot:cogs.ingredient.update'])
            ->name('cogs.ingredient-recipes.bulk.draft');
        Route::post('/bulk/sync-variants', [IngredientRecipeController::class, 'bulkSyncVariants'])
            ->middleware('permission_or_snapshot:cogs.ingredient.update')
            ->name('cogs.ingredient-recipes.bulk.sync-variants');
        Route::get('/coverage', [IngredientRecipeController::class, 'coverage'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view')
            ->name('cogs.ingredient-recipes.coverage');
        Route::post('/preview-item', [IngredientRecipeController::class, 'previewItem'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view,cogs.ingredient.create,cogs.ingredient.update')
            ->name('cogs.ingredient-recipes.preview-item');
        Route::get('/', [IngredientRecipeController::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view')
            ->name('cogs.ingredient-recipes.index');
        Route::post('/', [IngredientRecipeController::class, 'store'])
            ->middleware('permission_or_snapshot:cogs.ingredient.create')
            ->name('cogs.ingredient-recipes.store');
        Route::get('/{id}', [IngredientRecipeController::class, 'show'])
            ->middleware('permission_or_snapshot:cogs.ingredient.view')
            ->name('cogs.ingredient-recipes.show');
        Route::put('/{id}', [IngredientRecipeController::class, 'update'])
            ->middleware('permission_or_snapshot:cogs.ingredient.update')
            ->name('cogs.ingredient-recipes.update');
        Route::post('/{id}/clone-version', [IngredientRecipeController::class, 'cloneVersion'])
            ->middleware('permission_or_snapshot:cogs.ingredient.create')
            ->name('cogs.ingredient-recipes.clone-version');
        Route::post('/{id}/publish', [IngredientRecipeController::class, 'publish'])
            ->middleware('permission_or_snapshot:cogs.ingredient.update')
            ->name('cogs.ingredient-recipes.publish');
        Route::post('/{id}/sync-variants', [IngredientRecipeController::class, 'syncVariants'])
            ->middleware('permission_or_snapshot:cogs.ingredient.update')
            ->name('cogs.ingredient-recipes.sync-variants');
        Route::delete('/{id}', [IngredientRecipeController::class, 'destroy'])
            ->middleware('permission_or_snapshot:cogs.ingredient.delete')
            ->name('cogs.ingredient-recipes.destroy');
    });
