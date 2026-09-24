<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalI05WarehouseParStockCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i05-warehouse-par-stock-check';
    protected $description = 'Validate ERP POS FINAL I05 Warehouse Par Stock, opening balance consistency, and no fake movement.';

    public function handle(): int
    {
        $checks = [];
        foreach (['wh_par_stocks', 'stk_opening_stocks', 'stk_inventory_balances', 'wh_batches', 'wh_batch_balances'] as $table) {
            $checks["Table {$table}"] = Schema::hasTable($table);
        }

        $menu = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('path', '/warehouse/stock/par-stock')->first()
            : null;
        $checks['Access Matrix menu registered'] = $menu
            && (string) $menu->permission_view === 'warehouse.inventory.par_stock.view'
            && (string) $menu->permission_update === 'warehouse.inventory.par_stock.update';

        $openingCount = Schema::hasTable('stk_opening_stocks')
            ? (int) DB::table('stk_opening_stocks')->where('source', 'WAREHOUSE_PAR_INITIAL')->count()
            : 0;

        $fakeStockMovement = 0;
        if (Schema::hasTable('stk_inventory_movements')) {
            $fakeStockMovement = (int) DB::table('stk_inventory_movements')
                ->where(function ($q): void {
                    $q->where('reference_type', 'wh_i05_opening_stock')
                        ->orWhere('movement_type', 'opening_stock');
                })->count();
        }
        $fakeWarehouseLedger = (Schema::hasTable('wh_ledger_entries') && Schema::hasTable('wh_batches'))
            ? (int) DB::table('wh_ledger_entries as e')
                ->join('wh_batches as b', 'b.id', '=', 'e.batch_id')
                ->where('b.source_reference_type', 'wh_i05_opening_stock')
                ->count()
            : 0;

        $checks['Warehouse opening creates no stk_inventory_movements'] = $fakeStockMovement === 0;
        $checks['Warehouse opening creates no wh_ledger_entries'] = $fakeWarehouseLedger === 0;

        $variance = 0;
        if (Schema::hasTable('stk_opening_stocks') && Schema::hasTable('wh_batch_balances') && Schema::hasTable('stk_inventory_balances')) {
            $openingRows = DB::table('stk_opening_stocks as o')
                ->where('o.source', 'WAREHOUSE_PAR_INITIAL')
                ->get(['o.outlet_id', 'o.sku_id', 'o.opening_qty', 'o.inventory_value']);
            foreach ($openingRows as $row) {
                $ledgerExists = Schema::hasTable('wh_ledger_entries')
                    && DB::table('wh_ledger_entries')->where('warehouse_id', $row->outlet_id)->where('sku_id', $row->sku_id)->exists();
                if ($ledgerExists) continue; // operational activity may legitimately change balances after opening.

                $aggregate = DB::table('stk_inventory_balances')->where('outlet_id', $row->outlet_id)->where('sku_id', $row->sku_id)->first();
                $batch = DB::table('wh_batch_balances')->where('warehouse_id', $row->outlet_id)->where('sku_id', $row->sku_id)
                    ->selectRaw('COALESCE(SUM(on_hand_qty),0) qty, COALESCE(SUM(inventory_value),0) value')->first();
                if (! $aggregate
                    || abs((float) $aggregate->on_hand_qty - (float) ($batch->qty ?? 0)) > 0.0001
                    || abs((float) $aggregate->inventory_value - (float) ($batch->value ?? 0)) > 0.01
                    || abs((float) $row->opening_qty - (float) ($batch->qty ?? 0)) > 0.0001) {
                    $variance++;
                }
            }
        }
        $checks['Opening aggregate = batch before operations'] = $variance === 0;

        $this->line('ERP POS FINAL I05 - Warehouse Par Stock Check');
        $this->line('Warehouse opening rows : '.$openingCount);
        $this->line('Fake stock movements    : '.$fakeStockMovement.' (expected 0)');
        $this->line('Fake warehouse ledgers  : '.$fakeWarehouseLedger.' (expected 0)');
        $this->line('Opening variance rows   : '.$variance.' (expected 0 before operations)');
        $this->newLine();

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '[PASS] ' : '[FAIL] ').$label);
            if (! $ok) $failed++;
        }
        $this->newLine();
        if ($failed > 0) {
            $this->error("ERP POS FINAL I05 validation FAIL ({$failed} check). ");
            return self::FAILURE;
        }
        $this->info('ERP POS FINAL I05 validation PASS.');
        return self::SUCCESS;
    }
}
