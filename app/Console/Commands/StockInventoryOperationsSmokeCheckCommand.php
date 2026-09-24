<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class StockInventoryOperationsSmokeCheckCommand extends Command
{
    protected $signature = 'stock-inventory:smoke-check-operations';
    protected $description = 'Validate Receive Stock and Manual Stock database, routes, and Access Matrix wiring.';

    public function handle(): int
    {
        $requiredTables = [
            'stk_goods_receipts',
            'stk_goods_receipt_items',
            'stk_inventory_balances',
            'stk_inventory_movements',
        ];
        $requiredRoutes = [
            'stock-inventory.receive-stock.catalogs',
            'stock-inventory.receive-stock.index',
            'stock-inventory.receive-stock.release',
            'stock-inventory.manual-stock.catalogs',
            'stock-inventory.manual-stock.index',
            'stock-inventory.manual-stock.post',
        ];
        $requiredMenus = [
            'inventory-receive-stock',
            'inventory-manual-stock',
        ];

        $missingTables = array_values(array_filter($requiredTables, fn ($table) => ! Schema::hasTable($table)));
        $missingRoutes = array_values(array_filter($requiredRoutes, fn ($name) => ! Route::has($name)));
        $missingMenus = [];

        if (! Schema::hasTable('access_menus')) {
            $missingMenus = $requiredMenus;
        } else {
            $existingMenus = DB::table('access_menus')->whereIn('code', $requiredMenus)->pluck('code')->all();
            $missingMenus = array_values(array_diff($requiredMenus, $existingMenus));
        }

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
