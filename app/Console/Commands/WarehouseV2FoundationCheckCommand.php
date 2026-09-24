<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseV2FoundationCheckCommand extends Command
{
    protected $signature = 'warehouse:v2-foundation-check';

    protected $description = 'Memeriksa fondasi Portal Warehouse v2, route, tabel sumber, dan Access Matrix.';

    public function handle(): int
    {
        $requiredTables = [
            'wh_batch_balances',
            'wh_ledger_postings',
            'wh_ledger_entries',
            'wh_receivings',
            'wh_receiving_items',
            'wh_stock_ins',
            'wh_stock_in_items',
            'access_portals',
            'access_menus',
            'access_role_menu_permissions',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table) => ! Schema::hasTable($table)));

        $requiredRoutes = ['warehouse.context', 'warehouse.v2.dashboard'];
        $missingRoutes = array_values(array_filter($requiredRoutes, fn (string $route) => ! Route::has($route)));

        $requiredMenus = ['warehouse-operations-dashboard', 'warehouse-sales-dashboard', 'warehouse-finance-dashboard'];
        $missingMenus = Schema::hasTable('access_menus')
            ? array_values(array_diff($requiredMenus, DB::table('access_menus')->whereIn('code', $requiredMenus)->pluck('code')->all()))
            : $requiredMenus;

        $ok = $missingTables === [] && $missingRoutes === [] && $missingMenus === [];
        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables === [] ? '-' : implode(', ', $missingTables)],
            ['Missing named routes', $missingRoutes === [] ? '-' : implode(', ', $missingRoutes)],
            ['Missing Access Matrix menus', $missingMenus === [] ? '-' : implode(', ', $missingMenus)],
            ['Status', $ok ? 'PASSED' : 'FAILED'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
