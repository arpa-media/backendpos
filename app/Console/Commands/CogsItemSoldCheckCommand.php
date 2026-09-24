<?php

namespace App\Console\Commands;

use App\Models\Cogs\SaleConsumption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CogsItemSoldCheckCommand extends Command
{
    protected $signature = 'cogs:item-sold-check';

    protected $description = 'Validate Item Sold schema, routes, Access Matrix, idempotency, movements, reversals, and exception queue.';

    public function handle(): int
    {
        $requiredTables = [
            'cogs_sale_consumptions', 'cogs_sale_consumption_items', 'cogs_recipes', 'cogs_recipe_items',
            'sales', 'sale_items', 'stk_inventory_balances', 'stk_inventory_movements',
        ];
        $missingTables = collect($requiredTables)->reject(fn (string $table) => Schema::hasTable($table))->values();

        $requiredColumns = [
            'cogs_sale_consumptions' => [
                'id', 'outlet_id', 'sale_id', 'sale_item_id', 'product_id', 'product_variant_id', 'recipe_id',
                'movement_type', 'status', 'business_date', 'business_timezone', 'sale_number_snapshot',
                'product_name_snapshot', 'variant_name_snapshot', 'sold_quantity', 'recipe_yield_quantity',
                'total_base_quantity', 'total_cost', 'movement_count', 'exception_code', 'exception_message',
                'source_fingerprint', 'idempotency_key', 'processed_at', 'reversed_at',
                'reversed_by_consumption_id', 'resolved_at', 'resolved_by_consumption_id',
            ],
            'cogs_sale_consumption_items' => [
                'id', 'consumption_id', 'original_consumption_item_id', 'recipe_item_id', 'sku_id', 'base_uom_id',
                'quantity_per_sold_base', 'quantity_base', 'movement_quantity', 'unit_cost_snapshot', 'total_cost',
                'balance_qty_before', 'balance_qty_after', 'average_cost_before', 'average_cost_after',
                'inventory_value_before', 'inventory_value_after', 'inventory_movement_id',
            ],
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
            'cogs.item-sold.catalogs', 'cogs.item-sold.overview', 'cogs.item-sold.ingredients',
            'cogs.item-sold.exceptions', 'cogs.item-sold.exceptions.retry', 'cogs.item-sold.process',
            'cogs.item-sold.export', 'cogs.item-sold.index', 'cogs.item-sold.show',
        ];
        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name) => $routeNames->contains($name))->values();

        $menuOk = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('path', '/cogs/item-sold')->where('is_active', true)->exists();
        $requiredPermissions = collect(['view', 'create', 'update', 'delete'])->map(fn ($action) => 'cogs.item_sold.'.$action);
        $missingPermissions = $requiredPermissions->reject(fn ($name) => Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $name)->exists())->values();

        $postedWithoutItems = 0;
        $postedWithoutMovement = 0;
        $headerMovementMismatch = 0;
        $invalidMovementSigns = 0;
        $activeInvalidSales = 0;
        $reversedWithoutReversal = 0;
        $openExceptionsWithoutCode = 0;
        $reversalNetMismatch = 0;

        if ($missingTables->isEmpty()) {
            $postedWithoutItems = DB::table('cogs_sale_consumptions as c')
                ->leftJoin('cogs_sale_consumption_items as ci', 'ci.consumption_id', '=', 'c.id')
                ->whereIn('c.movement_type', [SaleConsumption::TYPE_CONSUMPTION, SaleConsumption::TYPE_REVERSAL])
                ->where('c.status', SaleConsumption::STATUS_POSTED)
                ->whereNull('ci.id')->count();

            $postedWithoutMovement = DB::table('cogs_sale_consumption_items as ci')
                ->join('cogs_sale_consumptions as c', 'c.id', '=', 'ci.consumption_id')
                ->where('c.status', SaleConsumption::STATUS_POSTED)
                ->whereNull('ci.inventory_movement_id')->count();

            $movementMismatchQuery = DB::table('cogs_sale_consumptions as c')
                ->leftJoin('cogs_sale_consumption_items as ci', 'ci.consumption_id', '=', 'c.id')
                ->whereIn('c.movement_type', [SaleConsumption::TYPE_CONSUMPTION, SaleConsumption::TYPE_REVERSAL])
                ->select('c.id')
                ->groupBy('c.id', 'c.movement_count')
                ->havingRaw('COUNT(ci.id) <> c.movement_count');
            $headerMovementMismatch = DB::query()->fromSub($movementMismatchQuery, 'movement_mismatch')->count();

            $invalidMovementSigns = DB::table('cogs_sale_consumption_items as ci')
                ->join('cogs_sale_consumptions as c', 'c.id', '=', 'ci.consumption_id')
                ->where(function ($query): void {
                    $query->where(function ($nested): void {
                        $nested->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
                            ->where('ci.movement_quantity', '>=', 0);
                    })->orWhere(function ($nested): void {
                        $nested->where('c.movement_type', SaleConsumption::TYPE_REVERSAL)
                            ->where('ci.movement_quantity', '<=', 0);
                    });
                })->count();

            $activeInvalidSales = DB::table('cogs_sale_consumptions as c')
                ->leftJoin('sales as s', 's.id', '=', 'c.sale_id')
                ->leftJoin('sale_items as si', 'si.id', '=', 'c.sale_item_id')
                ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
                ->where('c.status', SaleConsumption::STATUS_POSTED)
                ->where(function ($query): void {
                    $query->whereNull('s.id')
                        ->orWhere('s.status', '!=', 'PAID')
                        ->orWhereNotNull('s.deleted_at')
                        ->orWhereNull('si.id')
                        ->orWhereNotNull('si.voided_at');
                })->count();

            $reversedWithoutReversal = DB::table('cogs_sale_consumptions')
                ->where('movement_type', SaleConsumption::TYPE_CONSUMPTION)
                ->where('status', SaleConsumption::STATUS_REVERSED)
                ->whereNull('reversed_by_consumption_id')->count();

            $openExceptionsWithoutCode = DB::table('cogs_sale_consumptions')
                ->where('movement_type', SaleConsumption::TYPE_EXCEPTION)
                ->where('status', SaleConsumption::STATUS_OPEN)
                ->where(function ($query): void {
                    $query->whereNull('exception_code')->orWhere('exception_code', '');
                })->count();

            $reversalNetMismatch = DB::table('cogs_sale_consumption_items as original_item')
                ->join('cogs_sale_consumptions as original', 'original.id', '=', 'original_item.consumption_id')
                ->leftJoin('cogs_sale_consumption_items as reversal_item', 'reversal_item.original_consumption_item_id', '=', 'original_item.id')
                ->where('original.movement_type', SaleConsumption::TYPE_CONSUMPTION)
                ->where('original.status', SaleConsumption::STATUS_REVERSED)
                ->where(function ($query): void {
                    $query->whereNull('reversal_item.id')
                        ->orWhereRaw('ABS(ABS(original_item.movement_quantity) - reversal_item.movement_quantity) > 0.0001')
                        ->orWhereRaw('ABS(original_item.total_cost - reversal_item.total_cost) > 0.01');
                })->count();
        }

        $failed = $missingTables->isNotEmpty()
            || $missingColumns->isNotEmpty()
            || $missingRoutes->isNotEmpty()
            || ! $menuOk
            || $missingPermissions->isNotEmpty()
            || $postedWithoutItems > 0
            || $postedWithoutMovement > 0
            || $headerMovementMismatch > 0
            || $invalidMovementSigns > 0
            || $activeInvalidSales > 0
            || $reversedWithoutReversal > 0
            || $openExceptionsWithoutCode > 0
            || $reversalNetMismatch > 0;

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->implode(', ')],
            ['Missing columns', $missingColumns->isEmpty() ? '-' : $missingColumns->implode(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->implode(', ')],
            ['Item Sold Access Matrix menu', $menuOk ? 'OK' : 'MISSING'],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->implode(', ')],
            ['Posted headers without items', (string) $postedWithoutItems],
            ['Posted items without movement', (string) $postedWithoutMovement],
            ['Header movement count mismatch', (string) $headerMovementMismatch],
            ['Invalid movement signs', (string) $invalidMovementSigns],
            ['Active consumptions on invalid sale', (string) $activeInvalidSales],
            ['Reversed originals without reversal link', (string) $reversedWithoutReversal],
            ['Open exceptions without code', (string) $openExceptionsWithoutCode],
            ['Reversal quantity/cost mismatch', (string) $reversalNetMismatch],
            ['Status', $failed ? 'FAILED' : 'PASSED'],
        ]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
