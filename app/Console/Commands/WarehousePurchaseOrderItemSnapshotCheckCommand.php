<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehousePurchaseOrderItemSnapshotCheckCommand extends Command
{
    protected $signature = 'warehouse:smoke-check-po-item-snapshot';

    protected $description = 'Check Warehouse Supplier PO item name and UOM snapshots.';

    public function handle(): int
    {
        $columns = ['sku_code_snapshot', 'item_name_snapshot', 'base_uom_code_snapshot', 'base_uom_name_snapshot'];
        $missingColumns = collect($columns)
            ->reject(fn (string $column): bool => Schema::hasColumn('wh_supplier_purchase_order_items', $column))
            ->values();

        $blankRows = 0;
        $relationMismatch = 0;
        if ($missingColumns->isEmpty() && Schema::hasTable('wh_supplier_purchase_order_items')) {
            $blankRows = DB::table('wh_supplier_purchase_order_items')
                ->where(function ($query): void {
                    $query->whereNull('item_name_snapshot')->orWhere('item_name_snapshot', '')
                        ->orWhereNull('base_uom_code_snapshot')->orWhere('base_uom_code_snapshot', '');
                })
                ->count();

            $relationMismatch = DB::table('wh_supplier_purchase_order_items as poi')
                ->join('wh_purchase_request_items as pri', 'pri.id', '=', 'poi.purchase_request_item_id')
                ->where(function ($query): void {
                    $query->whereColumn('poi.sku_id', '<>', 'pri.sku_id')
                        ->orWhereColumn('poi.base_uom_id', '<>', 'pri.base_uom_id');
                })
                ->count();
        }

        $service = app_path('Services/Warehouse/WarehouseProcurementService.php');
        $serviceSource = is_file($service) ? (string) file_get_contents($service) : '';
        $serviceOk = str_contains($serviceSource, 'item_name_snapshot')
            && str_contains($serviceSource, 'items.requestItem.sku')
            && str_contains($serviceSource, 'base_uom_code_snapshot');

        $ok = $missingColumns->isEmpty() && $blankRows === 0 && $relationMismatch === 0 && $serviceOk;

        $this->table(['Check', 'Result'], [
            ['Snapshot columns', $missingColumns->isEmpty() ? 'OK' : $missingColumns->join(', ')],
            ['Blank item/UOM snapshot rows', (string) $blankRows],
            ['PO/PR relation mismatch', (string) $relationMismatch],
            ['Service fallback', $serviceOk ? 'OK' : 'MISSING'],
            ['Status', $ok ? 'PASSED' : 'FAILED'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
