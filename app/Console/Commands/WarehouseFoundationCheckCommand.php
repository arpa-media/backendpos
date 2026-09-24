<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseFoundationCheckCommand extends Command
{
    protected $signature = 'warehouse:foundation-check';

    protected $description = 'Validate Warehouse portal foundation, routes, warehouse outlets, permission, and Access Matrix.';

    public function handle(): int
    {
        $warehouseCount = Schema::hasTable('outlets')
            ? DB::table('outlets')->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['', 'warehouse'])->where('is_active', true)->count()
            : 0;

        $portalOk = Schema::hasTable('access_portals')
            && DB::table('access_portals')->where('code', 'warehouse-operations')->where('is_active', true)->exists();
        $menuOk = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('path', '/warehouse/dashboard')->where('is_active', true)->exists();
        $permissionOk = Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', 'warehouse.dashboard.view')->exists();

        $checks = [
            ['Active warehouse outlets', $warehouseCount > 0 ? (string) $warehouseCount : 'MISSING'],
            ['Route warehouse.context', Route::has('warehouse.context') ? 'OK' : 'MISSING'],
            ['Route warehouse.dashboard', Route::has('warehouse.dashboard') ? 'OK' : 'MISSING'],
            ['Warehouse Operations portal', $portalOk ? 'OK' : 'MISSING'],
            ['Warehouse dashboard menu', $menuOk ? 'OK' : 'MISSING'],
            ['warehouse.dashboard.view', $permissionOk ? 'OK' : 'MISSING'],
        ];

        $failed = collect($checks)->contains(fn ($row) => in_array($row[1], ['MISSING', '0'], true));
        $checks[] = ['Status', $failed ? 'FAILED' : 'PASSED'];

        $this->table(['Check', 'Result'], $checks);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
