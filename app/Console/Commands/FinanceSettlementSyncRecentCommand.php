<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceSettlementService;
use Illuminate\Console\Command;

final class FinanceSettlementSyncRecentCommand extends Command
{
    protected $signature = 'finance:settlement-sync-recent {--days=14 : Rolling closed/recent business-date window to synchronize}';

    protected $description = 'V8 I12 bounded Settlement source synchronization outside interactive HTTP requests.';

    public function handle(FinanceSettlementService $service): int
    {
        $maxDays = max(1, (int) config('report_performance_budget.max_range_days', 370));
        $days = min($maxDays, max(1, (int) $this->option('days')));
        $to = now('Asia/Jakarta')->toDateString();
        $from = now('Asia/Jakarta')->subDays($days - 1)->toDateString();

        $started = microtime(true);
        $count = $service->syncSources(null, $from, $to);
        $elapsed = round((microtime(true) - $started) * 1000, 2);

        $this->info("Settlement sources synchronized: {$count} rows | {$from} .. {$to} | {$elapsed} ms");

        return self::SUCCESS;
    }
}
