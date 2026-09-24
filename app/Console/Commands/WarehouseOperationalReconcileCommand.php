<?php

namespace App\Console\Commands;

use App\Services\Warehouse\WarehouseReportingService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseOperationalReconcileCommand extends Command
{
    protected $signature = 'warehouse:operational-reconcile
        {--warehouse= : Warehouse ID atau code}
        {--from= : Tanggal mulai YYYY-MM-DD}
        {--to= : Tanggal akhir YYYY-MM-DD}
        {--all : Jalankan untuk seluruh Warehouse aktif}';

    protected $description = 'Run Warehouse Iterasi 11 operational reconciliation without changing stock.';

    public function handle(WarehouseReportingService $service): int
    {
        $warehouses = DB::table('outlets')
            ->whereRaw("LOWER(COALESCE(type,'')) = 'warehouse'")
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($warehouses->isEmpty()) {
            $this->error('Tidak ada Warehouse aktif.');
            return self::FAILURE;
        }

        $selected = $warehouses->first();
        $scopeWarehouses = $warehouses;
        $scopeMode = 'all';

        if (! $this->option('all')) {
            $needle = trim((string) $this->option('warehouse'));
            if ($needle !== '') {
                $selected = $warehouses->first(fn ($row): bool => (string) $row->id === $needle || strtoupper((string) $row->code) === strtoupper($needle));
                if (! $selected) {
                    $this->error('Warehouse tidak ditemukan pada ID/code yang diberikan.');
                    return self::FAILURE;
                }
            }
            $scopeWarehouses = collect([$selected]);
            $scopeMode = 'selected';
        }

        $request = Request::create('/cli/warehouse-operational-reconcile', 'GET', [
            'scope' => $scopeMode,
            'date_from' => $this->option('from') ?: now()->startOfMonth()->toDateString(),
            'date_to' => $this->option('to') ?: now()->toDateString(),
        ]);
        $request->attributes->set('warehouse_scope', [
            'selected' => $selected,
            'warehouses' => $scopeWarehouses,
            'all_warehouse_count' => $warehouses->count(),
            'scope_locked' => $scopeMode === 'selected',
            'can_adjust_scope' => $scopeMode === 'all',
        ]);

        try {
            $result = $service->runReconciliation($request);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $snapshot = (array) ($result['snapshot'] ?? []);
        $rows = collect($snapshot['rules'] ?? [])->map(fn (array $row): array => [
            $row['label'] ?? $row['key'] ?? '-',
            (string) ($row['issue_count'] ?? 0),
            strtoupper((string) ($row['status'] ?? '-')),
        ])->all();
        $this->table(['Rule', 'Issues', 'Status'], $rows);
        $this->line('Run ID: '.($result['run_id'] ?: '-'));
        $this->line('Result: '.strtoupper((string) ($result['status'] ?? '-')));

        return ($snapshot['issue_count'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
