<?php

namespace App\Console\Commands;

use App\Services\StockInventory\ActualStockReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class ErpV5Iteration11ActualStockReconcileCommand extends Command
{
    protected $signature = 'erp-v5:iteration-11-reconcile
        {--outlet= : Batasi repair ke satu outlet ID}
        {--dry-run : Hanya analisa perubahan tanpa menulis data}';

    protected $description = 'ERP-V5 Iteration 11: reconcile Warehouse GR -> Actual/Current Stock authoritative chain.';

    public function handle(ActualStockReconciliationService $service): int
    {
        $outletId = trim((string) ($this->option('outlet') ?? '')) ?: null;
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $service->reconcile($outletId, $dryRun);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('ERP-V5 Iteration 11 Actual/Current Stock Reconciliation — '.strtoupper((string) $result['mode']));

        $rows = [];
        foreach (($result['outlets'] ?? []) as $outlet) {
            $summary = $outlet['summary'] ?? [];
            $rows[] = [
                ($outlet['outlet']['code'] ?? '') ?: ($outlet['outlet']['id'] ?? '-'),
                $outlet['outlet']['name'] ?? '-',
                $summary['gr_lines_scanned'] ?? 0,
                $summary['movements_created'] ?? 0,
                $summary['movements_normalized'] ?? 0,
                $summary['balances_changed'] ?? 0,
                number_format((float) ($summary['quantity_delta_total'] ?? 0), 4, '.', ''),
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Outlet', 'Name', 'GR Lines', 'Movement Create', 'Movement Normalize', 'Balance Change', 'Qty Delta'],
                $rows
            );
        }

        $summary = $result['summary'] ?? [];
        $this->table(['Metric', 'Value'], [
            ['Mode', $result['mode'] ?? ($dryRun ? 'dry-run' : 'repair')],
            ['Outlets', $summary['outlets'] ?? 0],
            ['GR lines scanned', $summary['gr_lines_scanned'] ?? 0],
            ['Movements to create/created', $summary['movements_created'] ?? 0],
            ['Movement normalizations', $summary['movements_normalized'] ?? 0],
            ['Balances to change/changed', $summary['balances_changed'] ?? 0],
            ['Total qty delta', number_format((float) ($summary['quantity_delta_total'] ?? 0), 4, '.', '')],
        ]);

        if ($dryRun) {
            $this->warn('Dry-run tidak mengubah data. Jalankan kembali tanpa --dry-run untuk melakukan repair.');
        } else {
            $this->info('Repair selesai. Command aman dijalankan ulang; derived movement menggunakan reference line yang idempotent.');
        }

        return self::SUCCESS;
    }
}
