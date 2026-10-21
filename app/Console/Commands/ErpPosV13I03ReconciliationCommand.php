<?php

namespace App\Console\Commands;

use App\Services\ErpReset\V13I03ReconciliationService;
use Illuminate\Console\Command;
use Throwable;

final class ErpPosV13I03ReconciliationCommand extends Command
{
    protected $signature = 'erp:transactions-reset-verify
        {--inspect : Safe metadata overview; no full table scans and no certificate}
        {--baseline : Snapshot ALL protected/target row counts BEFORE I02 execution (clone only)}
        {--verify : Verify FULL+ALL I02 completed run against signed pre-reset snapshot (clone only)}
        {--plan= : Exact signed I02 plan ID (V13I02-...)}
        {--snapshot= : Snapshot ID from --baseline (V13I03-...)}';

    protected $description = 'V13 I03 reconciliation: signed pre/post full-reset zero checks, protected row-count invariants (READ ONLY, clone only).';

    public function handle(V13I03ReconciliationService $service): int
    {
        try {
            $modes = (int) $this->option('inspect') + (int) $this->option('baseline') + (int) $this->option('verify');
            if ($modes !== 1) throw new \InvalidArgumentException('Choose exactly one: --inspect | --baseline | --verify.');
            if ($this->option('inspect')) {
                $data = $service->inspect();
                $this->table(['Field','Value'], [
                    ['Database', $data['database']], ['Environment', $data['environment']],
                    ['Reset target tables', $data['selected_tables']], ['Estimated reset rows', $data['estimated_reset_rows']],
                    ['Schema fingerprint', $data['schema_sha256']], ['Manifest blockers', count($data['blockers'])],
                ]);
                foreach (array_slice($data['blockers'],0,20) as $b) $this->warn(($b['code'] ?? 'BLOCK').' '.$b['table']);
                $this->warn('ESTIMATE ONLY. No deletions. Never a ZERO PROOF.');
                return $data['blockers'] ? self::INVALID : self::SUCCESS;
            }
            $plan = (string) $this->option('plan');
            $progress = function (int $done, int $total, string $table): void {
                $this->line('Scanned '.$done.'/'.$total.' tables; latest: '.$table);
            };
            if ($this->option('baseline')) {
                $result = $service->snapshot($plan, $progress);
                $this->table(['Field','Value'], [
                    ['Plan', $result['plan_id']], ['Snapshot', $result['snapshot_id']],
                    ['Reset tables', $result['selected_tables']], ['Protected tables', $result['protected_tables']],
                    ['Rows in reset scope', $result['selected_rows']], ['Signed file', $result['file']],
                ]);
                $this->warn('This is NOT a backup. Freeze all clone writers; then execute I02 on the same clone.');
                return self::SUCCESS;
            }
            $result = $service->verify($plan, (string) $this->option('snapshot'), $progress);
            $this->table(['Field','Value'], [
                ['Status', $result['status']], ['Plan', $plan], ['Report', $result['report_id']],
                ['Tables inspected', $result['checked_tables']], ['Remaining reset rows', $result['remaining_reset_rows']],
                ['Nonempty reset tables', count($result['nonzero_reset_tables'])],
                ['Protected count changes', count($result['changed_protected_count_tables'])],
                ['Violations', count($result['violations'])], ['Signed JSON', $result['report_file']],
            ]);
            foreach (array_slice($result['violations'],0,30) as $v) $this->warn('[FAIL] '.$v);
            $this->warn('No production authorization; historical/monthly replay and content-level master hashing are NOT proven.');
            return $result['status'] === 'PASS_CLONE_ONLY' ? self::SUCCESS : self::INVALID;
        } catch (Throwable $e) {
            $this->error('I03 stopped safely: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
