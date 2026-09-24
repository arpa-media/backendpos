<?php

namespace App\Console\Commands;

use App\Services\Warehouse\WarehouseLedgerReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehouseLedgerReconcileCommand extends Command
{
    protected $signature = 'warehouse:ledger-reconcile
        {--warehouse= : Warehouse ULID atau code. Kosong berarti seluruh warehouse aktif}
        {--sku= : Batasi ke satu SKU ULID}
        {--repair : Sinkronkan aggregate balance dari batch/storage detail}';

    protected $description = 'Check atau repair projection aggregate Warehouse dari wh_batch_balances.';

    public function handle(WarehouseLedgerReconciliationService $service): int
    {
        if (! Schema::hasTable('wh_reconciliation_runs')) {
            $this->error('Tabel Iterasi 04 belum tersedia. Jalankan php artisan migrate.');
            return self::FAILURE;
        }

        $warehouses = DB::table('outlets')
            ->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")
            ->where('is_active', true)
            ->when($this->option('warehouse'), function ($query, $value): void {
                $query->where(fn ($builder) => $builder->where('id', $value)->orWhere('code', strtoupper(trim((string) $value))));
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        if ($warehouses->isEmpty()) {
            $this->error('Warehouse tidak ditemukan.');
            return self::FAILURE;
        }

        $rows = [];
        $failed = false;
        foreach ($warehouses as $warehouse) {
            try {
                $run = $service->run(
                    (string) $warehouse->id,
                    (bool) $this->option('repair'),
                    null,
                    $this->option('sku') ? (string) $this->option('sku') : null
                );
                $rows[] = [
                    $warehouse->code,
                    $warehouse->name,
                    $run->mode,
                    $run->checked_sku_count,
                    $run->variance_sku_count,
                    $run->repaired_sku_count,
                    $run->negative_balance_count,
                    $run->status,
                ];
                $failed = $failed || $run->status !== 'completed';
            } catch (\Throwable $exception) {
                $rows[] = [$warehouse->code, $warehouse->name, $this->option('repair') ? 'repair' : 'check', '-', '-', '-', '-', 'FAILED: '.$exception->getMessage()];
                $failed = true;
            }
        }

        $this->table(
            ['Code', 'Warehouse', 'Mode', 'Checked SKU', 'Variance', 'Repaired', 'Negative', 'Status'],
            $rows
        );

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
