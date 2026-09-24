<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrContractLifecycleService;
use Illuminate\Console\Command;

class HrContractLifecycleSweepCommand extends Command
{
    protected $signature = 'hr:contract-lifecycle-sweep {--limit=200}';
    protected $description = 'Apply effective-dated HR contract documents, expiry, and reminders.';

    public function handle(HrContractLifecycleService $service): int
    {
        $result = $service->sweep((int) $this->option('limit'));
        $this->info('Contract lifecycle sweep selesai: '.json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
