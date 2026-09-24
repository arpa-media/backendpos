<?php

namespace App\Console\Commands;

use App\Services\StockInventory\ActualStockLedgerViewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpV5Iteration11StockInventoryCheckCommand extends Command
{
    protected $signature = 'erp-v5:iteration-11-check {--outlet= : Batasi runtime integrity check ke satu outlet ID}';

    protected $description = 'Smoke/integrity check ERP-V5 Iteration 11: Par UOM + authoritative Actual/Current Stock synchronization.';

    public function handle(ActualStockLedgerViewService $ledger): int
    {
        $ledgerSource = @file_get_contents(app_path('Services/StockInventory/ActualStockLedgerViewService.php')) ?: '';
        $actualSource = @file_get_contents(app_path('Services/StockInventory/ActualStockService.php')) ?: '';
        $snapshotSource = @file_get_contents(app_path('Services/StockInventory/StockSnapshotService.php')) ?: '';
        $opnameSource = @file_get_contents(app_path('Http/Controllers/Api/V1/StockInventory/StockOpnameController.php')) ?: '';
        $reconcileSource = @file_get_contents(app_path('Services/StockInventory/ActualStockReconciliationService.php')) ?: '';
        $parPage = @file_get_contents(base_path('../frontend - Backoffice/src/pages/stock-inventory/ParStocksPage.vue')) ?: '';

        $checks = [
            'Chronology memakai event timestamp sebelum priority' =>
                str_contains($ledgerSource, "'%s|%s|%02d|%s'")
                && str_contains($ledgerSource, '$this->eventSortTimestamp($event)')
                && str_contains($ledgerSource, 'absolute_timestamp_anchor'),
            'Canonical authoritative timeline tersedia' =>
                str_contains($ledgerSource, 'function authoritativeTimeline')
                && str_contains($ledgerSource, "'balance_before'")
                && str_contains($ledgerSource, "'balance_after'"),
            'Actual/Current rebuild memakai stateMap yang sama' =>
                str_contains($actualSource, '$this->ledgerView->stateMap($outletId)')
                && str_contains($actualSource, 'applyRebuiltCurrentBalance')
                && ! str_contains($actualSource, "whereDate('business_date','>',\$afterBusinessDate)"),
            'Submit Opname menjadi authoritative sebelum rebuild' =>
                ($submittedPos = strpos($opnameSource, "'status' => 'submitted'")) !== false
                && ($postPos = strpos($opnameSource, 'postSubmittedOpname($locked->fresh')) !== false
                && $submittedPos < $postPos,
            'Snapshot expose Base/Purchase UOM conversion' =>
                str_contains($snapshotSource, "'base_uom'")
                && str_contains($snapshotSource, "'purchase_uom'")
                && str_contains($snapshotSource, "'purchase_conversion_factor'")
                && str_contains($snapshotSource, "'par_qty_purchase_uom'"),
            'Par Stock UI tampilkan Base + Purchase UOM' =>
                str_contains($parPage, 'UOM Conversion')
                && str_contains($parPage, 'purchaseEquivalent')
                && str_contains($parPage, 'Jumlah Par Stock (Base UOM)')
                && str_contains($parPage, 'Purchase UOM'),
            'Repair GR bersifat reference-line idempotent' =>
                str_contains($reconcileSource, "'reference_type', 'wh_v3_goods_receipt'")
                && str_contains($reconcileSource, "->where('reference_line_id', \$line->line_id)")
                && str_contains($reconcileSource, 'authoritativeTimeline($outletId)'),
        ];

        $schemaMissing = [];
        foreach ([
            'stk_skus' => ['base_uom_id', 'purchase_uom_id', 'purchase_conversion_factor'],
            'stk_par_stocks' => ['outlet_id', 'sku_id', 'par_qty', 'minimum_qty', 'is_active'],
            'stk_inventory_balances' => ['outlet_id', 'sku_id', 'on_hand_qty', 'average_unit_cost', 'inventory_value'],
            'stk_inventory_movements' => ['outlet_id', 'sku_id', 'movement_type', 'reference_type', 'reference_id', 'reference_line_id', 'business_date', 'quantity', 'balance_qty_after'],
            'wh_v3_goods_receipts' => ['destination_type', 'destination_id', 'status', 'receipt_date', 'completed_at'],
            'wh_v3_goods_receipt_items' => ['goods_receipt_id', 'sku_id', 'received_qty_base'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $schemaMissing[] = $table.'.*';
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) $schemaMissing[] = $table.'.'.$column;
            }
        }
        $checks['Schema dependency Iterasi 11 tersedia'] = $schemaMissing === [];

        $menuMessage = 'access_menus tidak tersedia';
        if (Schema::hasTable('access_menus')) {
            $par = DB::table('access_menus')->where('path', '/stock-inventory/par-stocks')->first();
            $actual = DB::table('access_menus')->where('path', '/stock-inventory/actual-stock')->first();
            $menuOk = $par && $actual && (bool) $par->is_active && (bool) $actual->is_active
                && filled($par->permission_view) && filled($actual->permission_view);
            $checks['Access Matrix existing Par Stock + Actual Stock tetap aktif'] = (bool) $menuOk;
            $menuMessage = $menuOk
                ? $par->code.' + '.$actual->code.' (ACTIVE)'
                : 'Par Stock / Actual Stock belum aktif atau permission_view kosong';
        } else {
            $checks['Access Matrix existing Par Stock + Actual Stock tetap aktif'] = false;
        }

        $runtime = [
            'outlets_checked' => 0,
            'balance_mismatches' => 0,
            'missing_v3_gr_movements' => 0,
            'runtime_error' => null,
        ];

        if ($schemaMissing === []) {
            try {
                $outletOption = trim((string) ($this->option('outlet') ?? ''));
                $outlets = $outletOption !== ''
                    ? collect([$outletOption])
                    : DB::table('stk_par_stocks')->where('is_active', true)->pluck('outlet_id')->filter()->unique()->values();

                foreach ($outlets as $outletId) {
                    $outletId = (string) $outletId;
                    $runtime['outlets_checked']++;
                    $state = $ledger->stateMap($outletId);
                    $skuIds = DB::table('stk_par_stocks')
                        ->where('outlet_id', $outletId)
                        ->where('is_active', true)
                        ->pluck('sku_id')
                        ->map(fn ($id) => (string) $id);
                    $balances = DB::table('stk_inventory_balances')
                        ->where('outlet_id', $outletId)
                        ->whereIn('sku_id', $skuIds->all())
                        ->get(['sku_id', 'on_hand_qty'])
                        ->keyBy(fn ($row) => (string) $row->sku_id);

                    foreach ($skuIds as $skuId) {
                        $expected = round((float) ($state->get($skuId)['qty'] ?? 0), 4);
                        $stored = round((float) ($balances->get($skuId)?->on_hand_qty ?? 0), 4);
                        if (abs($expected - $stored) > 0.0001) $runtime['balance_mismatches']++;
                    }

                    $hardResetAt = Schema::hasTable('stk_actual_stock_reset_runs')
                        ? DB::table('stk_actual_stock_reset_runs')
                            ->where('outlet_id', $outletId)
                            ->where('mode', 'reset_opnames_zero')
                            ->orderByDesc('executed_at')->orderByDesc('id')->value('executed_at')
                        : null;

                    $grQuery = DB::table('wh_v3_goods_receipts as g')
                        ->join('wh_v3_goods_receipt_items as i', 'i.goods_receipt_id', '=', 'g.id')
                        ->leftJoin('stk_inventory_movements as m', function ($join): void {
                            $join->on('m.reference_line_id', '=', 'i.id')
                                ->where('m.movement_type', '=', 'goods_receipt')
                                ->where('m.reference_type', '=', 'wh_v3_goods_receipt');
                        })
                        ->where('g.destination_type', 'outlet')
                        ->where('g.destination_id', $outletId)
                        ->where('g.status', 'completed')
                        ->where('i.received_qty_base', '>', 0)
                        ->whereNull('m.id');
                    if ($hardResetAt) $grQuery->where('g.completed_at', '>', $hardResetAt);
                    $runtime['missing_v3_gr_movements'] += (int) $grQuery->count();
                }
            } catch (Throwable $e) {
                $runtime['runtime_error'] = $e->getMessage();
            }
        }

        $runtimeOk = $runtime['runtime_error'] === null
            && $runtime['balance_mismatches'] === 0
            && $runtime['missing_v3_gr_movements'] === 0;
        $checks['Runtime Actual = Current dan semua V3 GR punya movement'] = $runtimeOk;

        $rows = [];
        foreach ($checks as $name => $ok) $rows[] = [$name, $ok ? 'OK' : 'FAILED'];
        $rows[] = ['Missing schema', $schemaMissing ? implode(', ', $schemaMissing) : '-'];
        $rows[] = ['Access Matrix', $menuMessage];
        $rows[] = ['Outlets checked', (string) $runtime['outlets_checked']];
        $rows[] = ['Actual vs Current mismatch', (string) $runtime['balance_mismatches']];
        $rows[] = ['Completed V3 GR lines missing movement', (string) $runtime['missing_v3_gr_movements']];
        $rows[] = ['Runtime error', $runtime['runtime_error'] ?: '-'];

        $passed = ! in_array(false, $checks, true);
        $rows[] = ['Status', $passed ? 'PASSED' : 'FAILED'];
        $this->table(['Check', 'Result'], $rows);

        if (! $runtimeOk) {
            $this->warn('Jika mismatch berasal dari data historis, jalankan: php artisan erp-v5:iteration-11-reconcile lalu ulangi check.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
