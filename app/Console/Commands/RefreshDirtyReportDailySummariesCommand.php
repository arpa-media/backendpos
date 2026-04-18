<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryRefreshService;
use Illuminate\Console\Command;

class RefreshDirtyReportDailySummariesCommand extends Command
{
    protected $signature = 'report-daily-summaries:refresh-dirty
        {--limit=60 : Jumlah maksimal baris queue yang diproses per run}
        {--outlet-chunk=4 : Jumlah maksimum grup outlet yang diproses per batch}
        {--date-chunk=2 : Jumlah hari maksimum per refresh window}';

    protected $description = 'Refresh dirty daily summary windows incrementally after transaction mutations.';

    public function handle(ReportDailySummaryRefreshService $refreshService): int
    {
        $result = $refreshService->processPending(
            (int) $this->option('limit'),
            (int) $this->option('outlet-chunk'),
            (int) $this->option('date-chunk'),
        );

        $this->info(sprintf(
            'Dirty daily summary refresh finished. Claimed=%d Processed windows=%d Processed rows=%d Failed rows=%d',
            (int) ($result['claimed'] ?? 0),
            (int) ($result['processed_windows'] ?? 0),
            (int) ($result['processed_rows'] ?? 0),
            (int) ($result['failed_rows'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
