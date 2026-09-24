<?php

namespace App\Console\Commands;

use App\Services\Warehouse\WarehouseReportingService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseReportingCheckCommand extends Command
{
    protected $signature = 'warehouse:reporting-check {--fail-on-issues : Return failure when operational reconciliation finds issues}';
    protected $description = 'Validate Warehouse Iterasi 11 dashboard, reporting, export, timezone, and operational reconciliation contracts.';

    public function handle(WarehouseReportingService $service): int
    {
        $tables = ['wh_report_runs', 'wh_operational_reconciliation_runs'];
        $routes = [
            'warehouse.analytics.dashboard', 'warehouse.reports.options', 'warehouse.reports.show',
            'warehouse.reports.export', 'warehouse.reports.print-event',
            'warehouse.reports.reconciliation.index', 'warehouse.reports.reconciliation.run',
        ];
        $menus = ['warehouse-reports-analytics', 'warehouse-operational-reconciliation'];
        $permissions = [
            'warehouse.reporting.view', 'warehouse.reporting.export', 'warehouse.reporting.print', 'warehouse.reporting.run',
            'warehouse.operational_reconciliation.view', 'warehouse.operational_reconciliation.export',
            'warehouse.operational_reconciliation.print', 'warehouse.operational_reconciliation.run',
        ];

        $missingTables = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
        $missingRoutes = array_values(array_filter($routes, fn (string $name): bool => ! Route::has($name)));
        $missingMenus = Schema::hasTable('access_menus')
            ? array_values(array_diff($menus, DB::table('access_menus')->whereIn('code', $menus)->pluck('code')->all()))
            : $menus;
        $missingPermissions = Schema::hasTable('permissions')
            ? array_values(array_diff($permissions, DB::table('permissions')->whereIn('name', $permissions)->pluck('name')->all()))
            : $permissions;

        $warehouseCount = Schema::hasTable('outlets')
            ? DB::table('outlets')->whereRaw("LOWER(COALESCE(type,'')) = 'warehouse'")->where('is_active', true)->count()
            : 0;
        $reportCount = count(WarehouseReportingService::REPORTS);
        $duplicateReportKeys = count(array_keys(WarehouseReportingService::REPORTS)) !== count(array_unique(array_keys(WarehouseReportingService::REPORTS))) ? 1 : 0;
        $invalidWarehouseTimezone = Schema::hasTable('outlets')
            ? DB::table('outlets')->whereRaw("LOWER(COALESCE(type,'')) = 'warehouse'")->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('timezone')->orWhere('timezone', ''))->count()
            : 0;

        $issueCount = 0;
        $ruleCount = 0;
        $warehouses = Schema::hasTable('outlets')
            ? DB::table('outlets')->whereRaw("LOWER(COALESCE(type,'')) = 'warehouse'")->where('is_active', true)->orderBy('name')->get()
            : collect();
        if ($warehouses->isNotEmpty() && $missingTables === []) {
            $request = Request::create('/api/v1/warehouse/reports/reconciliation', 'GET', [
                'scope' => 'all',
                'date_from' => now()->startOfMonth()->toDateString(),
                'date_to' => now()->toDateString(),
            ]);
            $request->attributes->set('warehouse_scope', [
                'selected' => $warehouses->first(),
                'warehouses' => $warehouses,
                'all_warehouse_count' => $warehouses->count(),
                'scope_locked' => false,
                'can_adjust_scope' => true,
            ]);
            $payload = $service->reconciliation($request);
            $issueCount = (int) ($payload['snapshot']['issue_count'] ?? 0);
            $ruleCount = (int) ($payload['snapshot']['checked_rule_count'] ?? 0);
        }

        $rows = [
            ['Missing tables', $missingTables ? implode(', ', $missingTables) : '-'],
            ['Missing named routes', $missingRoutes ? implode(', ', $missingRoutes) : '-'],
            ['Missing Access Matrix menus', $missingMenus ? implode(', ', $missingMenus) : '-'],
            ['Missing permissions', $missingPermissions ? implode(', ', $missingPermissions) : '-'],
            ['Active Warehouse outlets', (string) $warehouseCount],
            ['Registered report definitions', (string) $reportCount],
            ['Duplicate report keys', (string) $duplicateReportKeys],
            ['Warehouse without timezone', (string) $invalidWarehouseTimezone],
            ['Operational reconciliation rules', (string) $ruleCount],
            ['Operational reconciliation issues', (string) $issueCount],
        ];

        $failed = $missingTables !== [] || $missingRoutes !== [] || $missingMenus !== [] || $missingPermissions !== []
            || $warehouseCount === 0 || $reportCount < 10 || $duplicateReportKeys > 0 || $invalidWarehouseTimezone > 0
            || ($this->option('fail-on-issues') && $issueCount > 0);
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
