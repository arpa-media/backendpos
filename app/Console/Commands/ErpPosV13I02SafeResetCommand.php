<?php

namespace App\Console\Commands;

use App\Services\ErpReset\V13I02SafeResetService;
use Illuminate\Console\Command;
use Throwable;

/** Standalone V13 I02 command: does not override I01 erp:transactions-reset. */
final class ErpPosV13I02SafeResetCommand extends Command
{
    protected $signature = 'erp:transactions-reset-run
        {--scope=all : all atau stock,warehouse,purchasing,finance,cogs}
        {--full : Seluruh periode}
        {--month= : Bulan YYYY-MM (PREVIEW ONLY pada I02)}
        {--from= : Awal periode (PREVIEW ONLY pada I02)}
        {--to= : Akhir periode (PREVIEW ONLY pada I02)}
        {--prepare : Buat signed executable plan jika lolos semua preflight}
        {--dry-run : Alias prepare; tidak mengubah DB}
        {--backup-file= : File backup SQL eksternal yang telah diuji restore}
        {--backup-sha256= : SHA256 file backup yang diverifikasi}
        {--execute : Jalankan hanya pada clone/test DB setelah prepare}
        {--plan= : Plan ID V13I02-...}
        {--resume= : Resume menggunakan plan ID}
        {--status= : Lihat status run berdasarkan plan ID}
        {--confirm= : Ketik tepat RESET ERP TRANSACTIONS}
        {--ack-restore-tested : Mengakui rehearsal restore backup benar-benar lulus}
        {--ack-writers-stopped : Mengakui seluruh API/kasir/DB writers telah dihentikan pada clone}
        {--ack-autopost-stopped : Mengakui scheduler/worker/autopost dimatikan pada clone}
        {--chunk=500 : Baris maksimal per transaksi commit (50-1000)}
        {--max-chunks=0 : Stop dan simpan checkpoint setelah N chunk (0 = unlimited)}';

    protected $description = 'V13 I02: reset executor dengan signed plan, FK DAG, chunk checkpoint; CLONE ONLY. I01 remains unchanged.';

    public function handle(V13I02SafeResetService $service): int
    {
        try {
            if ($id = trim((string) $this->option('status'))) {
                $state = $service->status($id);
                $this->renderState($state);
                return self::SUCCESS;
            }
            $resume = trim((string) $this->option('resume'));
            $execute = (bool) $this->option('execute') || $resume !== '';
            if (!$execute) {
                $this->warn('PREPARE / READ-ONLY: I02 tidak menghapus data tanpa --execute dan signed plan.');
                $data = $service->prepare([
                    'scope'=>$this->option('scope'), 'full'=>$this->option('full'),
                    'month'=>$this->option('month'), 'from'=>$this->option('from'), 'to'=>$this->option('to'),
                    'backup_file'=>$this->option('backup-file'), 'backup_sha256'=>$this->option('backup-sha256'),
                ]);
                $this->table(['Field', 'Value'], [
                    ['Database', $data['database']], ['Environment', $data['environment']],
                    ['Scope', implode(',', $data['scope'])], ['Period', $data['period']['mode']],
                    ['Selected tables', $data['tables']], ['Child-first delete ordering', $data['delete_order']],
                    ['Allowed FK detach operations', count($data['detach_links'])],
                    ['Backup SHA256 verified', $data['backup']['verified'] ? 'YES (restore rehearsal is a separate manual requirement)' : 'NO'],
                    ['Plan ID', $data['plan_id']], ['Status', $data['ready'] ? 'PLAN_READY_CLONE_ONLY' : 'BLOCKED'],
                ]);
                foreach (array_slice($data['blockers'], 0, 30) as $b) $this->warn('[BLOCK] '.$b);
                if (count($data['blockers']) > 30) $this->warn('... '.(count($data['blockers']) - 30).' blockers lain.');
                if ($data['file']) $this->info('Signed plan file: '.$data['file']);
                $this->warn('ALWAYS DISABLED on production and any live POS. No reset has occurred.');
                return $data['ready'] ? self::SUCCESS : self::INVALID;
            }
            if ((bool) $this->option('prepare') || (bool) $this->option('dry-run')) {
                throw new \RuntimeException('Cannot combine prepare/dry-run with execute/resume.');
            }
            $planId = $resume !== '' ? $resume : trim((string) $this->option('plan'));
            $state = $service->execute(
                $planId, (string) $this->option('confirm'), (int) $this->option('chunk'),
                [
                    'restore_tested'=>(bool) $this->option('ack-restore-tested'),
                    'writers_stopped'=>(bool) $this->option('ack-writers-stopped'),
                    'autopost_stopped'=>(bool) $this->option('ack-autopost-stopped'),
                ], (int) $this->option('max-chunks'), $resume !== '',
                function (array $state): void {
                    $this->line('['.$state['phase'].'] table '.($state['table_index'] + 1).' / detached '.$state['links_detached'].' / deleted '.$state['rows_deleted']);
                }
            );
            $this->renderState($state);
            $this->warn('I02 result is NOT a production zero-proof. I03 reconcile/verify is required.');
            return $state['phase'] === 'complete_awaiting_i03' ? self::SUCCESS : self::INVALID;
        } catch (Throwable $e) {
            $this->error('I02 reset stopped: '.$e->getMessage());
            $this->warn('If any chunk committed, restore the tested clone backup, or use --resume=<PLAN_ID> after resolving blocker.');
            return self::FAILURE;
        }
    }

    private function renderState(array $state): void
    {
        $this->table(['Field','Value'], [
            ['Plan ID', $state['plan_id']], ['Phase', $state['phase']],
            ['Table progress index', $state['table_index']], ['Detached links', $state['links_detached']],
            ['Deleted rows (checkpointed)', $state['rows_deleted']],
            ['Remaining non-empty tables', count($state['remaining_rows'] ?? [])],
            ['Last update', $state['updated_at'] ?? '-'],
        ]);
        if (!empty($state['last_error'])) $this->warn('Last error: '.$state['last_error']);
    }
}
