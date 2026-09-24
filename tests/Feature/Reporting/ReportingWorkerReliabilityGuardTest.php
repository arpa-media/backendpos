<?php

namespace Tests\Feature\Reporting;

use App\Jobs\Reporting\ProcessReportingMaterializationChunkJob;
use Tests\TestCase;

class ReportingWorkerReliabilityGuardTest extends TestCase
{
    public function test_reporting_queue_retry_window_exceeds_worker_timeout(): void
    {
        $job = new ProcessReportingMaterializationChunkJob('chunk','token');
        $this->assertSame('database', config('queue.connections.reporting.driver'));
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.reporting.retry_after'));
    }

    public function test_scheduler_dispatches_instead_of_running_materialization_inline(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $orchestrator = file_get_contents(app_path('Services/Reporting/ReportingMaterializationOrchestrator.php'));

        $this->assertStringContainsString('reporting-engine:tick --max-dispatch=4', $bootstrap);
        $this->assertStringContainsString('ProcessReportingMaterializationChunkJob::dispatch', $orchestrator);
        $this->assertStringContainsString('executeQueuedChunk', $orchestrator);
        $this->assertStringContainsString('recoverStaleLeasesLocked', $orchestrator);
    }

    public function test_downstream_stages_are_planned_up_front_for_stable_progress(): void
    {
        $source = file_get_contents(app_path('Services/Reporting/ReportingMaterializationOrchestrator.php'));
        $this->assertStringContainsString('planHourlyChunks($runId', $source);
        $this->assertStringContainsString('planMonthlyChunks($runId', $source);
        $this->assertStringContainsString("'pending_stage'", $source);
    }
}
