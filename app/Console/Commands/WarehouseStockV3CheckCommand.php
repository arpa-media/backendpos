<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseStockV3CheckCommand extends Command
{
    protected $signature = 'warehouse:stock-v3-check';
    protected $description = 'Smoke check Warehouse v3 Iterasi 03 Stock Warehouse + price policy Excel flow.';

    public function handle(): int
    {
        $checks = [];
        $failed = false;

        $requiredTables = ['wh_storages', 'wh_batches', 'wh_outlet_price_policies', 'wh_customer_price_policies', 'wh_customers'];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
        $checks[] = ['Required tables', $missingTables === [] ? 'OK' : 'MISSING: '.implode(', ', $missingTables)];
        $failed = $failed || $missingTables !== [];

        $routeNames = [
            'warehouse.stock-v3.price-options',
            'warehouse.stock-v3.prices.index',
            'warehouse.stock-v3.prices.upsert',
            'warehouse.stock-v3.prices.destroy',
            'warehouse.stock-v3.prices.template',
            'warehouse.stock-v3.prices.export',
            'warehouse.stock-v3.prices.import',
        ];
        $missingRoutes = array_values(array_filter($routeNames, fn (string $name): bool => ! Route::has($name)));
        $checks[] = ['API routes', $missingRoutes === [] ? 'OK' : 'MISSING: '.implode(', ', $missingRoutes)];
        $failed = $failed || $missingRoutes !== [];

        if (Schema::hasTable('access_menus')) {
            $requiredPaths = [
                '/warehouse/inventory/items', '/warehouse/inventory/batches', '/warehouse/stock/storage',
                '/warehouse/stock/prices', '/warehouse/ledger/history',
            ];
            $missingMenus = [];
            foreach ($requiredPaths as $path) {
                if (! DB::table('access_menus')->where('path', $path)->where('is_active', true)->exists()) {
                    $missingMenus[] = $path;
                }
            }
            $checks[] = ['Access Matrix Stock menus', $missingMenus === [] ? 'OK' : 'MISSING/INACTIVE: '.implode(', ', $missingMenus)];
            $failed = $failed || $missingMenus !== [];

            $legacyBarcodeActive = DB::table('access_menus')
                ->where(function ($query): void {
                    $query->where('code', 'warehouse-inventory-barcodes')
                        ->orWhere('path', '/warehouse/inventory/barcodes');
                })
                ->where('is_active', true)
                ->exists();
            $checks[] = ['Legacy barcode menu', $legacyBarcodeActive ? 'STILL ACTIVE' : 'DISABLED'];
            $failed = $failed || $legacyBarcodeActive;
        }

        if (Schema::hasTable('outlets') && Schema::hasTable('wh_storages')) {
            $warehouseIds = DB::table('outlets')->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")->pluck('id');
            $missingDefault = [];
            foreach ($warehouseIds as $warehouseId) {
                if (! DB::table('wh_storages')->where('warehouse_id', $warehouseId)->whereRaw('UPPER(code) = ?', ['UNCATEGORIZED'])->whereNull('deleted_at')->where('is_active', true)->exists()) {
                    $missingDefault[] = (string) $warehouseId;
                }
            }
            $checks[] = ['UNCATEGORIZED storage', $missingDefault === [] ? 'OK' : 'MISSING FOR: '.implode(', ', $missingDefault)];
            $failed = $failed || $missingDefault !== [];
        }

        if (Schema::hasTable('wh_batches')) {
            $nullBatchStorage = DB::table('wh_batches')->whereNull('storage_id')->whereNull('deleted_at')->count();
            $checks[] = ['Batch storage NULL', $nullBatchStorage === 0 ? '0 / OK' : (string) $nullBatchStorage];
            $failed = $failed || $nullBatchStorage > 0;
        }

        $checks[] = ['Status', $failed ? 'FAILED' : 'PASS'];
        $this->table(['Check', 'Result'], $checks);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
