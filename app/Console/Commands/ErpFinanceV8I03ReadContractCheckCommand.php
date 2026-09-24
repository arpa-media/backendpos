<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryService;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpFinanceV8I03ReadContractCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i03-read-contract-check
        {--days=370 : Rolling materialized-read window to inspect (max 400)}
        {--outlet= : Optional outlet id. Defaults to first active outlet}';

    protected $description = 'Read-only V8 I03 gate for Owner/Finance Overview and summary-family 1-year materialized read contract.';

    public function __construct(private readonly ReportDailySummaryService $dailySummaryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, min(400, (int) $this->option('days')));
        $timezone = TransactionDate::appTimezone();
        $to = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);
        $from = $to->subDays($days - 1);
        $failed = false;

        $this->info(sprintf(
            'ERP Finance V8 I03 read-contract check %s .. %s (%d days)',
            $from->toDateString(),
            $to->toDateString(),
            $days,
        ));

        $requiredTables = [
            'report_daily_summary_coverage',
            'report_daily_sales_summaries',
            'report_daily_payment_summaries',
            'report_daily_channel_summaries',
            'report_daily_category_summaries',
            'report_daily_product_summaries',
            'report_daily_variant_summaries',
            'report_sale_business_dates',
        ];

        foreach ($requiredTables as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('[%s] table %s', $ok ? 'OK' : 'MISSING', $table));
            $failed = $failed || ! $ok;
        }

        $requiredIndexes = [
            ['report_daily_summary_coverage', 'rds_cov_outlet_date_synced_idx'],
            ['report_daily_sales_summaries', 'report_daily_sales_summaries_date_outlet_idx'],
            ['report_daily_payment_summaries', 'rdps_outlet_date_method_idx'],
            ['report_daily_channel_summaries', 'rdcs_outlet_date_channel_idx'],
            ['report_daily_category_summaries', 'rdcat_outlet_date_name_kind_idx'],
            ['report_daily_product_summaries', 'rdprod_outlet_date_name_idx'],
            ['report_daily_variant_summaries', 'rdvar_outlet_date_names_idx'],
            ['report_sale_business_dates', 'rsbd_outlet_date_sale_idx'],
        ];

        foreach ($requiredIndexes as [$table, $index]) {
            $ok = Schema::hasTable($table) && $this->indexExists($table, $index);
            $this->line(sprintf('[%s] index %s.%s', $ok ? 'OK' : 'MISSING', $table, $index));
            $failed = $failed || ! $ok;
        }

        $failed = $this->checkHttpReadPaths() || $failed;

        $outletId = trim((string) $this->option('outlet'));
        if ($outletId === '' && Schema::hasTable('outlets')) {
            $query = DB::table('outlets')->whereRaw("LOWER(COALESCE(type, 'outlet')) = 'outlet'");
            if (Schema::hasColumn('outlets', 'is_active')) {
                $query->where('is_active', true);
            }
            $outletId = (string) ($query->orderBy('id')->value('id') ?? '');
        }

        if ($outletId === '') {
            $this->warn('No outlet found. Coverage and EXPLAIN probes skipped.');
        } else {
            $outletTimezone = (string) (DB::table('outlets')->where('id', $outletId)->value('timezone') ?: $timezone);
            $status = $this->dailySummaryService->readContractStatus(
                [$outletId],
                $from->toDateString(),
                $to->toDateString(),
                $outletTimezone
            );

            $this->newLine();
            $this->line('Read contract: '.json_encode($status, JSON_UNESCAPED_SLASHES));
            if (! ($status['ready'] ?? false)) {
                $this->warn('Coverage is not complete. Run the I01 370-day warm command before performance acceptance testing.');
            }

            $this->explain('Sales summary 1Y', 'report_daily_sales_summaries', 'grand_sales', $outletId, $from->toDateString(), $to->toDateString());
            $this->explain('Payment summary 1Y', 'report_daily_payment_summaries', 'gross_sales', $outletId, $from->toDateString(), $to->toDateString());
            $this->explain('Category summary 1Y', 'report_daily_category_summaries', 'gross_sales', $outletId, $from->toDateString(), $to->toDateString());
            $this->explain('Variant summary 1Y', 'report_daily_variant_summaries', 'gross_sales', $outletId, $from->toDateString(), $to->toDateString());
        }

        $this->newLine();
        $this->line('I03 rule: aggregate HTTP endpoints are read-only consumers of report_daily_* materialization.');
        $this->line('I03 rule: missing coverage returns REPORT_DAILY_SUMMARY_NOT_READY; HTTP must not call ensureCoverage().');

        if ($failed) {
            $this->error('ERP Finance V8 I03 gate failed. Apply previous migrations/patches first and verify the listed hot paths.');
            return self::FAILURE;
        }

        $this->info('ERP Finance V8 I03 schema/read-path gate passed.');
        return self::SUCCESS;
    }

    private function checkHttpReadPaths(): bool
    {
        $targets = [
            app_path('Http/Controllers/Api/V1/Finance/FinanceOverviewController.php'),
            app_path('Http/Controllers/Api/V1/Finance/SalesSummaryController.php'),
            app_path('Http/Controllers/Api/V1/Finance/CategorySummaryController.php'),
            app_path('Http/Controllers/Api/V1/Finance/ItemSummaryController.php'),
            app_path('Services/OwnerOverviewService.php'),
        ];

        $failed = false;
        foreach ($targets as $path) {
            if (! is_file($path)) {
                $this->line('[MISSING] hot path '.basename($path));
                $failed = true;
                continue;
            }

            $contents = (string) file_get_contents($path);
            $ok = ! str_contains($contents, '->ensureCoverage(');
            $this->line(sprintf('[%s] no HTTP ensureCoverage: %s', $ok ? 'OK' : 'FAIL', basename($path)));
            $failed = $failed || ! $ok;
        }

        return $failed;
    }

    private function explain(string $label, string $table, string $measureColumn, string $outletId, string $from, string $to): void
    {
        try {
            $rows = DB::select(
                "EXPLAIN SELECT SUM({$measureColumn}) FROM {$table} WHERE outlet_id = ? AND business_date BETWEEN ? AND ?",
                [$outletId, $from, $to]
            );

            $this->line($label.' EXPLAIN:');
            foreach ($rows as $row) {
                $data = (array) $row;
                $this->line(sprintf(
                    '  table=%s type=%s key=%s rows=%s extra=%s',
                    (string) ($data['table'] ?? '-'),
                    (string) ($data['type'] ?? '-'),
                    (string) ($data['key'] ?? '-'),
                    (string) ($data['rows'] ?? '-'),
                    (string) ($data['Extra'] ?? $data['extra'] ?? '-'),
                ));
            }
        } catch (Throwable $e) {
            $this->warn($label.' EXPLAIN skipped: '.$e->getMessage());
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }
}
