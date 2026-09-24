<?php

namespace App\Console\Commands;

use App\Services\Cogs\CogsValuationResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpV5I02CogsCheckCommand extends Command
{
    protected $signature = 'erp-v5:i02-cogs-check
        {--outlet= : Outlet ULID tertentu}
        {--date= : Business date YYYY-MM-DD, default hari ini Asia/Jakarta}';

    protected $description = 'ERP V5 I02 acceptance check for canonical GR and Item Sold COGS valuation.';

    public function handle(CogsValuationResolverService $resolver): int
    {
        $outletId = trim((string) ($this->option('outlet') ?? '')) ?: null;
        $date = trim((string) ($this->option('date') ?? '')) ?: now('Asia/Jakarta')->toDateString();
        $checks = [];

        $requiredTables = [
            'cogs_purchasing_cost_snapshots', 'cogs_sale_consumptions', 'cogs_sale_consumption_items',
            'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items', 'pur_purchase_orders', 'access_menus',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
        $checks[] = ['Required tables', $missingTables === [] ? 'PASS' : 'FAIL', $missingTables === [] ? '-' : implode(', ', $missingTables)];

        $identityColumns = ['canonical_receipt_fingerprint', 'canonical_source_type', 'source_role', 'is_canonical', 'duplicate_of_snapshot_id'];
        $missingColumns = Schema::hasTable('cogs_purchasing_cost_snapshots')
            ? array_values(array_filter($identityColumns, fn (string $column): bool => ! Schema::hasColumn('cogs_purchasing_cost_snapshots', $column)))
            : $identityColumns;
        $checks[] = ['Canonical identity columns', $missingColumns === [] ? 'PASS' : 'FAIL', $missingColumns === [] ? '-' : implode(', ', $missingColumns)];

        if ($missingTables === [] && $missingColumns === []) {
            $mirrorsMarkedCanonical = DB::table('cogs_purchasing_cost_snapshots')
                ->where('source_role', 'procurement_mirror')
                ->where('is_canonical', true)
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->whereDate('receipt_date', $date)
                ->count();
            $checks[] = ['Procurement mirror excluded', $mirrorsMarkedCanonical === 0 ? 'PASS' : 'FAIL', "canonical_mirrors={$mirrorsMarkedCanonical}"];

            $legacyPurchasingCanonical = DB::table('cogs_purchasing_cost_snapshots')
                ->where('receipt_type_snapshot', 'manual')
                ->whereNotNull('stock_request_id')
                ->where('supplier_document_number_snapshot', 'like', 'PUR-EXEC:%')
                ->where('is_canonical', true)
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->whereDate('receipt_date', $date)
                ->count();
            $checks[] = ['Legacy PUR-EXEC mirror excluded', $legacyPurchasingCanonical === 0 ? 'PASS' : 'FAIL', "canonical_legacy_rows={$legacyPurchasingCanonical}"];

            $parentQueryBuilder = DB::table('cogs_sale_consumptions as c')
                ->whereDate('c.business_date', $date)
                ->whereIn('c.movement_type', ['sale_consumption', 'sale_consumption_reversal'])
                ->whereIn('c.status', ['posted', 'reversed'])
                ->when($outletId, fn ($q) => $q->where('c.outlet_id', $outletId));
            if (Schema::hasTable('cogs_calculation_runs')) {
                $parentQueryBuilder->whereNotExists(function ($q): void {
                    $q->selectRaw('1')->from('cogs_calculation_runs as run')
                        ->whereColumn('run.outlet_id', 'c.outlet_id')
                        ->where('run.status', 'closed')
                        ->whereColumn('run.period_from', '<=', 'c.business_date')
                        ->whereColumn('run.period_to', '>=', 'c.business_date');
                });
            }
            $parentQuery = $parentQueryBuilder->get(['c.id', 'c.total_cost']);
            $parentMismatch = 0;
            foreach ($parentQuery as $parent) {
                $sum = round((float) DB::table('cogs_sale_consumption_items')->where('consumption_id', $parent->id)->sum('total_cost'), 2);
                if (abs((float) $parent->total_cost - $sum) > 0.01) $parentMismatch++;
            }
            $checks[] = ['Item Sold parent total synchronized', $parentMismatch === 0 ? 'PASS' : 'FAIL', "mismatch={$parentMismatch}"];

            $zeroRows = DB::table('cogs_sale_consumption_items as item')
                ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
                ->whereDate('c.business_date', $date)
                ->where('c.movement_type', 'sale_consumption')
                ->where('c.status', 'posted')
                ->when($outletId, fn ($q) => $q->where('c.outlet_id', $outletId))
                ->where(function ($q): void {
                    $q->where('item.unit_cost_snapshot', '<=', 0)->orWhere('item.total_cost', '<=', 0);
                })
                ->where(function ($q): void {
                    $q->whereRaw('ABS(item.movement_quantity) > 0.00000001')
                        ->orWhereRaw('ABS(item.quantity_base) > 0.00000001');
                });
            if (Schema::hasTable('cogs_calculation_runs')) {
                $zeroRows->whereNotExists(function ($q): void {
                    $q->selectRaw('1')->from('cogs_calculation_runs as run')
                        ->whereColumn('run.outlet_id', 'c.outlet_id')
                        ->where('run.status', 'closed')
                        ->whereColumn('run.period_from', '<=', 'c.business_date')
                        ->whereColumn('run.period_to', '>=', 'c.business_date');
                });
            }
            $zeroRows = $zeroRows->get(['item.sku_id', 'c.outlet_id', 'c.business_date']);
            $resolvableZero = 0;
            $resolver->clearCache();
            foreach ($zeroRows as $row) {
                $resolved = $resolver->resolve((string) $row->outlet_id, (string) $row->sku_id, substr((string) $row->business_date, 0, 10), 0.0);
                if ((float) ($resolved['unit_cost'] ?? 0) > 0) $resolvableZero++;
            }
            $checks[] = ['Resolvable Item Sold zero cost', $resolvableZero === 0 ? 'PASS' : 'FAIL', "resolvable_zero_lines={$resolvableZero}"];
        }

        foreach ([
            'cogs-history-stock' => '/cogs/history-stock',
            'cogs-item-sold' => '/cogs/item-sold',
        ] as $code => $path) {
            $menu = Schema::hasTable('access_menus') ? DB::table('access_menus')->where('code', $code)->first() : null;
            $ok = $menu && (string) $menu->path === $path && (bool) $menu->is_active;
            $checks[] = ["Access Matrix {$code}", $ok ? 'PASS' : 'FAIL', $menu ? (string) $menu->path : 'missing'];
        }

        foreach (['cogs.history-stock.index', 'cogs.item-sold.overview', 'cogs.item-sold.ingredients'] as $name) {
            $checks[] = ["Route {$name}", Route::has($name) ? 'PASS' : 'FAIL', Route::has($name) ? '-' : 'missing'];
        }

        $this->table(['Check', 'Result', 'Detail'], $checks);
        $failed = collect($checks)->contains(fn (array $row): bool => $row[1] === 'FAIL');
        $this->newLine();
        $this->line('Status: '.($failed ? '<fg=red>FAILED</>' : '<fg=green>PASSED</>'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
