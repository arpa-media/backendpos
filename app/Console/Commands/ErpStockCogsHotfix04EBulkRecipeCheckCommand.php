<?php

namespace App\Console\Commands;

use App\Services\Cogs\IngredientRecipeBulkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class ErpStockCogsHotfix04EBulkRecipeCheckCommand extends Command
{
    protected $signature = 'erp-stock-cogs:hotfix-04e-check';

    protected $description = 'Validasi route dan service Bulk Ingredient Recipe Hotfix 04E';

    public function handle(): int
    {
        $routes = [
            'cogs.ingredient-recipes.bulk.publish',
            'cogs.ingredient-recipes.bulk.draft',
            'cogs.ingredient-recipes.bulk.sync-variants',
        ];

        $missing = collect($routes)->reject(fn (string $name) => Route::has($name))->values();
        $serviceReady = app()->bound(IngredientRecipeBulkService::class)
            || class_exists(IngredientRecipeBulkService::class);

        $this->table(['Check', 'Result'], [
            ['Missing named routes', $missing->isEmpty() ? '-' : $missing->implode(', ')],
            ['Bulk service', $serviceReady ? 'OK' : 'MISSING'],
            ['Status', $missing->isEmpty() && $serviceReady ? 'PASSED' : 'FAILED'],
        ]);

        return $missing->isEmpty() && $serviceReady ? self::SUCCESS : self::FAILURE;
    }
}
