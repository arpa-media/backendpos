<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneReportObservabilityMetricsCommand extends Command
{
    protected $signature = 'report-observability:prune {--days= : Retention days}';
    protected $description = 'Prune persisted report request observability metrics.';

    public function handle(): int
    {
        if (! Schema::hasTable('report_request_metrics')) {
            $this->warn('report_request_metrics table not found.');
            return self::SUCCESS;
        }

        $days = max(1, min(90, (int) ($this->option('days') ?: config('system_health.metrics_retention_days', 7))));
        $deleted = DB::table('report_request_metrics')->where('occurred_at', '<', now()->subDays($days))->delete();
        $this->info("Pruned {$deleted} report metric rows older than {$days} day(s).");

        return self::SUCCESS;
    }
}
