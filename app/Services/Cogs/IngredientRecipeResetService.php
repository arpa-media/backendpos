<?php

namespace App\Services\Cogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class IngredientRecipeResetService
{
    public function resetAll(string $confirmation): array
    {
        if ($confirmation !== 'HAPUS SEMUA RECIPE') {
            throw new RuntimeException('Konfirmasi tidak valid. Ketik tepat: HAPUS SEMUA RECIPE');
        }

        $consumptionCount = Schema::hasTable('cogs_sale_consumptions')
            ? (int) DB::table('cogs_sale_consumptions')->whereNotNull('recipe_id')->count()
            : 0;
        $consumptionItemCount = Schema::hasTable('cogs_sale_consumption_items')
            ? (int) DB::table('cogs_sale_consumption_items')->whereNotNull('recipe_item_id')->count()
            : 0;

        if ($consumptionCount > 0 || $consumptionItemCount > 0) {
            throw new RuntimeException("Reset dibatalkan karena recipe sudah dipakai: {$consumptionCount} consumption dan {$consumptionItemCount} consumption item.");
        }

        return DB::transaction(function (): array {
            $before = [
                'recipes' => (int) DB::table('cogs_recipes')->count(),
                'items' => (int) DB::table('cogs_recipe_items')->count(),
                'variant_links' => (int) DB::table('cogs_recipe_variant_links')->count(),
            ];

            DB::table('cogs_recipe_variant_links')->delete();
            DB::table('cogs_recipe_items')->delete();
            DB::table('cogs_recipes')->delete();

            return [
                'deleted' => $before,
                'remaining' => [
                    'recipes' => (int) DB::table('cogs_recipes')->count(),
                    'items' => (int) DB::table('cogs_recipe_items')->count(),
                    'variant_links' => (int) DB::table('cogs_recipe_variant_links')->count(),
                ],
            ];
        }, 3);
    }
}
