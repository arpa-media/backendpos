<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CogsFoundationCheckCommand extends Command
{
    protected $signature = 'cogs:foundation-check';

    protected $description = 'Validate HPP/COGS portal foundation, UOM conversion contract, routes, permissions, and Access Matrix.';

    public function handle(): int
    {
        $requiredTables = [
            'stk_uoms',
            'stk_uom_conversions',
            'access_portals',
            'access_menus',
            'access_role_portal_permissions',
            'access_role_menu_permissions',
        ];
        $missingTables = collect($requiredTables)->reject(fn (string $table) => Schema::hasTable($table))->values();

        $requiredColumns = [
            'stk_uom_conversions' => [
                'id', 'from_uom_id', 'to_uom_id', 'conversion_factor', 'notes', 'is_active',
                'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at',
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
            'cogs.dashboard',
            'cogs.uom-conversions.catalogs',
            'cogs.uom-conversions.preview',
            'cogs.uom-conversions.index',
            'cogs.uom-conversions.store',
            'cogs.uom-conversions.update',
            'cogs.uom-conversions.destroy',
        ];
        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name) => $routeNames->contains($name))->values();

        $portalOk = Schema::hasTable('access_portals')
            && DB::table('access_portals')->where('code', 'warehouse')->where('is_active', true)->exists();
        $menuPaths = [
            '/portal/warehouse/dashboard',
            '/cogs/uom-conversions',
        ];
        $missingMenus = collect($menuPaths)->reject(fn (string $path) => Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('path', $path)->where('is_active', true)->exists())->values();

        $permissions = [
            'cogs.dashboard.view',
            'cogs.uom_conversion.view',
            'cogs.uom_conversion.create',
            'cogs.uom_conversion.update',
            'cogs.uom_conversion.delete',
        ];
        $missingPermissions = collect($permissions)->reject(fn (string $name) => Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $name)->exists())->values();

        $cycleCount = 0;
        if (Schema::hasTable('stk_uom_conversions')) {
            $cycleCount = $this->countCycles();
        }

        $failed = $missingTables->isNotEmpty()
            || $missingColumns->isNotEmpty()
            || $missingRoutes->isNotEmpty()
            || ! $portalOk
            || $missingMenus->isNotEmpty()
            || $missingPermissions->isNotEmpty()
            || $cycleCount > 0;

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->implode(', ')],
            ['Missing columns', $missingColumns->isEmpty() ? '-' : $missingColumns->implode(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->implode(', ')],
            ['HPP/COGS portal', $portalOk ? 'OK' : 'MISSING'],
            ['Missing Access Matrix menus', $missingMenus->isEmpty() ? '-' : $missingMenus->implode(', ')],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->implode(', ')],
            ['Active conversion cycles', (string) $cycleCount],
            ['Status', $failed ? 'FAILED' : 'PASSED'],
        ]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function countCycles(): int
    {
        $rows = DB::table('stk_uom_conversions')
            ->where('is_active', true)
            ->get(['from_uom_id', 'to_uom_id']);
        $adjacency = [];
        foreach ($rows as $row) {
            $adjacency[(string) $row->from_uom_id][] = (string) $row->to_uom_id;
        }

        $state = [];
        $cycles = 0;
        $visit = function (string $node) use (&$visit, &$state, &$cycles, $adjacency): void {
            $status = $state[$node] ?? 0;
            if ($status === 1) {
                $cycles++;
                return;
            }
            if ($status === 2) {
                return;
            }

            $state[$node] = 1;
            foreach ($adjacency[$node] ?? [] as $next) {
                $visit($next);
            }
            $state[$node] = 2;
        };

        foreach (array_keys($adjacency) as $node) {
            $visit($node);
        }

        return $cycles;
    }
}
