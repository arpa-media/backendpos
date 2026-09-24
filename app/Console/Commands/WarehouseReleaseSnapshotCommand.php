<?php

namespace App\Console\Commands;

use App\Services\Warehouse\WarehouseHardeningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class WarehouseReleaseSnapshotCommand extends Command
{
    protected $signature = 'warehouse:release-snapshot {--warehouse=} {--all} {--output=}';
    protected $description = 'Create a non-sensitive pre/post deployment Warehouse manifest for backup and rollback comparison.';

    public function handle(WarehouseHardeningService $service): int
    {
        [$ids, $selectedId, $label] = $this->resolveScope();
        $tables = [
            'stk_inventory_balances', 'stk_inventory_movements', 'wh_batch_balances', 'wh_stock_units',
            'wh_ledger_postings', 'wh_ledger_entries', 'wh_security_audit_events', 'wh_signed_scan_tokens',
            'wh_health_check_runs', 'wh_uat_runs', 'wh_operational_reconciliation_runs',
        ];
        $counts = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) { $counts[$table] = null; continue; }
            $query = DB::table($table);
            $column = match ($table) {
                'stk_inventory_balances' => 'outlet_id',
                'stk_inventory_movements' => 'outlet_id',
                default => Schema::hasColumn($table, 'warehouse_id') ? 'warehouse_id' : null,
            };
            if ($column) $query->whereIn($column, $ids);
            $counts[$table] = $query->count();
        }

        $snapshot = [
            'snapshot_id' => 'WH-'.strtoupper((string) Str::ulid()),
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'app_debug' => (bool) config('app.debug'),
            'database_connection' => config('database.default'),
            'scope' => ['label' => $label, 'warehouse_ids' => $ids],
            'latest_migration' => Schema::hasTable('migrations') ? DB::table('migrations')->orderByDesc('batch')->orderByDesc('id')->value('migration') : null,
            'table_counts' => $counts,
            'go_live_gate' => $service->commandGate($ids, $selectedId),
        ];

        $directory = storage_path('app/private/warehouse/release-snapshots');
        File::ensureDirectoryExists($directory);
        $output = trim((string) $this->option('output'));
        $path = $output !== '' ? $output : $directory.'/warehouse-release-'.now()->format('Ymd-His').'.json';
        File::put($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info('Warehouse release snapshot created.');
        $this->line($path);
        $this->line('SHA-256: '.hash_file('sha256', $path));
        return self::SUCCESS;
    }

    private function resolveScope(): array
    {
        $query = DB::table('outlets')->where('type', 'warehouse')->where('is_active', true);
        $warehouse = trim((string) $this->option('warehouse'));
        if ($warehouse !== '') {
            $query->where(fn ($q) => $q->where('id', $warehouse)->orWhere('code', $warehouse));
        } elseif (! $this->option('all')) {
            throw new RuntimeException('Gunakan --all atau --warehouse=CODE/ID.');
        }
        $rows = $query->orderBy('code')->get(['id', 'code', 'name']);
        if ($rows->isEmpty()) throw new RuntimeException('Warehouse aktif tidak ditemukan.');
        $ids = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();
        return [$ids, count($ids) === 1 ? $ids[0] : null, $rows->map(fn ($row) => $row->code.' - '.$row->name)->implode(', ')];
    }
}
