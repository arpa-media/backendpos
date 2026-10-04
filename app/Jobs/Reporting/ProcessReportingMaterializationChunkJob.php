<?php

namespace App\Jobs\Reporting;

use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessReportingMaterializationChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3300;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $chunkId,
        public readonly string $dispatchToken,
    ) {
        $this->onConnection('reporting');
        $this->onQueue('reporting');
    }

    public function handle(ReportingMaterializationOrchestrator $orchestrator): void
    {
        $runId = $orchestrator->executeQueuedChunk($this->chunkId, $this->dispatchToken);

        // V11 I04: each completed/failed chunk wakes the same persisted run so
        // the next batch/stage can be dispatched without waiting for scheduler.
        if ($runId) {
            $orchestrator->kickRun($runId);
        }
    }
}
