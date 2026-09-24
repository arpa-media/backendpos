<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseMasterDataCheckCommand extends Command
{
    protected $signature = 'warehouse:master-data-check';

    protected $description = 'Memeriksa kontrak Master Data Warehouse Iterasi 02.';

    public function handle(): int
    {
        $requiredTables = ['pur_supplier_sources', 'stk_categories', 'stk_uoms', 'wh_brands', 'wh_chain_supplies', 'wh_storages'];
        $requiredRoutes = [
            'warehouse.master.options',
            'warehouse.master.warehouses.index',
            'warehouse.master.suppliers.index',
            'warehouse.master.brands.index',
            'warehouse.master.categories.index',
            'warehouse.master.uoms.index',
            'warehouse.master.chain-supplies.index',
            'warehouse.master.storages.index',
        ];
        $requiredMenus = [
            'warehouse-master-warehouses',
            'warehouse-master-suppliers',
            'warehouse-master-brands',
            'warehouse-master-categories',
            'warehouse-master-uoms',
            'warehouse-master-chain-supplies',
            'warehouse-master-storages',
        ];
        $requiredPermissions = [
            'warehouse.master.warehouse.view',
            'warehouse.master.supplier.view',
            'warehouse.master.brand.view',
            'warehouse.master.category.view',
            'warehouse.master.uom.view',
            'warehouse.master.chain_supply.view',
            'warehouse.master.storage.view',
        ];

        $missingTables = collect($requiredTables)->reject(fn ($table) => Schema::hasTable($table))->values();
        $missingSupplierColumns = collect(['email', 'address', 'tax_number'])
            ->reject(fn ($column) => Schema::hasTable('pur_supplier_sources') && Schema::hasColumn('pur_supplier_sources', $column))
            ->values();
        $missingRoutes = collect($requiredRoutes)->reject(fn ($name) => Route::has($name))->values();
        $missingMenus = Schema::hasTable('access_menus')
            ? collect($requiredMenus)->reject(fn ($code) => DB::table('access_menus')->where('code', $code)->exists())->values()
            : collect($requiredMenus);
        $missingPermissions = Schema::hasTable('permissions')
            ? collect($requiredPermissions)->reject(fn ($name) => DB::table('permissions')->where('name', $name)->exists())->values()
            : collect($requiredPermissions);

        $warehouseCount = Schema::hasTable('outlets')
            ? DB::table('outlets')->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")->where('is_active', true)->count()
            : 0;
        $duplicateChainOutlets = Schema::hasTable('wh_chain_supplies')
            ? DB::table('wh_chain_supplies')->select('outlet_id')->groupBy('outlet_id')->havingRaw('COUNT(*) > 1')->count()
            : 0;
        $invalidStorageTypes = Schema::hasTable('wh_storages')
            ? DB::table('wh_storages')->whereNotIn('storage_type', ['shelves', 'box', 'freezer', 'basket', 'rack'])->count()
            : 0;

        $passed = $missingTables->isEmpty()
            && $missingSupplierColumns->isEmpty()
            && $missingRoutes->isEmpty()
            && $missingMenus->isEmpty()
            && $missingPermissions->isEmpty()
            && $warehouseCount > 0
            && $duplicateChainOutlets === 0
            && $invalidStorageTypes === 0;

        $this->table(['Check', 'Result'], [
            ['Active warehouse outlets', (string) $warehouseCount],
            ['Missing tables', $missingTables->implode(', ') ?: '-'],
            ['Missing supplier columns', $missingSupplierColumns->implode(', ') ?: '-'],
            ['Missing named routes', $missingRoutes->implode(', ') ?: '-'],
            ['Missing Access Matrix menus', $missingMenus->implode(', ') ?: '-'],
            ['Missing permissions', $missingPermissions->implode(', ') ?: '-'],
            ['Duplicate Chain Supply outlets', (string) $duplicateChainOutlets],
            ['Invalid Storage types', (string) $invalidStorageTypes],
            ['Status', $passed ? 'PASSED' : 'FAILED'],
        ]);

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
