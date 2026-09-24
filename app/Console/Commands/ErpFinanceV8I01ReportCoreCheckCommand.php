<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV8I01ReportCoreCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i01-report-core-check
        {--days=370 : Rolling coverage window to inspect (max 400)}';

    protected $description = 'Read-only verification for ERP Finance V8 I01 reporting core and historical coverage.';

    public function handle(): int
    {
        $days = max(1, min(400, (int) $this->option('days')));
        $timezone = TransactionDate::appTimezone();
        $to = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);
        $from = $to->subDays($days - 1);
        $failed = false;

        $this->info(sprintf(
            'ERP Finance V8 I01 report-core check %s .. %s (%s)',
            $from->toDateString(),
            $to->toDateString(),
            $timezone,
        ));

        $requiredTables = [
            'report_sale_business_dates',
            'report_daily_summary_coverage',
            'report_daily_sales_summaries',
            'report_daily_payment_summaries',
            'report_daily_channel_summaries',
            'report_daily_category_summaries',
            'report_daily_product_summaries',
            'report_daily_variant_summaries',
            'report_daily_summary_refresh_queue',
        ];

        foreach ($requiredTables as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('[%s] table %s', $ok ? 'OK' : 'MISSING', $table));
            $failed = $failed || ! $ok;
        }

        if (Schema::hasTable('report_daily_channel_summaries')) {
            $requiredChannelColumns = [
                'outlet_id',
                'business_date',
                'business_timezone',
                'display_channel',
                'trx_count',
                'marked_trx_count',
                'gross_sales',
                'marked_gross_sales',
                'created_at',
                'updated_at',
            ];

            foreach ($requiredChannelColumns as $column) {
                $ok = Schema::hasColumn('report_daily_channel_summaries', $column);
                $this->line(sprintf('[%s] report_daily_channel_summaries.%s', $ok ? 'OK' : 'MISSING', $column));
                $failed = $failed || ! $ok;
            }
        }

        if (Schema::hasTable('outlets') && Schema::hasTable('report_daily_summary_coverage')) {
            $outletQuery = DB::table('outlets')
                ->whereRaw("LOWER(COALESCE(type, 'outlet')) = 'outlet'");

            if (Schema::hasColumn('outlets', 'is_active')) {
                $outletQuery->where('is_active', true);
            }

            $outletIds = $outletQuery
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->filter()
                ->values()
                ->all();

            if ($outletIds === []) {
                $this->warn('No active outlet found; rolling coverage could not be verified.');
            } else {
                $coverage = DB::table('report_daily_summary_coverage')
                    ->whereIn('outlet_id', $outletIds)
                    ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                    ->selectRaw('business_date, COUNT(DISTINCT outlet_id) as outlet_count')
                    ->groupBy('business_date')
                    ->pluck('outlet_count', 'business_date');

                $completeDates = 0;
                for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addDay()) {
                    if ((int) ($coverage[$cursor->toDateString()] ?? 0) >= count($outletIds)) {
                        $completeDates++;
                    }
                }

                $this->line(sprintf(
                    'Rolling summary coverage: %d/%d dates complete for %d active outlets.',
                    $completeDates,
                    $days,
                    count($outletIds),
                ));

                if ($completeDates < $days) {
                    $this->warn('Coverage is not fully warmed yet. Run: php artisan report-daily-summaries:warm-common --days='.$days.' --outlet-chunk=6 --date-chunk=14');
                }
            }
        }

        if (Schema::hasTable('report_daily_summary_refresh_queue')) {
            $queue = DB::table('report_daily_summary_refresh_queue')
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count")
                ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
                ->first();

            $this->line(sprintf(
                'Refresh queue: pending=%d processing=%d failed=%d',
                (int) ($queue->pending_count ?? 0),
                (int) ($queue->processing_count ?? 0),
                (int) ($queue->failed_count ?? 0),
            ));
        }

        if ($failed) {
            $this->error('ERP Finance V8 I01 schema gate failed. Ensure all previous migrations are applied.');
            return self::FAILURE;
        }

        $this->info('ERP Finance V8 I01 static/schema gate passed.');
        return self::SUCCESS;
    }
}
