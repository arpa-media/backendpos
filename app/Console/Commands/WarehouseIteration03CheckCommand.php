<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehouseIteration03CheckCommand extends Command
{
    protected $signature = 'warehouse:iteration-03-check';
    protected $description = 'Smoke check Warehouse Iterasi 03: approved Stock Request document, header Request, and UOM v3 snapshots.';

    public function handle(): int
    {
        $checks = [];
        $required = [
            'wh_v3_sales_order_items' => ['uom_code_snapshot','uom_name_snapshot','base_uom_id_snapshot','base_uom_code_snapshot','conversion_factor_snapshot'],
            'wh_v3_transfer_order_items' => ['uom_code_snapshot','base_uom_id_snapshot','base_uom_code_snapshot','conversion_factor_snapshot'],
            'wh_production_inputs' => ['request_uom_code_snapshot','base_uom_code_snapshot','actual_qty_uom','conversion_factor_snapshot'],
            'wh_production_outputs' => ['output_uom_code_snapshot','base_uom_code_snapshot','actual_qty_uom','conversion_factor_snapshot'],
            'wh_v3_production_material_request_items' => ['conversion_factor_snapshot','request_uom_code_snapshot','base_uom_id_snapshot','base_uom_code_snapshot'],
            'wh_v3_production_result_items' => ['uom_code_snapshot','base_uom_id_snapshot','base_uom_code_snapshot','conversion_factor_snapshot'],
        ];
        foreach ($required as $table => $columns) {
            $missing = !Schema::hasTable($table) ? ['<table>'] : array_values(array_filter($columns, fn ($column) => !Schema::hasColumn($table,$column)));
            $checks["Schema {$table}"] = $missing ? 'MISSING: '.implode(', ', $missing) : 'OK';
        }

        foreach ([
            '/warehouse/purchasing/purchase-requests',
            '/warehouse/stock-requests/inbox',
            '/warehouse/sales/orders',
            '/warehouse/production/orders',
        ] as $path) {
            $menu = Schema::hasTable('access_menus') ? DB::table('access_menus')->where('path',$path)->where('is_active',true)->first() : null;
            $checks["Access Matrix {$path}"] = $menu ? 'OK' : 'MISSING';
        }

        $frontend = base_path('../frontend - Backoffice/src/modules/warehouse');
        $files = [
            'Approved print page' => $frontend.'/pages/WarehouseStockRequestApprovedPrintPage.vue',
            'Sales route' => $frontend.'/route-modules/94-sales-warehouse-v3.js',
            'Warehouse header' => $frontend.'/layouts/WarehouseLayout.vue',
            'Sales Order UOM UI' => $frontend.'/pages/WarehouseSalesTransferV3Page.vue',
            'Production UOM UI' => $frontend.'/pages/WarehouseProductionV3Page.vue',
        ];
        foreach ($files as $label => $file) $checks[$label] = is_file($file) ? 'OK' : 'MISSING';

        if (is_file($files['Sales route'])) {
            $source = file_get_contents($files['Sales route']);
            $checks['Print route inherits Stock Request View'] = str_contains($source, "warehouse-stock-request-approved-print") && str_contains($source, "accessPath: '/warehouse/stock-requests/inbox'") ? 'OK' : 'FAILED';
        }
        if (is_file($files['Warehouse header'])) {
            $source = file_get_contents($files['Warehouse header']);
            $checks['Header Request uses Access Matrix Create'] = str_contains($source, "'/warehouse/purchasing/purchase-requests', 'create'") ? 'OK' : 'FAILED';
        }

        $failed = collect($checks)->contains(fn ($value) => $value !== 'OK');
        $rows = collect($checks)->map(fn ($result,$check) => [$check,$result])->values()->all();
        $this->table(['Check','Result'],$rows);
        $this->line('Status  '.($failed ? '<fg=red>FAILED</>' : '<fg=green>PASSED</>'));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
