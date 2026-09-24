<?php

namespace App\Console\Commands;

use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Illuminate\Console\Command;

class ReportingEngineTickCommand extends Command
{
    protected $signature='reporting-engine:tick {--max-dispatch=4}';
    protected $description='Orchestrate/recover Reporting Engine state and dispatch chunks to the dedicated reporting queue.';

    public function handle(ReportingMaterializationOrchestrator $orchestrator): int
    {
        $result=$orchestrator->tick(max(1,min(20,(int)$this->option('max-dispatch'))));
        $this->line('Reporting Engine: '.json_encode($result,JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
