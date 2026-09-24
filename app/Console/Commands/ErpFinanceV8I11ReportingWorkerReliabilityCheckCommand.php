<?php

namespace App\Console\Commands;

use App\Jobs\Reporting\ProcessReportingMaterializationChunkJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV8I11ReportingWorkerReliabilityCheckCommand extends Command
{
    protected $signature='erp-finance-v8:i11-reporting-worker-check';
    protected $description='Validate V8 I11 dedicated reporting queue, lease/recovery schema, scheduler dispatcher, and active worker state.';

    public function handle(): int
    {
        $checks=[];
        $checks[]=$this->check('reporting queue connection', config('queue.connections.reporting.driver')==='database', json_encode(config('queue.connections.reporting')));
        $job=new ProcessReportingMaterializationChunkJob('01TESTCHUNK00000000000000','01TESTTOKEN00000000000000');
        $retry=(int)config('queue.connections.reporting.retry_after',0);
        $checks[]=$this->check('retry_after > worker timeout', $retry>$job->timeout, "retry_after={$retry}; timeout={$job->timeout}");
        $checks[]=$this->check('jobs table', Schema::hasTable('jobs'), 'database queue requires jobs table');
        $checks[]=$this->check('run tables', Schema::hasTable('report_materialization_runs')&&Schema::hasTable('report_materialization_run_chunks')&&Schema::hasTable('report_materialization_settings'), 'I09/I11 orchestration tables');
        foreach(['dispatch_token','worker_token','available_at','dispatched_at','claimed_at','heartbeat_at','lease_expires_at','recovery_count'] as $column) {
            $checks[]=$this->check("chunk column {$column}", Schema::hasColumn('report_materialization_run_chunks',$column), $column);
        }
        foreach(['worker_lease_seconds','max_attempts','retry_backoff_seconds','dispatch_batch','last_dispatch_at','last_worker_heartbeat_at','last_recovery_at'] as $column) {
            $checks[]=$this->check("settings column {$column}", Schema::hasColumn('report_materialization_settings',$column), $column);
        }

        $bootstrap=(string)@file_get_contents(base_path('bootstrap/app.php'));
        $orchestrator=(string)@file_get_contents(app_path('Services/Reporting/ReportingMaterializationOrchestrator.php'));
        $checks[]=$this->check('scheduler dispatch command', str_contains($bootstrap,'reporting-engine:tick --max-dispatch=4'), 'scheduler should dispatch, not execute heavy chunks');
        $checks[]=$this->check('dedicated job dispatch', str_contains($orchestrator,'ProcessReportingMaterializationChunkJob::dispatch'), 'orchestrator -> reporting queue');
        $checks[]=$this->check('stale lease recovery', str_contains($orchestrator,'recoverStaleLeasesLocked'), 'expired running/dispatched chunks recover automatically');
        $checks[]=$this->check('stable stage planning', str_contains($orchestrator,"'pending_stage'"), 'Daily/Hourly/Monthly totals planned up-front');

        if(Schema::hasTable('report_materialization_run_chunks')) {
            $stale=DB::table('report_materialization_run_chunks')->whereIn('status',['running','dispatched'])->whereNotNull('lease_expires_at')->where('lease_expires_at','<',now())->count();
            $running=DB::table('report_materialization_run_chunks')->where('status','running')->count();
            $dispatched=DB::table('report_materialization_run_chunks')->where('status','dispatched')->count();
            $this->line("Runtime: running={$running}; dispatched={$dispatched}; stale={$stale}");
            $checks[]=$this->check('no stale leases now', $stale===0, "stale={$stale}; next scheduler tick should recover stale leases");
        }

        if(Schema::hasTable('report_materialization_settings')) {
            $s=DB::table('report_materialization_settings')->where('id','default')->first();
            $this->line('Scheduler last tick: '.($s->last_tick_at ?? 'never'));
            $this->line('Worker last activity: '.($s->last_worker_heartbeat_at ?? 'never'));
        }

        $failed=collect($checks)->contains(false);
        $this->newLine();
        $this->line($failed?'I11 RESULT: FAIL':'I11 RESULT: PASS');
        return $failed?self::FAILURE:self::SUCCESS;
    }

    private function check(string $label,bool $ok,string $detail=''): bool
    {
        $this->line(sprintf('[%s] %s%s',$ok?'PASS':'FAIL',$label,$detail!==''?' — '.$detail:''));
        return $ok;
    }
}
