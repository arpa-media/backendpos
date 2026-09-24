<?php

namespace App\Console\Commands;

use App\Models\Cogs\StockVariance;
use App\Models\StockInventory\StockOpname;
use App\Services\Cogs\CanonicalStockVarianceLedgerService;
use App\Services\Cogs\StockVarianceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ErpV5I03StockVarianceReconcileCommand extends Command
{
    protected $signature = 'erp-v5:i03-stock-variance-reconcile
        {--outlet= : Filter satu outlet ULID}
        {--from= : Business date awal YYYY-MM-DD}
        {--to= : Business date akhir YYYY-MM-DD}
        {--all : Proses seluruh histori}
        {--dry-run : Audit tanpa mengubah data}';

    protected $description = 'ERP-V5 I03: retire stale variance after Actual Stock reset and rebuild eligible Stock Variance with canonical GR/COGS movement sources.';

    public function __construct(
        private readonly CanonicalStockVarianceLedgerService $ledger,
        private readonly StockVarianceService $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ([
            'stk_stock_opnames', 'stk_stock_opname_items', 'stk_actual_stock_reset_runs',
            'cogs_stock_variances', 'cogs_stock_variance_items',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Tabel {$table} belum tersedia.");
                return self::FAILURE;
            }
        }

        [$from, $to] = $this->dateRange();
        if ($from === false) {
            return self::FAILURE;
        }

        $outletId = trim((string) $this->option('outlet')) ?: null;
        $dryRun = (bool) $this->option('dry-run');
        $scopeText = $this->option('all') ? 'ALL HISTORY' : "{$from} s/d {$to}";
        $this->info('ERP-V5 I03 Stock Variance Reconcile · '.$scopeText.($dryRun ? ' · DRY RUN' : ''));

        $varianceQuery = StockVariance::query()->with('stockOpname')
            ->whereIn('status', [StockVariance::STATUS_CALCULATED, StockVariance::STATUS_SUBMITTED]);
        $opnameQuery = StockOpname::query()->with('items')->where('status', 'submitted')->whereNotNull('submitted_at');

        if ($outletId) {
            $varianceQuery->where('outlet_id', $outletId);
            $opnameQuery->where('outlet_id', $outletId);
        }
        if (! $this->option('all')) {
            $varianceQuery->whereBetween('variance_date', [$from, $to]);
            $opnameQuery->whereBetween('opname_date', [$from, $to]);
        }

        $stats = [
            'active_variances_scanned' => 0,
            'stale_source_found' => 0,
            'stale_source_cancelled' => 0,
            'eligible_opnames' => 0,
            'already_canonical_submitted' => 0,
            'needs_rebuild' => 0,
            'rebuilt_to_calculated' => 0,
            'errors' => 0,
        ];

        foreach ($varianceQuery->orderBy('variance_date')->cursor() as $variance) {
            $stats['active_variances_scanned']++;
            $source = $variance->stockOpname;
            if ($source && $this->ledger->isCanonicalSubmittedOpname($source)) {
                continue;
            }

            $stats['stale_source_found']++;
            if ($dryRun) {
                continue;
            }

            try {
                $this->engine->cancelForOpname((string) $variance->stock_opname_id, 'erp_v5_i03_noncanonical_source_after_actual_reset');
                $stats['stale_source_cancelled']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn("Cancel stale variance {$variance->id} gagal: {$e->getMessage()}");
            }
        }

        foreach ($opnameQuery->orderBy('opname_date')->orderBy('submitted_at')->cursor() as $opname) {
            if (! $this->ledger->isCanonicalSubmittedOpname($opname)) {
                continue;
            }
            $stats['eligible_opnames']++;

            $existing = StockVariance::query()->where('stock_opname_id', $opname->id)->first();
            $engineVersion = (string) data_get($existing?->metadata ?: [], 'engine', '');
            if ($existing?->status === StockVariance::STATUS_SUBMITTED
                && $engineVersion === CanonicalStockVarianceLedgerService::ENGINE_VERSION) {
                $stats['already_canonical_submitted']++;
                continue;
            }

            $stats['needs_rebuild']++;
            if ($dryRun) {
                continue;
            }

            try {
                $rebuilt = $this->engine->calculateForOpname($opname, null, 'erp_v5_i03_reconcile');
                if ($rebuilt->status === StockVariance::STATUS_CALCULATED) {
                    $stats['rebuilt_to_calculated']++;
                } elseif ($rebuilt->status === StockVariance::STATUS_SUBMITTED) {
                    $stats['already_canonical_submitted']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn("Rebuild opname {$opname->id} ({$opname->opname_date}) gagal: {$e->getMessage()}");
            }
        }

        $this->table(['Metric', 'Result'], collect($stats)->map(fn ($value, $key) => [$key, (string) $value])->values()->all());
        if ($dryRun) {
            $this->comment('DRY RUN: tidak ada data yang diubah. Jalankan kembali tanpa --dry-run untuk repair.');
        } elseif ($stats['rebuilt_to_calculated'] > 0) {
            $this->comment('Dokumen yang direbuild dikembalikan ke status CALCULATED agar dapat direview sebelum Submit & Kunci kembali.');
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0:string|false,1:string|null} */
    private function dateRange(): array
    {
        if ($this->option('all')) {
            return [null, null];
        }

        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $from = trim((string) $this->option('from')) ?: $today;
        $to = trim((string) $this->option('to')) ?: $from;

        try {
            $fromDate = CarbonImmutable::createFromFormat('Y-m-d', $from, 'Asia/Jakarta')->startOfDay();
            $toDate = CarbonImmutable::createFromFormat('Y-m-d', $to, 'Asia/Jakarta')->startOfDay();
        } catch (\Throwable) {
            $this->error('--from dan --to wajib format YYYY-MM-DD.');
            return [false, null];
        }

        if ($toDate->lessThan($fromDate)) {
            $this->error('--to tidak boleh lebih kecil dari --from.');
            return [false, null];
        }

        return [$fromDate->toDateString(), $toDate->toDateString()];
    }
}
