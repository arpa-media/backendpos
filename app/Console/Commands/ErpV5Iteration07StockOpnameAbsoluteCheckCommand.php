<?php

namespace App\Console\Commands;

use App\Services\StockInventory\ActualStockLedgerViewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpV5Iteration07StockOpnameAbsoluteCheckCommand extends Command
{
    protected $signature = 'erp-v5:iteration-07-stock-opname-check
        {--outlet= : Batasi pemeriksaan ke satu outlet ID}';

    protected $description = 'Validate Stock Opname absolute-anchor semantics through Actual Stock, COGS, and Finance posting.';

    public function handle(ActualStockLedgerViewService $ledger): int
    {
        $actualSource = @file_get_contents(app_path('Services/StockInventory/ActualStockService.php')) ?: '';
        $reconcileSource = @file_get_contents(app_path('Services/StockInventory/ActualStockReconciliationService.php')) ?: '';
        $cogsSource = @file_get_contents(app_path('Services/Cogs/CogsCalculationService.php')) ?: '';
        $financeSource = @file_get_contents(app_path('Services/Finance/FinanceCogsPostingService.php')) ?: '';
        $uiSource = @file_get_contents(base_path('../frontend - Backoffice/src/pages/stock-inventory/StockOpnamePage.vue')) ?: '';

        $staticChecks = [
            'Actual delta uses canonical timeline' => str_contains($actualSource, 'authoritativeTimeline')
                && str_contains($actualSource, "'qty_before_authoritative' => \$before")
                && str_contains($actualSource, "'quantity' => \$delta"),
            'Opname semantics are absolute anchor' => str_contains($actualSource, 'absolute_physical_count_anchor'),
            'Reconcile repairs absolute opname movement' => str_contains($reconcileSource, 'erp_v5_iteration_07_absolute_opname')
                && str_contains($reconcileSource, "'total_cost'")
                && str_contains($reconcileSource, 'erp_v5_iteration_07_absolute_opname'),
            'COGS excludes opname adjustment from other movement' => str_contains($cogsSource, "'stock_opname_adjustment'")
                && str_contains($cogsSource, 'excluded_variance_owns_physical_count_difference'),
            'COGS engine bumped for source fingerprint' => str_contains($cogsSource, 'erp-v5-iter07-stock-opname-absolute-v1'),
            'Finance posts final COGS value' => str_contains($financeSource, 'final_cogs_value'),
            'UI previews Actual Stock delta' => str_contains($uiSource, 'Perubahan Aktual')
                && str_contains($uiSource, 'opnameDelta(row)'),
        ];

        $outletId = trim((string) ($this->option('outlet') ?? '')) ?: null;
        $missingMovement = 0;
        $quantityMismatch = 0;
        $balanceMismatch = 0;
        $checkedOpnameLines = 0;
        $legacyCogsRuns = 0;
        $legacyClosedCogsRuns = 0;

        if (Schema::hasTable('stk_stock_opnames')
            && Schema::hasTable('stk_stock_opname_items')
            && Schema::hasTable('stk_inventory_movements')) {
            $outletQuery = DB::table('stk_stock_opnames')
                ->where('status', 'submitted')
                ->select('outlet_id')
                ->distinct();
            if ($outletId) $outletQuery->where('outlet_id', $outletId);

            foreach ($outletQuery->pluck('outlet_id') as $id) {
                $id = (string) $id;
                $movements = DB::table('stk_inventory_movements')
                    ->where('outlet_id', $id)
                    ->where('movement_type', 'stock_opname_adjustment')
                    ->where('reference_type', 'stk_stock_opname')
                    ->get(['reference_line_id', 'quantity', 'balance_qty_after'])
                    ->keyBy(fn ($row) => (string) $row->reference_line_id);

                foreach ($ledger->authoritativeTimeline($id) as $event) {
                    if (($event['kind'] ?? null) !== 'stock_opname') continue;
                    $checkedOpnameLines++;
                    $movement = $movements->get((string) ($event['reference_line_id'] ?? ''));
                    if (! $movement) {
                        $missingMovement++;
                        continue;
                    }
                    if (abs((float) $movement->quantity - (float) $event['signed_quantity']) > 0.0001) {
                        $quantityMismatch++;
                    }
                    if (abs((float) $movement->balance_qty_after - (float) $event['balance_after']) > 0.0001) {
                        $balanceMismatch++;
                    }
                }
            }
        }

        if (Schema::hasTable('cogs_calculation_runs')) {
            $query = DB::table('cogs_calculation_runs')
                ->whereIn('status', ['calculated', 'reconciled', 'closed']);
            if ($outletId) $query->where('outlet_id', $outletId);
            foreach ($query->get(['status', 'metadata']) as $run) {
                $metadata = is_string($run->metadata) ? (json_decode($run->metadata, true) ?: []) : (array) ($run->metadata ?? []);
                if (($metadata['calculation_engine'] ?? null) !== 'erp-v5-iter07-stock-opname-absolute-v1') {
                    $legacyCogsRuns++;
                    if ((string) $run->status === 'closed') $legacyClosedCogsRuns++;
                }
            }
        }

        $menuOk = true;
        $permissionOk = true;
        if (Schema::hasTable('access_menus')) {
            $menuOk = DB::table('access_menus')
                ->where('code', 'inventory-stock-opname')
                ->where('path', '/stock-inventory/stock-opname')
                ->where('is_active', true)
                ->exists();
        }
        if (Schema::hasTable('permissions')) {
            $permissionOk = collect(['view', 'create', 'update', 'delete'])
                ->every(fn (string $ability): bool => DB::table('permissions')
                    ->where('name', 'stock_inventory.opname.'.$ability)->exists());
        }

        $rows = collect($staticChecks)->map(fn (bool $ok, string $name): array => [$name, $ok ? 'PASS' : 'FAIL'])->values()->all();
        $rows[] = ['Access Matrix menu existing', $menuOk ? 'PASS' : 'FAIL'];
        $rows[] = ['Access Matrix permissions existing', $permissionOk ? 'PASS' : 'FAIL'];
        $this->table(['Check', 'Result'], $rows);

        $this->table(['Runtime metric', 'Value'], [
            ['Submitted opname lines checked', (string) $checkedOpnameLines],
            ['Missing opname movements', (string) $missingMovement],
            ['Wrong opname movement sign/qty', (string) $quantityMismatch],
            ['Wrong opname movement balance_after', (string) $balanceMismatch],
            ['COGS runs from pre-Iter07 engine', (string) $legacyCogsRuns],
            ['Closed legacy COGS runs (audit immutable)', (string) $legacyClosedCogsRuns],
            ['Reference scenario 1000 -> 400', '-600 movement; closing Actual Stock = 400'],
        ]);

        if ($legacyCogsRuns > 0) {
            $this->warn('COGS run lama terdeteksi. Recalculate run yang belum CLOSED agar memakai policy Iterasi 07. Run CLOSED/Finance POSTED jangan diubah diam-diam; gunakan correction period sesuai audit policy.');
        }

        $failed = collect($staticChecks)->contains(false)
            || ! $menuOk
            || ! $permissionOk
            || $missingMovement > 0
            || $quantityMismatch > 0
            || $balanceMismatch > 0;

        if ($failed && ($missingMovement > 0 || $quantityMismatch > 0 || $balanceMismatch > 0)) {
            $this->warn('Repair derived Actual/Current Stock dengan: php artisan erp-v5:iteration-11-reconcile --dry-run lalu jalankan tanpa --dry-run.');
        }

        $this->line('Status: '.($failed ? 'FAILED' : ($legacyCogsRuns > 0 ? 'PASSED_WITH_ATTENTION' : 'PASSED')));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
