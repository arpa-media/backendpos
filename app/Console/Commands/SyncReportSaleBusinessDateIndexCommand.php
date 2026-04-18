<?php

namespace App\Console\Commands;

use App\Services\ReportSaleBusinessDateIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncReportSaleBusinessDateIndexCommand extends Command
{
    protected $signature = 'report-sale-business-dates:sync-recent {--days=45 : Jumlah hari business date yang dipanaskan}';

    protected $description = 'Warm report sale business-date index for recent reporting windows.';

    public function handle(ReportSaleBusinessDateIndexService $service): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $dateTo = now()->toDateString();
        $dateFrom = now()->subDays($days - 1)->toDateString();

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

        $service->ensureCoverage($outletIds, $dateFrom, $dateTo, config('app.timezone', 'Asia/Jakarta'));

        $this->info('Report sale business-date index warmed for ' . $dateFrom . ' until ' . $dateTo . '.');

        return self::SUCCESS;
    }
}
