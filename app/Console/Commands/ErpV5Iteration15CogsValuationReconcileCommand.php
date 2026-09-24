<?php

namespace App\Console\Commands;

use App\Services\Cogs\CogsZeroCostRevaluationService;
use App\Services\Cogs\WarehouseV3ValuationBridgeService;
use Illuminate\Console\Command;

class ErpV5Iteration15CogsValuationReconcileCommand extends Command
{
    protected $signature = 'erp-v5:iteration-15-reconcile
        {--outlet= : Outlet ULID tertentu}
        {--date-from= : Business date awal YYYY-MM-DD}
        {--date-to= : Business date akhir YYYY-MM-DD}
        {--dry-run : Hanya analisis tanpa menulis perubahan}';

    protected $description = 'ERP V5 Iteration 15: bridge Warehouse V3 GR valuation ke COGS dan repair zero-cost consumption.';

    public function handle(
        WarehouseV3ValuationBridgeService $bridge,
        CogsZeroCostRevaluationService $zeroCost,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $outletId = $this->nullableString($this->option('outlet'));
        $dateFrom = $this->nullableString($this->option('date-from'));
        $dateTo = $this->nullableString($this->option('date-to'));

        $this->info('ERP V5 Iteration 15 — COGS Warehouse V3 Valuation Reconcile');
        $this->line('Mode: '.($dryRun ? 'DRY-RUN' : 'REPAIR'));
        if ($outletId) $this->line('Outlet: '.$outletId);
        if ($dateFrom || $dateTo) $this->line('Period: '.($dateFrom ?: '-').' s/d '.($dateTo ?: '-'));

        $bridgeResult = $bridge->reconcile($outletId, $dateFrom, $dateTo, $dryRun);
        $bridgeSummary = (array) ($bridgeResult['summary'] ?? []);
        $this->newLine();
        $this->info('Warehouse V3 → COGS valuation bridge');
        $this->table(['Metric', 'Value'], collect($bridgeSummary)->map(fn ($value, $key) => [$key, $value])->values()->all());

        $revalueResult = $zeroCost->revalue($outletId, $dateFrom, $dateTo, $dryRun);
        $this->newLine();
        $this->info('Zero-cost Item Sold / Recipe Consumption revaluation');
        $this->table(['Metric', 'Value'], collect($revalueResult)->map(fn ($value, $key) => [$key, $value])->values()->all());

        $unresolved = (int) ($bridgeSummary['unresolved_cost'] ?? 0) + (int) ($revalueResult['unresolved'] ?? 0);
        $closed = (int) ($bridgeSummary['closed_period_skipped'] ?? 0) + (int) ($revalueResult['closed_period_skipped'] ?? 0);

        if ($unresolved > 0) {
            $this->warn("Masih ada {$unresolved} baris dengan cost tidak tersedia. Cek valuation Warehouse/batch untuk SKU terkait.");
        }
        if ($closed > 0) {
            $this->warn("Ada {$closed} baris yang berada pada COGS period CLOSED dan sengaja tidak dimutasi.");
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Dry-run selesai. Jalankan ulang tanpa --dry-run untuk apply repair.'
            : 'Iteration 15 reconcile selesai. Jalankan erp-v5:iteration-15-check untuk final gate.');

        return self::SUCCESS;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
