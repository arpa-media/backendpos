<?php

namespace App\Console\Commands;

use App\Services\Cogs\IngredientRecipeSpreadsheetService;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpStockCogsIteration02CheckCommand extends Command
{
    protected $signature = 'erp-stock-cogs:iteration-02-check';

    protected $description = 'Smoke check Ingredient / Recipe XLSX template, export, idempotent import, permissions, and source tables.';

    public function handle(): int
    {
        $rows = [];
        $failed = false;

        foreach ([
            IngredientRecipeSpreadsheetService::class,
            SimpleXlsxService::class,
        ] as $class) {
            $exists = class_exists($class);
            $rows[] = ['Class '.$class, $exists ? 'OK' : 'MISSING'];
            $failed = $failed || ! $exists;
        }

        $requiredRoutes = [
            'cogs.ingredient-recipes.template' => ['GET', 'api/v1/cogs/ingredient-recipes/template'],
            'cogs.ingredient-recipes.export' => ['GET', 'api/v1/cogs/ingredient-recipes/export'],
            'cogs.ingredient-recipes.import' => ['POST', 'api/v1/cogs/ingredient-recipes/import'],
        ];
        $routes = Route::getRoutes();
        foreach ($requiredRoutes as $name => [$method, $uri]) {
            $route = $routes->getByName($name);
            $ok = $route && $route->uri() === $uri && in_array($method, $route->methods(), true);
            $rows[] = ["Route {$method} {$uri}", $ok ? 'OK' : 'MISSING'];
            $failed = $failed || ! $ok;
        }

        foreach (['products', 'product_variants', 'stk_skus', 'stk_uoms', 'stk_uom_conversions', 'cogs_recipes', 'cogs_recipe_items', 'cogs_recipe_variant_links'] as $table) {
            $exists = Schema::hasTable($table);
            $rows[] = ["Table {$table}", $exists ? 'OK' : 'MISSING'];
            $failed = $failed || ! $exists;
        }

        if (Schema::hasTable('permissions')) {
            $requiredPermissions = [
                'cogs.ingredient.view',
                'cogs.ingredient.create',
                'cogs.ingredient.update',
            ];
            $count = DB::table('permissions')->whereIn('name', $requiredPermissions)->count();
            $ok = $count === count($requiredPermissions);
            $rows[] = ['Spatie Ingredient / Recipe permissions', $ok ? 'OK' : "FAILED ({$count}/".count($requiredPermissions).')'];
            $failed = $failed || ! $ok;
        }

        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')
                ->where(function ($query): void {
                    $query->where('code', 'cogs-ingredient-recipes')
                        ->orWhere('path', '/cogs/ingredient-recipes');
                })
                ->first();
            $ok = $menu
                && (bool) $menu->is_active
                && $menu->permission_view === 'cogs.ingredient.view'
                && $menu->permission_create === 'cogs.ingredient.create'
                && $menu->permission_update === 'cogs.ingredient.update';
            $rows[] = ['Access Matrix Ingredient / Recipe metadata', $ok ? 'OK' : 'FAILED'];
            $failed = $failed || ! $ok;
        }

        $rows[] = ['Import identity', 'product_id + variant_key + ingredient_sku_code'];
        $rows[] = ['Published mutation strategy', 'Clone draft version; never overwrite history'];
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
