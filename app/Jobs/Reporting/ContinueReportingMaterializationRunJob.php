<?php

namespace App\Jobs\Reporting;

use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * V11 I04 lightweight wake-up job.
 *
 * It never performs materialization itself. It only asks the orchestrator to
 * persist/dispatch the next eligible batch to the dedicated reporting worker.
 */
class ContinueReportingMaterializationRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        public readonly string $runId,
        public readonly string $reason = 'continuation',
    ) {
        $this->onConnection('reporting');
        $this->onQueue('reporting');
    }

    public function handle(ReportingMaterializationOrchestrator $orchestrator): void
    {
        $orchestrator->kickRun($this->runId);
    }
}
