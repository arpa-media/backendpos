<?php

namespace App\Console\Commands;

use App\Models\Cogs\StockVariance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CogsStockVarianceCheckCommand extends Command
{
    protected $signature = 'cogs:stock-variance-check';

    protected $description = 'Validate Stock Variance schema, routes, Access Matrix, formulas, item coverage, and Stock Opname traceability.';

    public function handle(): int
    {
        $requiredTables = [
            'cogs_stock_variances', 'cogs_stock_variance_items',
            'stk_stock_opnames', 'stk_stock_opname_items', 'stk_inventory_movements',
        ];
        $missingTables = collect($requiredTables)->reject(fn (string $table) => Schema::hasTable($table))->values();

        $requiredColumns = [
            'cogs_stock_variances' => [
                'id', 'outlet_id', 'stock_opname_id', 'previous_stock_opname_id', 'variance_date', 'opening_date',
                'status', 'source_fingerprint', 'sku_count', 'shortage_sku_count', 'surplus_sku_count',
                'zero_cost_sku_count', 'missing_opening_sku_count', 'open_exception_count',
                'uncounted_movement_sku_count', 'theoretical_qty_total', 'actual_qty_total', 'variance_qty_total',
                'shortage_value', 'surplus_value', 'net_variance_value', 'absolute_variance_value',
                'calculated_at', 'submitted_at', 'cancelled_at',
            ],
            'cogs_stock_variance_items' => [
                'id', 'stock_variance_id', 'stock_opname_item_id', 'sku_id', 'base_uom_id',
                'opening_actual_qty', 'goods_receipt_qty', 'sale_consumption_qty', 'other_movement_qty',
                'movement_qty', 'theoretical_qty', 'actual_qty', 'variance_qty', 'unit_cost_snapshot',
                'shortage_value', 'surplus_value', 'net_variance_value', 'movement_count', 'warning_codes',
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
            'cogs.stock-variance.overview', 'cogs.stock-variance.candidates',
            'cogs.stock-variance.calculate', 'cogs.stock-variance.submit',
            'cogs.stock-variance.export', 'cogs.stock-variance.index', 'cogs.stock-variance.show',
        ];
        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name) => $routeNames->contains($name))->values();

        $menuOk = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('path', '/cogs/stock-variance')->where('is_active', true)->exists();
        $requiredPermissions = collect(['view', 'create', 'update', 'delete'])->map(fn ($action) => 'cogs.stock_variance.'.$action);
        $missingPermissions = $requiredPermissions->reject(fn ($name) => Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $name)->exists())->values();

        $activeWithoutItems = 0;
        $itemCountMismatch = 0;
        $itemFormulaMismatch = 0;
        $headerFormulaMismatch = 0;
        $submittedWithInvalidSource = 0;
        $submittedOpnamesWithoutVariance = 0;
        $attentionDocuments = 0;

        if ($missingTables->isEmpty()) {
            $activeWithoutItems = DB::table('cogs_stock_variances as variance')
                ->leftJoin('cogs_stock_variance_items as item', 'item.stock_variance_id', '=', 'variance.id')
                ->whereIn('variance.status', [StockVariance::STATUS_CALCULATED, StockVariance::STATUS_SUBMITTED])
                ->whereNull('item.id')
                ->count();

            $countMismatch = DB::table('cogs_stock_variances as variance')
                ->leftJoin('cogs_stock_variance_items as item', 'item.stock_variance_id', '=', 'variance.id')
                ->whereIn('variance.status', [StockVariance::STATUS_CALCULATED, StockVariance::STATUS_SUBMITTED])
                ->groupBy('variance.id', 'variance.sku_count')
                ->havingRaw('COUNT(item.id) <> variance.sku_count')
                ->select('variance.id');
            $itemCountMismatch = DB::query()->fromSub($countMismatch, 'variance_count_mismatch')->count();

            $itemFormulaMismatch = DB::table('cogs_stock_variance_items')
                ->whereRaw('ABS(theoretical_qty - (opening_actual_qty + movement_qty)) > 0.0001')
                ->orWhereRaw('ABS(variance_qty - (actual_qty - theoretical_qty)) > 0.0001')
                ->orWhereRaw('ABS(net_variance_value - ROUND(variance_qty * unit_cost_snapshot, 2)) > 0.01')
                ->count();

            $headerTotals = DB::table('cogs_stock_variances as variance')
                ->join('cogs_stock_variance_items as item', 'item.stock_variance_id', '=', 'variance.id')
                ->whereIn('variance.status', [StockVariance::STATUS_CALCULATED, StockVariance::STATUS_SUBMITTED])
                ->groupBy(
                    'variance.id', 'variance.theoretical_qty_total', 'variance.actual_qty_total',
                    'variance.variance_qty_total', 'variance.shortage_value', 'variance.surplus_value',
                    'variance.net_variance_value', 'variance.absolute_variance_value',
                )
                ->havingRaw('ABS(SUM(item.theoretical_qty) - variance.theoretical_qty_total) > 0.0001')
                ->orHavingRaw('ABS(SUM(item.actual_qty) - variance.actual_qty_total) > 0.0001')
                ->orHavingRaw('ABS(SUM(item.variance_qty) - variance.variance_qty_total) > 0.0001')
                ->orHavingRaw('ABS(SUM(item.shortage_value) - variance.shortage_value) > 0.01')
                ->orHavingRaw('ABS(SUM(item.surplus_value) - variance.surplus_value) > 0.01')
                ->orHavingRaw('ABS(SUM(item.net_variance_value) - variance.net_variance_value) > 0.01')
                ->orHavingRaw('ABS(SUM(ABS(item.net_variance_value)) - variance.absolute_variance_value) > 0.01')
                ->select('variance.id');
            $headerFormulaMismatch = DB::query()->fromSub($headerTotals, 'variance_total_mismatch')->count();

            $submittedWithInvalidSource = DB::table('cogs_stock_variances as variance')
                ->leftJoin('stk_stock_opnames as opname', 'opname.id', '=', 'variance.stock_opname_id')
                ->where('variance.status', StockVariance::STATUS_SUBMITTED)
                ->where(function ($query): void {
                    $query->whereNull('opname.id')->orWhere('opname.status', '!=', 'submitted');
                })->count();

            $submittedOpnamesWithoutVariance = DB::table('stk_stock_opnames as opname')
                ->leftJoin('cogs_stock_variances as variance', 'variance.stock_opname_id', '=', 'opname.id')
                ->where('opname.status', 'submitted')
                ->whereNull('variance.id')
                ->count();

            $attentionDocuments = DB::table('cogs_stock_variances')
                ->whereIn('status', [StockVariance::STATUS_CALCULATED, StockVariance::STATUS_SUBMITTED])
                ->whereRaw('(open_exception_count + zero_cost_sku_count + missing_opening_sku_count + uncounted_movement_sku_count) > 0')
                ->count();
        }

        $failed = $missingTables->isNotEmpty()
            || $missingColumns->isNotEmpty()
            || $missingRoutes->isNotEmpty()
            || ! $menuOk
            || $missingPermissions->isNotEmpty()
            || $activeWithoutItems > 0
            || $itemCountMismatch > 0
            || $itemFormulaMismatch > 0
            || $headerFormulaMismatch > 0
            || $submittedWithInvalidSource > 0;

        $status = $failed ? 'FAILED' : ($submittedOpnamesWithoutVariance > 0 || $attentionDocuments > 0 ? 'PASSED_WITH_ATTENTION' : 'PASSED');
        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->implode(', ')],
            ['Missing columns', $missingColumns->isEmpty() ? '-' : $missingColumns->implode(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->implode(', ')],
            ['Stock Variance Access Matrix menu', $menuOk ? 'OK' : 'MISSING'],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->implode(', ')],
            ['Active documents without items', (string) $activeWithoutItems],
            ['Header item count mismatch', (string) $itemCountMismatch],
            ['Item formula mismatch', (string) $itemFormulaMismatch],
            ['Header total mismatch', (string) $headerFormulaMismatch],
            ['Submitted variance with invalid opname', (string) $submittedWithInvalidSource],
            ['Submitted opnames without variance', (string) $submittedOpnamesWithoutVariance],
            ['Variance documents with attention', (string) $attentionDocuments],
            ['Status', $status],
        ]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
