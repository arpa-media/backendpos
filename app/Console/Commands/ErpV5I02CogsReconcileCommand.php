<?php

namespace App\Console\Commands;

use App\Services\Cogs\CanonicalReceiptIdentityService;
use App\Services\Cogs\CogsZeroCostRevaluationService;
use App\Services\Cogs\WarehouseV3ValuationBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ErpV5I02CogsReconcileCommand extends Command
{
    protected $signature = 'erp-v5:i02-cogs-reconcile
        {--outlet= : Outlet ULID tertentu}
        {--from= : Tanggal awal YYYY-MM-DD}
        {--to= : Tanggal akhir YYYY-MM-DD}
        {--all : Rekonsiliasi seluruh histori}
        {--dry-run : Audit tanpa update data}';

    protected $description = 'ERP V5 I02: canonicalize Warehouse/Purchasing GR snapshots and repair zero/stale Item Sold COGS.';

    public function handle(
        CanonicalReceiptIdentityService $identity,
        WarehouseV3ValuationBridgeService $warehouseBridge,
        CogsZeroCostRevaluationService $zeroCost,
    ): int {
        $outletId = trim((string) ($this->option('outlet') ?? '')) ?: null;
        [$from, $to] = $this->dates();
        $dryRun = (bool) $this->option('dry-run');

        foreach (['cogs_purchasing_cost_snapshots', 'cogs_sale_consumptions', 'cogs_sale_consumption_items'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Tabel {$table} belum tersedia.");
                return self::FAILURE;
            }
        }
        if (! $identity->identityColumnsAvailable()) {
            $this->error('Kolom canonical I02 belum tersedia. Jalankan php artisan migrate terlebih dahulu.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'ERP V5 I02 COGS reconcile: outlet=%s, range=%s..%s, mode=%s',
            $outletId ?: 'ALL', $from ?: 'ALL', $to ?: 'ALL', $dryRun ? 'DRY-RUN' : 'REPAIR'
        ));

        // First hide/classify old mirrors, then ensure Warehouse V3 valuation exists,
        // then link mirrors to the authoritative physical snapshot.
        $identityBefore = DB::transaction(
            fn (): array => $identity->reconcileSnapshots($outletId, $from, $to, $dryRun),
            5
        );
        $warehouse = $warehouseBridge->reconcile($outletId, $from, $to, $dryRun);
        $identityAfter = DB::transaction(
            fn (): array => $identity->reconcileSnapshots($outletId, $from, $to, $dryRun),
            5
        );
        $revalue = DB::transaction(
            fn (): array => $zeroCost->revalue($outletId, $from, $to, $dryRun),
            5
        );

        $this->table(['Stage', 'Result'], [
            ['Canonical identity (before bridge)', json_encode($identityBefore, JSON_UNESCAPED_SLASHES)],
            ['Warehouse V3 valuation bridge', json_encode($warehouse['summary'] ?? $warehouse, JSON_UNESCAPED_SLASHES)],
            ['Canonical identity (after bridge)', json_encode($identityAfter, JSON_UNESCAPED_SLASHES)],
            ['Item Sold revaluation', json_encode($revalue, JSON_UNESCAPED_SLASHES)],
        ]);

        $this->newLine();
        $this->info($dryRun ? 'DRY-RUN selesai. Tidak ada perubahan data.' : 'REPAIR selesai. Jalankan erp-v5:i02-cogs-check untuk validasi.');

        return self::SUCCESS;
    }

    /** @return array{0:?string,1:?string} */
    private function dates(): array
    {
        if ((bool) $this->option('all')) {
            return [null, null];
        }

        $today = now('Asia/Jakarta')->toDateString();
        $from = trim((string) ($this->option('from') ?? '')) ?: $today;
        $to = trim((string) ($this->option('to') ?? '')) ?: $from;

        foreach ([$from, $to] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException("Tanggal tidak valid: {$date}. Gunakan YYYY-MM-DD.");
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException('--from tidak boleh lebih besar dari --to.');
        }

        return [$from, $to];
    }
}
