<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WarmReportDailySummariesCommand extends Command
{
    protected $signature = 'report-daily-summaries:warm-common
        {--days=30 : Range maksimum hari yang dipanaskan}
        {--outlet-chunk=5 : Jumlah outlet per rebuild chunk}
        {--date-chunk=3 : Jumlah hari per rebuild chunk}';

    protected $description = 'Warm report daily summary tables for common finance and owner overview windows.';

    public function handle(ReportDailySummaryService $dailySummaryService): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $outletChunk = max(1, min(50, (int) $this->option('outlet-chunk')));
        $dateChunk = max(1, min(31, (int) $this->option('date-chunk')));
        $outletIds = DB::table('outlets')
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();

        if ($outletIds === []) {
            $this->warn('No outlets found.');
            return self::SUCCESS;
        }

        $dateTo = now()->toDateString();
        $dateFrom = now()->subDays($days - 1)->toDateString();
        $dailySummaryService->ensureCoverage($outletIds, $dateFrom, $dateTo, config('app.timezone', 'Asia/Jakarta'), [
            'outlet_chunk' => $outletChunk,
            'date_chunk_days' => $dateChunk,
        ]);

        $this->info('Report daily summaries warmed successfully with chunked rebuilds.');
        $this->line(sprintf('Range: %s to %s | outlet chunk: %d | date chunk: %d', $dateFrom, $dateTo, $outletChunk, $dateChunk));

        return self::SUCCESS;
    }
}
