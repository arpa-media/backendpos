<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockInventoryIteration01CheckCommand extends Command
{
    protected $signature = 'stock-inventory:iteration-01-check';
    protected $description = 'Smoke check ERP v4 Iterasi 01: Actual Stock active Par Stock scope, menu order, and Purchase UOM recommendation.';

    public function handle(): int
    {
        $actualService = @file_get_contents(app_path('Services/StockInventory/ActualStockLedgerViewService.php')) ?: '';
        $requestService = @file_get_contents(app_path('Services/Warehouse/WarehouseStockRequestService.php')) ?: '';
        $actualPage = @file_get_contents(base_path('../frontend - Backoffice/src/pages/stock-inventory/ActualStockPage.vue')) ?: '';
        $requestPage = @file_get_contents(base_path('../frontend - Backoffice/src/modules/warehouse/pages/WarehouseStockRequestPage.vue')) ?: '';

        $checks = [
            'Actual Stock memakai active Par Stock sebagai domain catalog' =>
                str_contains($actualService, "->join('stk_par_stocks as p'")
                && str_contains($actualService, "->where('p.is_active', '=', 1)"),
            'Actual Stock tidak lagi LEFT JOIN Par Stock' =>
                ! str_contains($actualService, "->leftJoin('stk_par_stocks as p'"),
            'UI memakai nama Aktual Stock' =>
                str_contains($actualPage, 'title="Aktual Stock"')
                && ! str_contains($actualPage, 'title="Aktual Stok"'),
            'UI Saldo Aggregate sudah menjadi Selisih' =>
                str_contains($actualPage, '>Selisih</th>')
                && ! str_contains($actualPage, '>Saldo Aggregate</th>'),
            'Warehouse request expose recommendation Purchase UOM' =>
                str_contains($requestService, "'recommended_request_qty_uom'")
                && str_contains($requestService, "'default_request_uom_id'")
                && str_contains($requestService, "'is_purchase_default'"),
            'Purchase UOM tetap valid tanpa wh_sku_uoms' =>
                str_contains($requestService, 'Schema::hasTable(\'wh_sku_uoms\')')
                && str_contains($requestService, '$sku->purchase_uom_id')
                && str_contains($requestService, '$sku->purchase_conversion_factor'),
            'UI default request memilih Purchase UOM' =>
                str_contains($requestPage, 'preferredRequestUom')
                && str_contains($requestPage, 'recommended_request_qty_uom')
                && str_contains($requestPage, 'recommendedBaseQty'),
        ];

        $schemaMissing = [];
        foreach ([
            'stk_skus' => ['base_uom_id', 'purchase_uom_id', 'purchase_conversion_factor'],
            'stk_par_stocks' => ['outlet_id', 'sku_id', 'par_qty', 'is_active'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $schemaMissing[] = $table.'.*';
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $schemaMissing[] = $table.'.'.$column;
                }
            }
        }
        $checks['Schema SKU/Par Stock minimum tersedia'] = $schemaMissing === [];

        $menuMessage = 'Access Matrix table tidak tersedia';
        if (Schema::hasTable('access_menus')) {
            $request = DB::table('access_menus')->where('code', 'inventory-request-stock')->first();
            $receiving = DB::table('access_menus')->where('code', 'inventory-warehouse-receiving')->first();
            $actual = DB::table('access_menus')->where('code', 'inventory-actual-stock')->first();
            $menuOk = $request && $receiving && $actual
                && (bool) $receiving->is_active
                && (bool) $actual->is_active
                && (string) $actual->name === 'Aktual Stock'
                && (int) $request->sort_order < (int) $receiving->sort_order
                && (int) $receiving->sort_order < (int) $actual->sort_order;
            $checks['Access Matrix: Request → Receiving → Aktual Stock'] = (bool) $menuOk;
            $menuMessage = $menuOk
                ? "{$request->sort_order} → {$receiving->sort_order} → {$actual->sort_order}"
                : 'Urutan/nama menu belum sesuai migration Iterasi 01';
        } else {
            $checks['Access Matrix: Request → Receiving → Aktual Stock'] = false;
        }

        $rows = [];
        foreach ($checks as $name => $ok) {
            $rows[] = [$name, $ok ? 'OK' : 'FAILED'];
        }
        $rows[] = ['Missing schema', $schemaMissing ? implode(', ', $schemaMissing) : '-'];
        $rows[] = ['Menu order', $menuMessage];
        $rows[] = ['wh_sku_uoms', Schema::hasTable('wh_sku_uoms') ? 'AVAILABLE (extended UOM mappings enabled)' : 'OPTIONAL/ABSENT (Purchase UOM fallback active)'];

        $passed = ! in_array(false, $checks, true);
        $rows[] = ['Status', $passed ? 'PASSED' : 'FAILED'];
        $this->table(['Check', 'Result'], $rows);

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
