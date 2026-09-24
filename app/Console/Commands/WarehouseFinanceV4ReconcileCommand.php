<?php

namespace App\Console\Commands;

use App\Services\Warehouse\FinanceV4\WarehouseFinanceReconciliationV4Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WarehouseFinanceV4ReconcileCommand extends Command
{
    protected $signature = 'warehouse:finance-v4-reconcile
        {--warehouse= : Warehouse ID atau code}
        {--all : Audit seluruh Warehouse yang memiliki storage/transaction}
        {--from= : Tanggal awal YYYY-MM-DD}
        {--to= : Tanggal akhir YYYY-MM-DD}
        {--capture-baseline : Capture cut-over baseline sebelum run}
        {--force-baseline : Izinkan maintenance/test re-baseline}
        {--fail-on-warning : Exit code 2 bila hanya warning}';

    protected $description = 'Warehouse Finance v4 reconciliation and deployment release gate.';

    public function handle(WarehouseFinanceReconciliationV4Service $service): int
    {
        if (! Schema::hasTable('wh_v4_finance_recon_runs')) {
            $this->error('Migration Iterasi 07 belum dijalankan.');
            return 1;
        }
        $ids = $this->resolveWarehouseIds();
        if ($ids === []) { $this->error('Warehouse tidak ditemukan. Gunakan --warehouse=CODE/ID atau --all.'); return 1; }
        $mode = $this->option('all') ? 'all' : 'warehouse';
        $to = (string)($this->option('to') ?: now('Asia/Jakarta')->toDateString());
        $from = (string)($this->option('from') ?: now('Asia/Jakarta')->subDays(30)->toDateString());

        if ($this->option('capture-baseline')) {
            $service->captureBaseline($ids,'',(bool)$this->option('force-baseline'));
            $this->info('Cut-over baseline captured. Jalankan Operational Reconciliation setelah baseline sebelum release gate final.');
        }

        $result = $service->run($ids,$mode,$from,$to,null);
        $this->line('Status: '.$result['status']);
        $this->line('Critical: '.$result['critical_count'].' | Warning: '.$result['warning_count'].' | Issues: '.$result['issue_count']);
        foreach (array_slice($result['issues'],0,25) as $issue) {
            $this->line(sprintf('[%s] %s - %s',$issue['severity'],$issue['rule_key'],$issue['message']));
        }
        if ((int)$result['critical_count'] > 0) return 1;
        if ((bool)$this->option('fail-on-warning') && (int)$result['warning_count'] > 0) return 2;
        return 0;
    }

    private function resolveWarehouseIds(): array
    {
        if ($this->option('all')) {
            $ids = collect();
            if (Schema::hasTable('wh_storages')) $ids = $ids->merge(DB::table('wh_storages')->distinct()->pluck('warehouse_id'));
            if (Schema::hasTable('wh_ledger_postings')) $ids = $ids->merge(DB::table('wh_ledger_postings')->distinct()->pluck('warehouse_id'));
            return $ids->filter()->map(fn($id)=>(string)$id)->unique()->values()->all();
        }
        $needle = trim((string)$this->option('warehouse'));
        if ($needle === '') return [];
        $row = DB::table('outlets')->where('id',$needle)->orWhere('code',$needle)->first(['id']);
        return $row ? [(string)$row->id] : [];
    }
}
