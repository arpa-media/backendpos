<?php

namespace App\Console\Commands;

use App\Models\Cogs\IngredientRecipe;
use App\Services\Cogs\IngredientRecipeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CogsIngredientRecipeCheckCommand extends Command
{
    protected $signature = 'cogs:recipe-check {--sync-links : Rebuild physical product variant links before validation}';

    protected $description = 'Validate versioned Ingredient Recipe schema, routes, Access Matrix, snapshots, periods, and POS variant coverage.';

    public function handle(IngredientRecipeService $service): int
    {
        $requiredTables = [
            'cogs_recipes',
            'cogs_recipe_items',
            'cogs_recipe_variant_links',
            'products',
            'product_variants',
            'stk_skus',
            'stk_uoms',
            'stk_uom_conversions',
        ];
        $missingTables = collect($requiredTables)->reject(fn (string $table) => Schema::hasTable($table))->values();

        $requiredColumns = [
            'cogs_recipes' => [
                'id', 'product_id', 'variant_key', 'variant_name', 'version_no', 'status',
                'effective_from', 'effective_to', 'yield_quantity', 'notes', 'is_active',
                'created_by_user_id', 'updated_by_user_id', 'published_by_user_id', 'published_at',
                'created_at', 'updated_at', 'deleted_at',
            ],
            'cogs_recipe_items' => [
                'id', 'recipe_id', 'sku_id', 'input_quantity', 'input_uom_id', 'base_uom_id',
                'conversion_factor', 'base_quantity', 'waste_percentage', 'consumption_base_quantity',
                'conversion_snapshot', 'notes', 'sort_order',
            ],
            'cogs_recipe_variant_links' => ['id', 'recipe_id', 'product_variant_id'],
        ];
        $missingColumns = collect();
        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns->push("{$table}.{$column}");
                }
            }
        }

        $requiredRoutes = [
            'cogs.ingredient-recipes.catalogs',
            'cogs.ingredient-recipes.coverage',
            'cogs.ingredient-recipes.preview-item',
            'cogs.ingredient-recipes.index',
            'cogs.ingredient-recipes.store',
            'cogs.ingredient-recipes.show',
            'cogs.ingredient-recipes.update',
            'cogs.ingredient-recipes.clone-version',
            'cogs.ingredient-recipes.publish',
            'cogs.ingredient-recipes.sync-variants',
            'cogs.ingredient-recipes.destroy',
        ];
        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name) => $routeNames->contains($name))->values();

        $menuOk = Schema::hasTable('access_menus')
            && DB::table('access_menus')
                ->where('path', '/cogs/ingredient-recipes')
                ->where('is_active', true)
                ->exists();
        $requiredPermissions = collect(['view', 'create', 'update', 'delete'])
            ->map(fn (string $action) => 'cogs.ingredient.'.$action);
        $missingPermissions = $requiredPermissions->reject(fn (string $name) => Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $name)->exists())->values();

        $syncFailures = collect();
        if ($missingTables->isEmpty() && $this->option('sync-links')) {
            IngredientRecipe::query()->each(function (IngredientRecipe $recipe) use ($service, $syncFailures): void {
                try {
                    $service->syncVariantLinks($recipe);
                } catch (Throwable $exception) {
                    $syncFailures->push((string) $recipe->id.': '.$exception->getMessage());
                }
            });
        }

        $publishedWithoutItems = 0;
        $publishedWithoutLinks = 0;
        $publishedWithoutStart = 0;
        $invalidPeriods = 0;
        $invalidBaseUom = 0;
        $invalidBaseQuantity = 0;
        $invalidConsumptionQuantity = 0;
        $overlapCount = 0;
        if ($missingTables->isEmpty()) {
            $publishedWithoutItems = IngredientRecipe::query()->where('status', IngredientRecipe::STATUS_PUBLISHED)->doesntHave('items')->count();
            $publishedWithoutLinks = IngredientRecipe::query()->where('status', IngredientRecipe::STATUS_PUBLISHED)->doesntHave('variantLinks')->count();
            $publishedWithoutStart = IngredientRecipe::query()->where('status', IngredientRecipe::STATUS_PUBLISHED)->whereNull('effective_from')->count();
            $invalidPeriods = IngredientRecipe::query()
                ->whereNotNull('effective_from')
                ->whereNotNull('effective_to')
                ->whereColumn('effective_to', '<', 'effective_from')
                ->count();

            $invalidBaseUom = DB::table('cogs_recipe_items as item')
                ->join('stk_skus as sku', 'sku.id', '=', 'item.sku_id')
                ->whereColumn('item.base_uom_id', '!=', 'sku.base_uom_id')
                ->count();
            $invalidBaseQuantity = DB::table('cogs_recipe_items')
                ->whereRaw('ABS(base_quantity - (input_quantity * conversion_factor)) > 0.0001')
                ->count();
            $invalidConsumptionQuantity = DB::table('cogs_recipe_items')
                ->whereRaw('ABS(consumption_base_quantity - (base_quantity * (1 + waste_percentage / 100))) > 0.0001')
                ->count();

            $published = IngredientRecipe::query()
                ->where('status', IngredientRecipe::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->whereNotNull('effective_from')
                ->orderBy('product_id')
                ->orderBy('variant_key')
                ->orderBy('effective_from')
                ->get(['id', 'product_id', 'variant_key', 'effective_from', 'effective_to'])
                ->groupBy(fn (IngredientRecipe $recipe) => $recipe->product_id."\0".$recipe->variant_key);
            foreach ($published as $versions) {
                $list = $versions->values();
                for ($i = 0; $i < $list->count(); $i++) {
                    for ($j = $i + 1; $j < $list->count(); $j++) {
                        $a = $list[$i];
                        $b = $list[$j];
                        $aTo = $a->effective_to?->format('Y-m-d') ?? '9999-12-31';
                        $bTo = $b->effective_to?->format('Y-m-d') ?? '9999-12-31';
                        if ($a->effective_from->format('Y-m-d') <= $bTo && $b->effective_from->format('Y-m-d') <= $aTo) {
                            $overlapCount++;
                        }
                    }
                }
            }
        }

        $failed = $missingTables->isNotEmpty()
            || $missingColumns->isNotEmpty()
            || $missingRoutes->isNotEmpty()
            || ! $menuOk
            || $missingPermissions->isNotEmpty()
            || $syncFailures->isNotEmpty()
            || $publishedWithoutItems > 0
            || $publishedWithoutLinks > 0
            || $publishedWithoutStart > 0
            || $invalidPeriods > 0
            || $invalidBaseUom > 0
            || $invalidBaseQuantity > 0
            || $invalidConsumptionQuantity > 0
            || $overlapCount > 0;

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->implode(', ')],
            ['Missing columns', $missingColumns->isEmpty() ? '-' : $missingColumns->implode(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->implode(', ')],
            ['Ingredient Access Matrix menu', $menuOk ? 'OK' : 'MISSING'],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->implode(', ')],
            ['Variant link sync failures', $syncFailures->isEmpty() ? '-' : $syncFailures->implode(' | ')],
            ['Published recipes without items', (string) $publishedWithoutItems],
            ['Published recipes without variant links', (string) $publishedWithoutLinks],
            ['Published recipes without effective start', (string) $publishedWithoutStart],
            ['Invalid effective periods', (string) $invalidPeriods],
            ['Overlapping published periods', (string) $overlapCount],
            ['Items with wrong base UOM', (string) $invalidBaseUom],
            ['Items with invalid base quantity', (string) $invalidBaseQuantity],
            ['Items with invalid waste quantity', (string) $invalidConsumptionQuantity],
            ['Status', $failed ? 'FAILED' : 'PASSED'],
        ]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
