<?php

namespace App\Console\Commands;

use App\Services\Operational\OperationalDailyTopItemsByCategoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpPosFinalI08ChamberHourlyUxCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i08-chamber-hourly-ux-check';

    protected $description = 'Validate I08 Chamber Operational hourly whole-day category aggregation invariants.';

    public function handle(): int
    {
        $this->info('ERP POS FINAL I08 - Chamber Operational Hourly UX Check');
        $failed = false;

        foreach (['report_hourly_product_summaries', 'report_hourly_sales_summaries', 'report_hourly_summary_coverage', 'report_sale_business_dates'] as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('%-36s : %s', $table, $ok ? 'OK' : 'MISSING'));
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('Required hourly materialization tables are missing.');
            return self::FAILURE;
        }

        try {
            $sample = DB::table('report_hourly_product_summaries')
                ->select(['outlet_id', 'business_date'])
                ->orderByDesc('business_date')
                ->orderBy('outlet_id')
                ->first();

            if (! $sample) {
                $this->line(sprintf('%-36s : %s', 'Historical category sample', 'SKIP (no materialized product rows)'));
            } else {
                $outletId = (string) $sample->outlet_id;
                $businessDate = (string) $sample->business_date;

                /** @var OperationalDailyTopItemsByCategoryService $service */
                $service = app(OperationalDailyTopItemsByCategoryService::class);
                $categories = $service->forDate([$outletId], $businessDate, false, 10);

                $direct = DB::table('report_hourly_product_summaries')
                    ->where('outlet_id', $outletId)
                    ->where('business_date', $businessDate)
                    ->selectRaw('COALESCE(SUM(item_sold), 0) as qty')
                    ->selectRaw('COALESCE(SUM(gross_sales), 0) as gross')
                    ->first();

                $serviceQty = (float) collect($categories)->sum('total_item_sold');
                $serviceGross = (int) collect($categories)->sum('total_gross_sales');
                $directQty = (float) ($direct->qty ?? 0);
                $directGross = (int) round((float) ($direct->gross ?? 0));

                $qtyOk = abs($serviceQty - $directQty) < 0.001;
                $grossOk = $serviceGross === $directGross;
                $windowOk = collect($categories)->every(fn (array $category) =>
                    ($category['range_start'] ?? null) === '00:00'
                    && ($category['range_end'] ?? null) === '23:59'
                    && ($category['range_label'] ?? null) === '00:00 - 23:59'
                );
                $pieOk = collect($categories)->every(function (array $category): bool {
                    $total = (float) ($category['total_item_sold'] ?? 0);
                    $pieTotal = (float) collect($category['pie_items'] ?? [])->sum('item_sold');
                    return abs($total - $pieTotal) < 0.001;
                });

                $this->line(sprintf('%-36s : %s (%s / %s)', 'Whole-day quantity total', $qtyOk ? 'OK' : 'FAIL', $serviceQty, $directQty));
                $this->line(sprintf('%-36s : %s (%s / %s)', 'Whole-day gross total', $grossOk ? 'OK' : 'FAIL', $serviceGross, $directGross));
                $this->line(sprintf('%-36s : %s', 'Fixed 00:00-23:59 window', $windowOk ? 'OK' : 'FAIL'));
                $this->line(sprintf('%-36s : %s', 'Pie quantity reconciliation', $pieOk ? 'OK' : 'FAIL'));

                $failed = $failed || ! $qtyOk || ! $grossOk || ! $windowOk || ! $pieOk;
            }
        } catch (Throwable $e) {
            $failed = true;
            $this->error('Hourly category regression query failed: '.$e->getMessage());
        }

        if ($failed) {
            $this->error('ERP POS FINAL I08 validation FAIL.');
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I08 validation PASS.');
        return self::SUCCESS;
    }
}
