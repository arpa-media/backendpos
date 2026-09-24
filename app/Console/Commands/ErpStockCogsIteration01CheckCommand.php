<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpStockCogsIteration01CheckCommand extends Command
{
    protected $signature = 'erp-stock-cogs:iteration-01-check';

    protected $description = 'Smoke check Par Stock saved-only flow, removed Receive Stock menu, and HPP/COGS Access Matrix metadata.';

    public function handle(): int
    {
        $rows = [];
        $failed = false;

        $requiredRoutes = [
            ['GET', 'api/v1/stock-inventory/dashboard'],
            ['GET', 'api/v1/stock-inventory/par-stocks'],
            ['GET', 'api/v1/stock-inventory/par-stocks/catalogs'],
            ['PUT', 'api/v1/stock-inventory/par-stocks'],
            ['DELETE', 'api/v1/stock-inventory/par-stocks/{skuId}'],
        ];

        $routeCollection = Route::getRoutes();
        foreach ($requiredRoutes as [$method, $uri]) {
            $found = collect($routeCollection->getRoutes())->contains(function ($route) use ($method, $uri): bool {
                return in_array($method, $route->methods(), true) && $route->uri() === $uri;
            });
            $rows[] = ["Route {$method} {$uri}", $found ? 'OK' : 'MISSING'];
            $failed = $failed || ! $found;
        }

        foreach (['stk_par_stocks', 'access_portals', 'access_menus'] as $table) {
            $exists = Schema::hasTable($table);
            $rows[] = ["Table {$table}", $exists ? 'OK' : 'MISSING'];
            $failed = $failed || ! $exists;
        }

        if (Schema::hasTable('access_menus')) {
            $dashboard = DB::table('access_menus')->where('code', 'warehouse-dashboard')->first();
            $dashboardOk = $dashboard
                && (bool) $dashboard->is_active
                && $dashboard->permission_view === 'cogs.dashboard.view'
                && $dashboard->permission_create === 'cogs.dashboard.create'
                && $dashboard->permission_update === 'cogs.dashboard.update'
                && $dashboard->permission_delete === 'cogs.dashboard.delete';
            $rows[] = ['HPP/COGS dashboard permission metadata', $dashboardOk ? 'OK' : 'FAILED'];
            $failed = $failed || ! $dashboardOk;

            if (Schema::hasTable('permissions')) {
                $requiredDashboardPermissions = [
                    'cogs.dashboard.view',
                    'cogs.dashboard.create',
                    'cogs.dashboard.update',
                    'cogs.dashboard.delete',
                ];
                $permissionCount = DB::table('permissions')->whereIn('name', $requiredDashboardPermissions)->count();
                $permissionsOk = $permissionCount === count($requiredDashboardPermissions);
                $rows[] = ['Spatie dashboard permissions', $permissionsOk ? 'OK' : "FAILED ({$permissionCount}/".count($requiredDashboardPermissions).')'];
                $failed = $failed || ! $permissionsOk;
            }

            $cogsCodes = [
                'warehouse-dashboard',
                'cogs-uom-conversion',
                'cogs-ingredient-recipes',
                'cogs-history-stock',
                'cogs-item-sold',
                'cogs-stock-variance',
                'cogs-calculation',
            ];
            $activeCogsCount = DB::table('access_menus')->whereIn('code', $cogsCodes)->where('is_active', true)->count();
            $cogsOk = $activeCogsCount === count($cogsCodes);
            $rows[] = ['Canonical HPP/COGS menus active', $cogsOk ? 'OK' : "FAILED ({$activeCogsCount}/".count($cogsCodes).')'];
            $failed = $failed || ! $cogsOk;

            $receive = DB::table('access_menus')
                ->where(function ($query): void {
                    $query->where('code', 'inventory-receive-stock')
                        ->orWhere('path', '/stock-inventory/receive-stock');
                })
                ->first();
            $receiveHidden = ! $receive || ! (bool) $receive->is_active;
            $rows[] = ['Receive Stock menu inactive', $receiveHidden ? 'OK' : 'FAILED'];
            $failed = $failed || ! $receiveHidden;
        }

        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
