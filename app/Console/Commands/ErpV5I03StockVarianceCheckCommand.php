<?php

namespace App\Console\Commands;

use App\Models\Cogs\StockVariance;
use App\Models\StockInventory\StockOpname;
use App\Services\Cogs\CanonicalStockVarianceLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpV5I03StockVarianceCheckCommand extends Command
{
    protected $signature = 'erp-v5:i03-stock-variance-check
        {--outlet= : Filter satu outlet ULID}
        {--date= : Business date YYYY-MM-DD, default hari ini Asia/Jakarta}';

    protected $description = 'ERP-V5 I03 acceptance checker for canonical Stock Variance opening, GR, recipe consumption, reset boundary, and Access Matrix.';

    public function __construct(private readonly CanonicalStockVarianceLedgerService $ledger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = trim((string) $this->option('date')) ?: CarbonImmutable::now('Asia/Jakarta')->toDateString();
        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $date, 'Asia/Jakarta')->toDateString();
        } catch (\Throwable) {
            $this->error('--date wajib format YYYY-MM-DD.');
            return self::FAILURE;
        }
        $outletId = trim((string) $this->option('outlet')) ?: null;

        $requiredTables = [
            'cogs_stock_variances', 'cogs_stock_variance_items',
            'stk_stock_opnames', 'stk_stock_opname_items', 'stk_actual_stock_reset_runs',
            'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items',
            'cogs_sale_consumptions', 'cogs_sale_consumption_items',
            'access_menus', 'permissions',
        ];
        $missingTables = collect($requiredTables)->reject(fn (string $table) => Schema::hasTable($table))->values();

        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();
        $requiredRoutes = [
            'cogs.stock-variance.overview', 'cogs.stock-variance.candidates',
            'cogs.stock-variance.calculate', 'cogs.stock-variance.submit',
            'cogs.stock-variance.export', 'cogs.stock-variance.index', 'cogs.stock-variance.show',
        ];
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name) => $routeNames->contains($name))->values();

        $menuOk = Schema::hasTable('access_menus')
            && DB::table('access_menus')
                ->where('code', 'cogs-stock-variance')
                ->where('path', '/cogs/stock-variance')
                ->where('is_active', true)
                ->exists();
        $requiredPermissions = collect(['view', 'create', 'update', 'delete'])->map(fn (string $action) => 'cogs.stock_variance.'.$action);
        $missingPermissions = $requiredPermissions->reject(fn (string $permission): bool => Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $permission)->exists())->values();

        $servicePath = app_path('Services/Cogs/CanonicalStockVarianceLedgerService.php');
        $enginePath = app_path('Services/Cogs/StockVarianceService.php');
        $queryPath = app_path('Services/Cogs/StockVarianceQueryService.php');
        $source = is_file($servicePath) ? file_get_contents($servicePath) : '';
        $engineSource = is_file($enginePath) ? file_get_contents($enginePath) : '';
        $querySource = is_file($queryPath) ? file_get_contents($queryPath) : '';
        $sourceContracts = [
            'Canonical ledger service exists' => $source !== '',
            'Physical GR uses ActualStockLedgerViewService' => str_contains($source, 'ActualStockLedgerViewService'),
            'Recipe consumption reads COGS documents' => str_contains($source, 'cogs_sale_consumption_items'),
            'Hard-reset boundary enforced' => str_contains($source, 'reset_opnames_zero'),
            'Variance engine stores canonical fingerprint' => str_contains($engineSource, 'canonical_movement_fingerprint'),
            'Candidate query enforces reset boundary' => str_contains($querySource, 'canonicalOpnameQuery'),
        ];
        $failedContracts = collect($sourceContracts)->filter(fn (bool $ok) => ! $ok)->keys()->values();

        $metrics = [
            'eligible_submitted_opnames' => 0,
            'stale_active_variances' => 0,
            'old_engine_active_variances' => 0,
            'source_actual_mismatch_items' => 0,
            'opening_mismatch_items' => 0,
            'goods_receipt_mismatch_items' => 0,
            'consumption_mismatch_items' => 0,
            'theoretical_formula_mismatch_items' => 0,
            'canonical_trace_mismatch_items' => 0,
        ];

        if ($missingTables->isEmpty()) {
            $opnameQuery = StockOpname::query()->with('items')
                ->whereDate('opname_date', $date)
                ->where('status', 'submitted')
                ->whereNotNull('submitted_at');
            $varianceQuery = StockVariance::query()->with(['stockOpname.items', 'items'])
                ->whereDate('variance_date', $date)
                ->whereIn('status', [StockVariance::STATUS_CALCULATED, StockVariance::STATUS_SUBMITTED]);
            if ($outletId) {
                $opnameQuery->where('outlet_id', $outletId);
                $varianceQuery->where('outlet_id', $outletId);
            }

            foreach ($opnameQuery->get() as $opname) {
                if ($this->ledger->isCanonicalSubmittedOpname($opname)) {
                    $metrics['eligible_submitted_opnames']++;
                }
            }

            foreach ($varianceQuery->get() as $variance) {
                $sourceOpname = $variance->stockOpname;
                if (! $sourceOpname || ! $this->ledger->isCanonicalSubmittedOpname($sourceOpname)) {
                    $metrics['stale_active_variances']++;
                    continue;
                }

                $storedEngine = (string) data_get($variance->metadata ?: [], 'engine', '');
                if ($storedEngine !== CanonicalStockVarianceLedgerService::ENGINE_VERSION) {
                    $metrics['old_engine_active_variances']++;
                    continue;
                }

                $previous = $this->ledger->previousSubmittedOpname($sourceOpname);
                $previousBySku = $previous?->items?->keyBy(fn ($item) => (string) $item->sku_id) ?? collect();
                $skuIds = $sourceOpname->items->pluck('sku_id')->map(fn ($id) => (string) $id)->values()->all();
                $movementSet = $this->ledger->movementSet($sourceOpname, $previous, $skuIds);
                $movementBySku = $movementSet['by_sku'];
                $opnameBySku = $sourceOpname->items->keyBy(fn ($item) => (string) $item->sku_id);

                foreach ($variance->items as $item) {
                    $skuId = (string) $item->sku_id;
                    $sourceItem = $opnameBySku->get($skuId);
                    $openingItem = $previousBySku->get($skuId);
                    $movement = $movementBySku->get($skuId);
                    $expectedActual = round((float) ($sourceItem?->actual_qty ?? 0), 8);
                    $expectedOpening = round((float) ($openingItem?->actual_qty ?? 0), 8);
                    $expectedGr = round((float) ($movement?->goods_receipt_qty ?? 0), 8);
                    $expectedConsumption = round((float) ($movement?->sale_consumption_qty ?? 0), 8);
                    $expectedOther = round((float) ($movement?->other_movement_qty ?? 0), 8);
                    $expectedTheoretical = round($expectedOpening + $expectedGr + $expectedConsumption + $expectedOther, 8);

                    if ($this->different((float) $item->actual_qty, $expectedActual)) {
                        $metrics['source_actual_mismatch_items']++;
                    }
                    if ($this->different((float) $item->opening_actual_qty, $expectedOpening)) {
                        $metrics['opening_mismatch_items']++;
                    }
                    if ($this->different((float) $item->goods_receipt_qty, $expectedGr)) {
                        $metrics['goods_receipt_mismatch_items']++;
                    }
                    if ($this->different((float) $item->sale_consumption_qty, $expectedConsumption)) {
                        $metrics['consumption_mismatch_items']++;
                    }
                    if ($this->different((float) $item->theoretical_qty, $expectedTheoretical)) {
                        $metrics['theoretical_formula_mismatch_items']++;
                    }
                    if ((string) data_get($item->trace_snapshot ?: [], 'canonical_movement_fingerprint', '') !== (string) $movementSet['fingerprint']) {
                        $metrics['canonical_trace_mismatch_items']++;
                    }
                }
            }
        }

        $failed = $missingTables->isNotEmpty()
            || $missingRoutes->isNotEmpty()
            || ! $menuOk
            || $missingPermissions->isNotEmpty()
            || $failedContracts->isNotEmpty()
            || $metrics['stale_active_variances'] > 0
            || $metrics['old_engine_active_variances'] > 0
            || $metrics['source_actual_mismatch_items'] > 0
            || $metrics['opening_mismatch_items'] > 0
            || $metrics['goods_receipt_mismatch_items'] > 0
            || $metrics['consumption_mismatch_items'] > 0
            || $metrics['theoretical_formula_mismatch_items'] > 0
            || $metrics['canonical_trace_mismatch_items'] > 0;

        $rows = [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->implode(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->implode(', ')],
            ['Stock Variance Access Matrix', $menuOk ? 'OK' : 'MISSING'],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->implode(', ')],
            ['Failed source contracts', $failedContracts->isEmpty() ? '-' : $failedContracts->implode(', ')],
        ];
        foreach ($metrics as $metric => $value) {
            $rows[] = [$metric, (string) $value];
        }
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);

        if ($metrics['eligible_submitted_opnames'] === 0) {
            $this->comment('Tidak ada Stock Opname canonical submitted pada scope tanggal ini. Jika Aktual Stock baru saja di-reset, submit Stock Opname baru setelah reset agar muncul sebagai candidate.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function different(float $stored, float $expected): bool
    {
        return abs($stored - $expected) > 0.0001;
    }
}
