<?php

namespace App\Console\Commands;

use App\Services\ReportSaleScopeCacheService;
use Illuminate\Console\Command;

class PruneReportSaleScopeCacheCommand extends Command
{
    protected $signature = 'report-sale-scope-cache:prune';
    protected $description = 'Delete expired rows from report_sale_scope_cache';

    public function handle(ReportSaleScopeCacheService $service): int
    {
        $deleted = $service->pruneExpired();
        $this->info("Deleted {$deleted} expired rows from report_sale_scope_cache.");

        return self::SUCCESS;
    }
}
