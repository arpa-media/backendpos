<?php

namespace App\Console\Commands;

use App\Services\Warehouse\WarehouseHardeningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WarehouseHardeningCheckCommand extends Command
{
    protected $signature = 'warehouse:hardening-check {--warehouse=} {--all} {--record}';
    protected $description = 'Check security, concurrency, audit, mobile queue, and release readiness contracts.';

    public function handle(WarehouseHardeningService $service): int
    {
        [$ids, $selectedId, $label] = $this->resolveScope();
        $checks = $service->commandChecks($ids);
        $this->table(
            ['Category', 'Check', 'Status', 'Result'],
            collect($checks)->map(fn (array $row): array => [
                $row['category'], $row['name'], strtoupper($row['status']), $row['message'],
            ])->all()
        );
        $failures = count(array_filter($checks, fn (array $row): bool => $row['status'] === 'fail'));
        $warnings = count(array_filter($checks, fn (array $row): bool => $row['status'] === 'warning'));
        if ($this->option('record')) {
            $run = $service->createCommandHealthRun($ids, $selectedId, null, 'CLI-'.strtoupper((string) Str::ulid()));
            $this->line('Health Run ID: '.$run['id']);
        }
        $this->newLine();
        $this->line(sprintf('Scope: %s | PASS %d | WARNING %d | FAILED %d', $label, count($checks)-$warnings-$failures, $warnings, $failures));
        return $failures > 0 ? self::FAILURE : self::SUCCESS;
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
        $selectedId = count($ids) === 1 ? $ids[0] : null;
        $label = $rows->map(fn ($row) => $row->code.' - '.$row->name)->implode(', ');
        return [$ids, $selectedId, $label];
    }
}
