<?php

namespace App\Console\Commands;

use App\Jobs\Reporting\ContinueReportingMaterializationRunJob;
use App\Jobs\Reporting\ProcessReportingMaterializationChunkJob;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV11I04BackgroundRunnerCheckCommand extends Command
{
    protected $signature = 'erp-pos-v11:i04-check {--runtime : Tampilkan snapshot queue/worker saat ini}';
    protected $description = 'Check ERP POS V11 I04 Web-to-CLI Background Materialization Runner.';

    public function handle(): int
    {
        $checks = [
            'Materialization settings table' => Schema::hasTable('report_materialization_settings'),
            'Materialization runs table' => Schema::hasTable('report_materialization_runs'),
            'Materialization chunks table' => Schema::hasTable('report_materialization_run_chunks'),
            'Reporting queue connection exists' => config('queue.connections.reporting.driver') !== null,
            'Reporting queue uses database driver' => config('queue.connections.reporting.driver') === 'database',
            'Reporting queue name configured' => (string) config('queue.connections.reporting.queue', '') !== '',
            'Orchestrator kickRun available' => method_exists(ReportingMaterializationOrchestrator::class, 'kickRun'),
            'Orchestrator chunk execution returns continuation context' => method_exists(ReportingMaterializationOrchestrator::class, 'executeQueuedChunk'),
            'Chunk job is queued' => is_subclass_of(ProcessReportingMaterializationChunkJob::class, ShouldQueue::class),
            'Continuation job is queued' => is_subclass_of(ContinueReportingMaterializationRunJob::class, ShouldQueue::class),
            'Run progress GET route registered' => Route::has('console.control-center.runs.show.v11-i04'),
            'Manual kick route registered' => Route::has('console.control-center.runs.kick.v11-i04'),
        ];

        $rows = [];
        $failed = 0;
        foreach ($checks as $label => $ok) {
            $rows[] = [$label, $ok ? 'PASS' : 'FAIL'];
            if (! $ok) $failed++;
        }
        $this->table(['Check', 'Result'], $rows);

        $this->newLine();
        $this->line('Queue connection : reporting');
        $this->line('Queue name       : '.(string) config('queue.connections.reporting.queue', 'reporting'));
        $this->line('Worker command   : php artisan queue:work reporting --queue='.(string) config('queue.connections.reporting.queue', 'reporting').' --sleep=1 --tries=1 --timeout=3300');
        $this->line('Scheduler role   : automatic maintenance/fallback; manual run I04 tidak menunggu scheduler tick.');

        if ($this->option('runtime') && Schema::hasTable('report_materialization_settings')) {
            $settings = DB::table('report_materialization_settings')->where('id', 'default')->first();
            $queueDepth = Schema::hasTable('jobs')
                ? DB::table('jobs')->where('queue', (string) config('queue.connections.reporting.queue', 'reporting'))->count()
                : null;
            $activeRun = Schema::hasTable('report_materialization_runs')
                ? DB::table('report_materialization_runs')->whereIn('status', ['queued','running','pause_requested','paused','cancel_requested','waiting_window'])->orderBy('created_at')->first()
                : null;

            $this->newLine();
            $this->table(['Runtime', 'Value'], [
                ['last_scheduler_tick_at', $settings->last_tick_at ?? '-'],
                ['last_worker_activity_at', $settings->last_worker_heartbeat_at ?? '-'],
                ['last_dispatch_at', $settings->last_dispatch_at ?? '-'],
                ['reporting_queue_depth', $queueDepth === null ? '-' : (string) $queueDepth],
                ['active_run', $activeRun ? ((string) $activeRun->id.' / '.(string) $activeRun->status.' / '.(string) ($activeRun->progress_percent ?? 0).'%') : '-'],
            ]);
        }

        if ($failed > 0) {
            $this->error("V11 I04 CHECK: FAIL ({$failed})");
            return self::FAILURE;
        }

        $this->info('V11 I04 CHECK: PASS');
        return self::SUCCESS;
    }
}
