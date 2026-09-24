<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseInventoryCatalogCheckCommand extends Command
{
    protected $signature = 'warehouse:inventory-catalog-check';

    protected $description = 'Memeriksa kontrak All Item, batch, barcode, dan pricing Warehouse Iterasi 03.';

    public function handle(): int
    {
        $requiredTables = [
            'stk_skus', 'wh_sku_uoms', 'wh_batches', 'wh_batch_balances',
            'wh_stock_units', 'wh_outlet_price_policies', 'wh_scan_events',
        ];
        $requiredSkuColumns = ['brand_id', 'purchase_uom_id', 'purchase_conversion_factor', 'price_min', 'price_max'];
        $requiredRoutes = [
            'warehouse.inventory.options', 'warehouse.inventory.items.index', 'warehouse.inventory.items.store',
            'warehouse.inventory.item-uoms.index', 'warehouse.inventory.batches.index',
            'warehouse.inventory.barcodes.index', 'warehouse.inventory.barcodes.generate',
            'warehouse.inventory.barcodes.print-data', 'warehouse.inventory.barcodes.validate-scan',
            'warehouse.inventory.outlet-prices.index',
        ];
        $requiredMenus = [
            'warehouse-inventory-items', 'warehouse-inventory-batches',
            'warehouse-inventory-barcodes', 'warehouse-inventory-outlet-prices',
        ];
        $requiredPermissions = [
            'warehouse.inventory.item.view', 'warehouse.inventory.batch.view',
            'warehouse.inventory.barcode.view', 'warehouse.inventory.price.view',
            'warehouse.inventory.barcode.print', 'warehouse.inventory.barcode.scan',
            'warehouse.inventory.item.uom.manage',
        ];

        $missingTables = collect($requiredTables)->reject(fn ($table) => Schema::hasTable($table))->values();
        $missingSkuColumns = collect($requiredSkuColumns)->reject(fn ($column) => Schema::hasTable('stk_skus') && Schema::hasColumn('stk_skus', $column))->values();
        $missingRoutes = collect($requiredRoutes)->reject(fn ($name) => Route::has($name))->values();
        $missingMenus = Schema::hasTable('access_menus')
            ? collect($requiredMenus)->reject(fn ($code) => DB::table('access_menus')->where('code', $code)->exists())->values()
            : collect($requiredMenus);
        $missingPermissions = Schema::hasTable('permissions')
            ? collect($requiredPermissions)->reject(fn ($name) => DB::table('permissions')->where('name', $name)->exists())->values()
            : collect($requiredPermissions);

        $duplicateSkuUoms = Schema::hasTable('wh_sku_uoms')
            ? DB::table('wh_sku_uoms')->select('sku_id', 'uom_id')->groupBy('sku_id', 'uom_id')->havingRaw('COUNT(*) > 1')->count()
            : -1;
        $duplicatePolicies = Schema::hasTable('wh_outlet_price_policies')
            ? DB::table('wh_outlet_price_policies')->select('warehouse_id', 'outlet_id', 'sku_id')->groupBy('warehouse_id', 'outlet_id', 'sku_id')->havingRaw('COUNT(*) > 1')->count()
            : -1;
        $invalidBarcodeTrace = $this->invalidBarcodeTraceCount();
        $invalidPriceBands = Schema::hasTable('wh_batches')
            ? DB::table('wh_batches')->whereNull('deleted_at')->whereRaw('price_min > price_avg OR price_avg > price_max')->count()
            : -1;
        $aggregateVariance = $this->aggregateVarianceCount();
        $baseUomConversionInvalid = $this->baseUomConversionInvalidCount();

        $passed = $missingTables->isEmpty()
            && $missingSkuColumns->isEmpty()
            && $missingRoutes->isEmpty()
            && $missingMenus->isEmpty()
            && $missingPermissions->isEmpty()
            && $duplicateSkuUoms === 0
            && $duplicatePolicies === 0
            && $invalidBarcodeTrace === 0
            && $invalidPriceBands === 0
            && $aggregateVariance === 0
            && $baseUomConversionInvalid === 0;

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->implode(', ') ?: '-'],
            ['Missing stk_skus columns', $missingSkuColumns->implode(', ') ?: '-'],
            ['Missing named routes', $missingRoutes->implode(', ') ?: '-'],
            ['Missing Access Matrix menus', $missingMenus->implode(', ') ?: '-'],
            ['Missing permissions', $missingPermissions->implode(', ') ?: '-'],
            ['Duplicate SKU-UoM rows', (string) $duplicateSkuUoms],
            ['Duplicate outlet price policies', (string) $duplicatePolicies],
            ['Invalid barcode trace rows', (string) $invalidBarcodeTrace],
            ['Invalid batch price bands', (string) $invalidPriceBands],
            ['Aggregate vs batch variance SKU', (string) $aggregateVariance],
            ['Invalid base UoM conversion', (string) $baseUomConversionInvalid],
            ['Status', $passed ? 'PASSED' : 'FAILED'],
        ]);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function invalidBarcodeTraceCount(): int
    {
        if (! Schema::hasTable('wh_stock_units')) {
            return -1;
        }

        return DB::table('wh_stock_units as unit')
            ->leftJoin('wh_batches as batch', 'batch.id', '=', 'unit.batch_id')
            ->leftJoin('wh_storages as storage', 'storage.id', '=', 'unit.storage_id')
            ->where(function ($query): void {
                $query->whereNull('batch.id')
                    ->orWhereNull('storage.id')
                    ->orWhereColumn('unit.warehouse_id', '!=', 'batch.warehouse_id')
                    ->orWhereColumn('unit.sku_id', '!=', 'batch.sku_id')
                    ->orWhereColumn('unit.warehouse_id', '!=', 'storage.warehouse_id');
            })
            ->count();
    }

    private function aggregateVarianceCount(): int
    {
        if (! Schema::hasTable('stk_inventory_balances') || ! Schema::hasTable('wh_batch_balances')) {
            return -1;
        }

        $aggregate = DB::table('stk_inventory_balances as balance')
            ->join('outlets as warehouse', 'warehouse.id', '=', 'balance.outlet_id')
            ->whereRaw("LOWER(COALESCE(warehouse.type, '')) = 'warehouse'")
            ->select('balance.outlet_id as warehouse_id', 'balance.sku_id', 'balance.on_hand_qty');
        $batch = DB::table('wh_batch_balances')
            ->selectRaw('warehouse_id, sku_id, SUM(on_hand_qty) as batch_qty')
            ->groupBy('warehouse_id', 'sku_id');

        return DB::query()->fromSub($aggregate, 'aggregate')
            ->leftJoinSub($batch, 'batch', function ($join): void {
                $join->on('batch.warehouse_id', '=', 'aggregate.warehouse_id')
                    ->on('batch.sku_id', '=', 'aggregate.sku_id');
            })
            ->whereRaw('ABS(aggregate.on_hand_qty - COALESCE(batch.batch_qty, 0)) > 0.0001')
            ->count();
    }

    private function baseUomConversionInvalidCount(): int
    {
        if (! Schema::hasTable('wh_sku_uoms')) {
            return -1;
        }

        return DB::table('stk_skus as sku')
            ->leftJoin('wh_sku_uoms as conversion', function ($join): void {
                $join->on('conversion.sku_id', '=', 'sku.id')
                    ->on('conversion.uom_id', '=', 'sku.base_uom_id');
            })
            ->whereNull('sku.deleted_at')
            ->where(function ($query): void {
                $query->whereNull('conversion.id')->orWhereRaw('ABS(conversion.conversion_factor - 1) > 0.00000001');
            })
            ->count();
    }
}
