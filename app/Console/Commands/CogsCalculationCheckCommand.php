<?php

namespace App\Console\Commands;

use App\Models\Cogs\CogsCalculationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CogsCalculationCheckCommand extends Command
{
    protected $signature = 'cogs:calculation-check';

    protected $description = 'Validate final COGS calculation schema, routes, Access Matrix, formulas, reconciliation, and closing integrity.';

    public function handle(): int
    {
        $tables = ['cogs_calculation_runs', 'cogs_calculation_items'];
        $missingTables = collect($tables)->reject(fn (string $table) => Schema::hasTable($table))->values();
        $requiredColumns = [
            'cogs_calculation_runs' => [
                'id', 'outlet_id', 'period_from', 'period_to', 'status', 'opening_variance_id', 'closing_variance_id',
                'source_fingerprint', 'net_sales_value', 'purchasing_value', 'net_recipe_cogs_value',
                'net_variance_value', 'variance_adjustment_value', 'opening_inventory_value', 'closing_inventory_value',
                'inventory_bridge_cogs_value', 'final_cogs_value', 'reconciliation_difference', 'attention_count',
                'data_quality_score', 'calculated_at', 'reconciled_at', 'closed_at',
            ],
            'cogs_calculation_items' => [
                'id', 'calculation_run_id', 'sku_id', 'base_uom_id', 'opening_value', 'receipt_value',
                'net_consumption_value', 'other_movement_value', 'closing_value', 'variance_value',
                'final_cogs_value', 'inventory_bridge_cogs_value', 'reconciliation_difference', 'warning_codes',
            ],
        ];
        $missingColumns = collect();
        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) continue;
            foreach ($columns as $column) if (! Schema::hasColumn($table, $column)) $missingColumns->push("{$table}.{$column}");
        }

        $requiredRoutes = [
            'cogs.calculation.overview', 'cogs.calculation.calculate', 'cogs.calculation.reconcile',
            'cogs.calculation.close', 'cogs.calculation.cancel', 'cogs.calculation.export',
            'cogs.calculation.index', 'cogs.calculation.show',
        ];
        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name) => $routeNames->contains($name))->values();
        $menuOk = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('path', '/cogs/calculation')->where('is_active', true)->exists();
        $requiredPermissions = collect(['view', 'create', 'update', 'delete'])->map(fn ($action) => 'cogs.calculation.'.$action);
        $missingPermissions = $requiredPermissions->reject(fn ($name) => Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $name)->exists())->values();

        $activeWithoutItems = 0;
        $headerFormulaMismatch = 0;
        $itemFormulaMismatch = 0;
        $closedWithoutAudit = 0;
        $closedWithBlockingAttention = 0;
        $reconciledWithLargeDifference = 0;

        if ($missingTables->isEmpty()) {
            $activeWithoutItems = DB::table('cogs_calculation_runs as run')
                ->leftJoin('cogs_calculation_items as item', 'item.calculation_run_id', '=', 'run.id')
                ->whereIn('run.status', [
                    CogsCalculationRun::STATUS_CALCULATED,
                    CogsCalculationRun::STATUS_RECONCILED,
                    CogsCalculationRun::STATUS_CLOSED,
                ])
                ->whereNull('item.id')->count();

            $headerFormulaMismatch = DB::table('cogs_calculation_runs')
                ->whereRaw('ABS(variance_adjustment_value + net_variance_value) > 0.01')
                ->orWhereRaw('ABS(final_cogs_value - (net_recipe_cogs_value + variance_adjustment_value)) > 0.01')
                ->orWhereRaw('ABS(inventory_bridge_cogs_value - (opening_inventory_value + purchasing_value + other_movement_value - closing_inventory_value)) > 0.01')
                ->orWhereRaw('ABS(reconciliation_difference - (final_cogs_value - inventory_bridge_cogs_value)) > 0.01')
                ->count();

            $itemFormulaMismatch = DB::table('cogs_calculation_items')
                ->whereRaw('ABS(net_consumption_value - (consumption_value - reversal_value)) > 0.01')
                ->orWhereRaw('ABS(final_cogs_value - (net_consumption_value - variance_value)) > 0.01')
                ->orWhereRaw('ABS(inventory_bridge_cogs_value - (opening_value + receipt_value + other_movement_value - closing_value)) > 0.01')
                ->orWhereRaw('ABS(reconciliation_difference - (final_cogs_value - inventory_bridge_cogs_value)) > 0.01')
                ->count();

            $closedWithoutAudit = DB::table('cogs_calculation_runs')
                ->where('status', CogsCalculationRun::STATUS_CLOSED)
                ->where(function ($query): void {
                    $query->whereNull('closed_at')->orWhereNull('closed_by_user_id')->orWhereNull('source_fingerprint');
                })->count();

            $closedWithBlockingAttention = DB::table('cogs_calculation_runs')
                ->where('status', CogsCalculationRun::STATUS_CLOSED)
                ->where('attention_count', '>', 0)
                ->where(function ($query): void {
                    $query->whereNull('close_notes')->orWhere('close_notes', '');
                })->count();

            $reconciledWithLargeDifference = DB::table('cogs_calculation_runs')
                ->whereIn('status', [CogsCalculationRun::STATUS_RECONCILED, CogsCalculationRun::STATUS_CLOSED])
                ->whereRaw('ABS(reconciliation_difference) > 1.00')
                ->count();
        }

        $failed = $missingTables->isNotEmpty() || $missingColumns->isNotEmpty() || $missingRoutes->isNotEmpty()
            || ! $menuOk || $missingPermissions->isNotEmpty() || $activeWithoutItems > 0
            || $headerFormulaMismatch > 0 || $itemFormulaMismatch > 0 || $closedWithoutAudit > 0
            || $closedWithBlockingAttention > 0;
        $status = $failed ? 'FAILED' : ($reconciledWithLargeDifference > 0 ? 'PASSED_WITH_ATTENTION' : 'PASSED');

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->implode(', ')],
            ['Missing columns', $missingColumns->isEmpty() ? '-' : $missingColumns->implode(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->implode(', ')],
            ['COGS Calculation Access Matrix menu', $menuOk ? 'OK' : 'MISSING'],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->implode(', ')],
            ['Active runs without items', (string) $activeWithoutItems],
            ['Header formula mismatch', (string) $headerFormulaMismatch],
            ['Item formula mismatch', (string) $itemFormulaMismatch],
            ['Closed runs without audit', (string) $closedWithoutAudit],
            ['Closed attention without notes', (string) $closedWithBlockingAttention],
            ['Reconciled difference > tolerance', (string) $reconciledWithLargeDifference],
            ['Status', $status],
        ]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
