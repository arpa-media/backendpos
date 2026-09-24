<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpV5Iteration15CogsValuationCheckCommand extends Command
{
    protected $signature = 'erp-v5:iteration-15-check {--outlet= : Outlet ULID tertentu}';

    protected $description = 'ERP V5 Iteration 15 readiness check untuk Warehouse V3 COGS valuation bridge.';

    public function handle(): int
    {
        $outletId = trim((string) ($this->option('outlet') ?? '')) ?: null;
        $requiredTables = [
            'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items', 'wh_v3_delivery_orders',
            'wh_ledger_entries', 'stk_inventory_balances', 'stk_inventory_movements',
            'cogs_purchasing_cost_snapshots', 'cogs_sale_consumptions', 'cogs_sale_consumption_items',
            'access_menus',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));

        $checks = [];
        $checks[] = ['Required tables', $missingTables === [] ? 'PASS' : 'FAIL', $missingTables === [] ? '-' : implode(', ', $missingTables)];

        if ($missingTables === []) {
            $missingSnapshots = DB::table('wh_v3_goods_receipt_items as item')
                ->join('wh_v3_goods_receipts as receipt', 'receipt.id', '=', 'item.goods_receipt_id')
                ->leftJoin('cogs_purchasing_cost_snapshots as snapshot', 'snapshot.goods_receipt_item_id', '=', 'item.id')
                ->where('receipt.destination_type', 'outlet')
                ->where('receipt.status', 'completed')
                ->where('item.received_qty_base', '>', 0)
                ->when($outletId, fn ($q) => $q->where('receipt.destination_id', $outletId))
                ->whereNull('snapshot.id')
                ->count();
            $checks[] = ['Warehouse V3 GR snapshot coverage', $missingSnapshots === 0 ? 'PASS' : 'FAIL', "missing={$missingSnapshots}"];

            $zeroSnapshot = DB::table('cogs_purchasing_cost_snapshots')
                ->where('receipt_type_snapshot', 'warehouse_v3')
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->where(function ($q): void {
                    $q->where('unit_cost', '<=', 0)->orWhere('line_total', '<=', 0);
                })
                ->count();
            $checks[] = ['Warehouse V3 snapshot valuation', $zeroSnapshot === 0 ? 'PASS' : 'FAIL', "zero_cost={$zeroSnapshot}"];

            $zeroBalances = DB::table('stk_inventory_balances as balance')
                ->where('balance.on_hand_qty', '!=', 0)
                ->when($outletId, fn ($q) => $q->where('balance.outlet_id', $outletId))
                ->whereExists(function ($q): void {
                    $q->selectRaw('1')
                        ->from('wh_v3_goods_receipt_items as item')
                        ->join('wh_v3_goods_receipts as receipt', 'receipt.id', '=', 'item.goods_receipt_id')
                        ->whereColumn('receipt.destination_id', 'balance.outlet_id')
                        ->whereColumn('item.sku_id', 'balance.sku_id')
                        ->where('receipt.destination_type', 'outlet')
                        ->where('receipt.status', 'completed')
                        ->where('item.received_qty_base', '>', 0);
                })
                ->where(function ($q): void {
                    $q->where('balance.average_unit_cost', '<=', 0)->orWhereRaw('ABS(balance.inventory_value) <= 0.01');
                })
                ->count();
            $checks[] = ['Current Stock valuation after Warehouse GR', $zeroBalances === 0 ? 'PASS' : 'FAIL', "zero_valuation={$zeroBalances}"];

            $zeroConsumption = DB::table('cogs_sale_consumption_items as item')
                ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
                ->whereIn('c.movement_type', ['sale_consumption', 'sale_consumption_reversal'])
                ->whereIn('c.status', ['posted', 'reversed'])
                ->when($outletId, fn ($q) => $q->where('c.outlet_id', $outletId))
                ->where(function ($q): void {
                    $q->where('item.unit_cost_snapshot', '<=', 0)->orWhere('item.total_cost', '<=', 0);
                })
                ->whereNotExists(function ($q): void {
                    $q->selectRaw('1')
                        ->from('cogs_calculation_runs as run')
                        ->whereColumn('run.outlet_id', 'c.outlet_id')
                        ->where('run.status', 'closed')
                        ->whereColumn('run.period_from', '<=', 'c.business_date')
                        ->whereColumn('run.period_to', '>=', 'c.business_date');
                })
                ->count();
            $checks[] = ['Open-period Item Sold zero cost', $zeroConsumption === 0 ? 'PASS' : 'FAIL', "zero_cost_lines={$zeroConsumption}"];
        }

        foreach ([
            'cogs-history-stock' => '/cogs/history-stock',
            'cogs-item-sold' => '/cogs/item-sold',
            'cogs-calculation' => '/cogs/calculation',
        ] as $code => $path) {
            if (! Schema::hasTable('access_menus')) {
                $checks[] = ["Access Matrix {$code}", 'FAIL', 'access_menus missing'];
                continue;
            }
            $menu = DB::table('access_menus')->where('code', $code)->first();
            $ok = $menu && (string) $menu->path === $path && (bool) $menu->is_active;
            $checks[] = ["Access Matrix {$code}", $ok ? 'PASS' : 'FAIL', $menu ? (string) $menu->path : 'missing'];
        }

        $this->table(['Check', 'Result', 'Detail'], $checks);
        $failed = collect($checks)->contains(fn (array $row): bool => $row[1] === 'FAIL');
        $this->newLine();
        $this->line('Status: '.($failed ? '<fg=red>FAILED</>' : '<fg=green>PASSED</>'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
