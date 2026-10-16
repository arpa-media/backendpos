<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrSquadAssignmentSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reconcile preexisting Squad -> Personalia assignments. Preview by default. */
class HrSquadAssignmentsSyncCommand extends Command
{
    protected $signature = 'hr:squad-assignments-sync
        {--apply : Apply changes (default is dry-run)}
        {--squad-id= : One Data Squad ID}
        {--limit=0 : Maximum number of Squad rows to inspect (0 = all)}';

    protected $description = 'V12 I04: preview/apply HR Data Squad penugasan as source of truth for Personalia Assignment.';

    public function handle(HrSquadAssignmentSyncService $sync): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $id = trim((string) ($this->option('squad-id') ?? ''));
        $counts = ['unchanged' => 0, 'would_update' => 0, 'updated' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0];
        $seen = 0;
        $this->info($apply ? 'APPLY: Data Squad -> Personalia Assignment' : 'DRY-RUN: no database changes');

        $run = function ($rows) use ($sync, $apply, $limit, &$counts, &$seen): bool {
            foreach ($rows as $squad) {
                if ($limit && $seen >= $limit) return false;
                $seen++;
                try {
                    $r = $sync->synchronize((int) $squad->id, $apply, false);
                    $state = (string) ($r['status'] ?? 'failed');
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                    $msg = sprintf('%s | squad=%s | nisj=%s | %s -> %s | %s',
                        strtoupper($state), $squad->id, $squad->nisj ?: '-',
                        $r['before_outlet_id'] ?? '-', $r['target_outlet_id'] ?? '-',
                        $r['reason'] ?? ($r['action'] ?? '-'));
                    $this->line($msg);
                } catch (\Throwable $e) {
                    $counts['failed']++;
                    $this->error('FAILED | squad='.$squad->id.' | '.$e->getMessage());
                }
            }
            return ! $limit || $seen < $limit;
        };
        $q = DB::table('HR_squads')->whereNull('deleted_at')->select(['id', 'nisj'])->orderBy('id');
        if ($id !== '') $q->where('id', $id);
        $q->chunkById(100, $run, 'id');
        $this->table(['Result', 'Rows'], collect($counts)->map(fn ($n, $s) => [$s, $n])->values()->all());
        $this->info("Inspected {$seen} Squad rows. ".($apply ? 'Changes applied where safe.' : 'Run with --apply after reviewing preview.'));
        return ($counts['failed'] || $counts['blocked']) ? self::FAILURE : self::SUCCESS;
    }
}
