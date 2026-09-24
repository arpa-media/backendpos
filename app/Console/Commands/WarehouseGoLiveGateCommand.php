<?php

namespace App\Console\Commands;

use App\Services\Warehouse\WarehouseHardeningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehouseGoLiveGateCommand extends Command
{
    protected $signature = 'warehouse:go-live-gate {--warehouse=} {--all} {--strict}';
    protected $description = 'Block deployment when Warehouse release readiness requirements are not satisfied.';

    public function handle(WarehouseHardeningService $service): int
    {
        [$ids, $selectedId, $label] = $this->resolveScope();
        $gate = $service->commandGate($ids, $selectedId);
        $this->table(
            ['Category', 'Gate', 'Status', 'Result'],
            collect($gate['checks'])->map(fn (array $row): array => [
                $row['category'], $row['name'], strtoupper($row['status']), $row['message'],
            ])->all()
        );
        $this->newLine();
        $this->line('Scope: '.$label);
        $this->line('Go-Live Status: '.strtoupper($gate['status']));
        $blocked = ! $gate['ready'];
        $warning = (int) ($gate['summary']['warning_count'] ?? 0) > 0;
        return ($blocked || ($this->option('strict') && $warning)) ? self::FAILURE : self::SUCCESS;
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
