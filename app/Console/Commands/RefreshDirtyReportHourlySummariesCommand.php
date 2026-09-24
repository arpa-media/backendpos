<?php

namespace App\Console\Commands;

use App\Services\Operational\ReportHourlySummaryService;
use App\Support\AnalyticsResponseCache;
use Illuminate\Console\Command;

class RefreshDirtyReportHourlySummariesCommand extends Command
{
    protected $signature = 'report-hourly-summaries:refresh-dirty {--limit=120}';
    protected $description = 'Refresh hourly reporting facts only where daily materialization is newer or hourly coverage is missing.';

    public function handle(ReportHourlySummaryService $service): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $from = now()->subDays(369)->toDateString();
        $to = now()->toDateString();
        $pairs = $service->stalePairs($from, $to, $limit);

        if ($pairs->isEmpty()) {
            $this->info('Hourly summaries already fresh.');
            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($pairs->groupBy(fn ($row) => (string) ($row->business_date ?? '')) as $date => $rows) {
            $outletIds = $rows->pluck('outlet_id')->map(fn ($value) => (string) $value)->filter()->unique()->values()->all();
            if ($date === '' || $outletIds === []) continue;
            $service->refreshDate($outletIds, $date);
            $processed += count($outletIds);
            $this->line(sprintf('%s · %d outlet refreshed', $date, count($outletIds)));
        }

        if ($processed > 0) {
            AnalyticsResponseCache::bumpVersion('hourly-summary-refresh:'.$processed);
        }

        $this->info("Hourly refresh complete: {$processed} outlet-date row(s).");
        return self::SUCCESS;
    }
}
