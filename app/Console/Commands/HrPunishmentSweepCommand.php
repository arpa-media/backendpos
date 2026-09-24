<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrPunishmentAutomationService;
use Illuminate\Console\Command;

class HrPunishmentSweepCommand extends Command
{
    protected $signature = 'hr:punishment-sweep {--from=} {--to=} {--limit=500}';
    protected $description = 'Detect alpha/late violations and generate SP recommendations using configurable punishment rules.';

    public function handle(HrPunishmentAutomationService $service): int
    {
        $result=$service->sweep($this->option('from') ?: null,$this->option('to') ?: null,(int)$this->option('limit'));
        $this->table(['Metric','Value'],collect($result)->map(fn($v,$k)=>[$k,$v])->values()->all());
        return self::SUCCESS;
    }
}
