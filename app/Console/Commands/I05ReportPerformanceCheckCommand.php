<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class I05ReportPerformanceCheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i05-check {--days=30 : Coverage window to inspect (1-90)}';

    protected $description = 'Read-only health check for ERP Finance V7 I05 reporting performance infrastructure.';

    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $timezone = TransactionDate::appTimezone();
        $to = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);
        $from = $to->subDays($days - 1);

        $this->info("I05 reporting health check {$from->toDateString()} .. {$to->toDateString()} ({$timezone})");

        $requiredIndexes = [
            ['sales', 'sales_report_status_deleted_outlet_created_idx'],
            ['sale_items', 'sale_items_sale_voided_idx'],
            ['sale_payments', 'sale_payments_sale_method_idx'],
            ['sale_cancel_requests', 'scr_status_type_sale_outlet_idx'],
            ['report_sale_business_dates', 'rsbd_outlet_date_marking_sale_idx'],
            ['report_daily_payment_summaries', 'rdps_outlet_date_method_idx'],
        ];

        $failed = false;
        foreach ($requiredIndexes as [$table, $index]) {
            $ok = Schema::hasTable($table) && $this->indexExists($table, $index);
            $this->line(sprintf('[%s] %s.%s', $ok ? 'OK' : 'MISSING', $table, $index));
            $failed = $failed || ! $ok;
        }

        if (Schema::hasTable('payment_methods')) {
            $activePayments = DB::table('payment_methods')->whereNull('deleted_at')->where('is_active', true)->count();
            $this->line('Active payment methods: ' . $activePayments);
        }

        if (Schema::hasTable('report_daily_summary_coverage') && Schema::hasTable('outlets')) {
            $outletCount = DB::table('outlets')
                ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
                ->count();

            $coverageByDate = DB::table('report_daily_summary_coverage')
                ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('business_date, COUNT(DISTINCT outlet_id) as outlet_count')
                ->groupBy('business_date')
                ->pluck('outlet_count', 'business_date');

            $missing = [];
            for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addDay()) {
                $date = $cursor->toDateString();
                if ((int) ($coverageByDate[$date] ?? 0) < $outletCount) {
                    $missing[] = $date;
                }
            }

            $this->line(sprintf('Daily summary coverage: %d/%d dates complete for %d outlets', $days - count($missing), $days, $outletCount));
            if ($missing !== []) {
                $this->warn('Coverage needs warm/rebuild for: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' ...' : ''));
            }
        }

        if ($failed) {
            $this->error('One or more required hot-path indexes are missing. Run php artisan migrate.');
            return self::FAILURE;
        }

        $this->info('I05 infrastructure check passed.');
        return self::SUCCESS;
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
}
