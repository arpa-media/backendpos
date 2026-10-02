<?php

namespace App\Services\Operational;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;

class OperationalHourlySalesAnalyticService
{
    public function __construct(
        private readonly ReportHourlySummaryService $hourlySummaryService,
        private readonly OperationalDailyTopItemsByCategoryService $dailyTopItemsService,
    ) {
    }

    public function hourlyComparison(array $outletIds, string $baselineDate, string $timezone): array
    {
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $currentDate = TransactionDate::businessTodayDateString($timezone);
        $currentHour = (int) CarbonImmutable::now($timezone)->format('G');

        $isCurrentBaseline = $baselineDate === $currentDate;
        $materializedStatus = $isCurrentBaseline
            ? [
                'contract' => 'erp_finance_v8_i08_hourly_live',
                'ready' => true,
                'state' => 'live_current_business_date',
                'source' => 'report_sale_business_dates + sales (one business date only)',
                'http_backfill' => false,
            ]
            : $this->hourlySummaryService->readContractStatus($outletIds, $baselineDate);

        $useExactHistoricalFallback = ! $isCurrentBaseline && ! ($materializedStatus['ready'] ?? false);

        if ($useExactHistoricalFallback) {
            $baseline = $this->hourlySummaryService->exactSalesSeries($outletIds, $baselineDate);
            $reportingSource = array_merge($materializedStatus, [
                'ready' => true,
                'materialized_ready' => false,
                'state' => 'served_by_exact_historical_fallback',
                'read_mode' => 'live_historical_fallback',
                'source' => 'sales raw exact one-business-date TransactionDate resolver',
                'fallback_reason' => 'hourly_materialization_not_ready',
                'http_backfill' => false,
            ]);
        } else {
            $baseline = $isCurrentBaseline
                ? $this->hourlySummaryService->liveSalesSeries($outletIds, $baselineDate)
                : $this->hourlySummaryService->historicalSalesSeries($outletIds, $baselineDate);
            $reportingSource = array_merge($materializedStatus, [
                'materialized_ready' => ! $isCurrentBaseline,
                'read_mode' => $isCurrentBaseline ? 'live_current_business_date' : 'materialized',
            ]);
        }

        $live = $this->hourlySummaryService->liveSalesSeries($outletIds, $currentDate);
        $liveOutlets = $this->hourlySummaryService->liveOutletCurrentHour($outletIds, $currentDate, $currentHour);

        $series = [];
        $spikeHours = 0;
        foreach (range(0, 23) as $hour) {
            $baseRow = $baseline[$hour] ?? ['gross_amount_sales' => 0, 'trx_count' => 0];
            $liveRow = $live[$hour] ?? ['gross_amount_sales' => 0, 'trx_count' => 0];
            $baseAmount = (int) ($baseRow['gross_amount_sales'] ?? 0);
            $liveAmount = (int) ($liveRow['gross_amount_sales'] ?? 0);
            $delta = $liveAmount - $baseAmount;
            $spike = $hour <= $currentHour && $liveAmount > $baseAmount && $liveAmount > 0;
            if ($spike) $spikeHours++;

            $series[] = [
                'hour' => $hour,
                'hour_label' => sprintf('%02d:00', $hour),
                'baseline_amount' => $baseAmount,
                'baseline_trx_count' => (int) ($baseRow['trx_count'] ?? 0),
                'live_amount' => $liveAmount,
                'live_trx_count' => (int) ($liveRow['trx_count'] ?? 0),
                'delta_amount' => $delta,
                'delta_percent' => $baseAmount > 0 ? round(($delta / $baseAmount) * 100, 2) : null,
                'spike' => $spike,
                'future_hour' => $hour > $currentHour,
            ];
        }

        $baselineTotal = (int) collect($baseline)->sum('gross_amount_sales');
        $liveToCurrent = (int) collect($live)->filter(fn ($row) => (int) ($row['hour'] ?? 0) <= $currentHour)->sum('gross_amount_sales');
        $baselineToCurrent = (int) collect($baseline)->filter(fn ($row) => (int) ($row['hour'] ?? 0) <= $currentHour)->sum('gross_amount_sales');

        return [
            'ready' => true,
            'report' => [
                'baseline_date' => $baselineDate,
                'current_date' => $currentDate,
                'current_hour' => $currentHour,
                'current_hour_label' => sprintf('%02d:00', $currentHour),
                'report_time' => CarbonImmutable::now($timezone)->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
            ],
            'summary' => [
                'baseline_total_day' => $baselineTotal,
                'baseline_to_current_hour' => $baselineToCurrent,
                'live_to_current_hour' => $liveToCurrent,
                'delta_to_current_hour' => $liveToCurrent - $baselineToCurrent,
                'delta_percent_to_current_hour' => $baselineToCurrent > 0
                    ? round((($liveToCurrent - $baselineToCurrent) / $baselineToCurrent) * 100, 2)
                    : null,
                'spike_hour_count' => $spikeHours,
                'live_outlet_count' => count($liveOutlets),
            ],
            'series' => $series,
            'live_outlets' => $liveOutlets,
            'meta' => [
                'contract' => 'erp_finance_hf04_hourly_comparison_fallback',
                'historical_source' => $useExactHistoricalFallback
                    ? 'raw_exact_one_business_date'
                    : ($isCurrentBaseline ? 'live_one_day_query' : 'report_hourly_sales_summaries'),
                'live_source' => 'report_sale_business_dates + sales, bounded to current business date',
                'reporting_source' => $reportingSource,
                'hourly_materialized' => ! $useExactHistoricalFallback && ! $isCurrentBaseline,
                'fallback_active' => $useExactHistoricalFallback,
                'fallback_scope' => $useExactHistoricalFallback ? 'one_business_date_only' : null,
                'refresh_hint_seconds' => 60,
                'spike_rule' => 'live hourly gross amount sales > baseline hourly gross amount sales',
                'http_backfill' => false,
            ],
        ];
    }

    public function hourlySummary(array $outletIds, string $date, int $hour, string $timezone): array
    {
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $currentDate = TransactionDate::businessTodayDateString($timezone);
        $hour = max(0, min(23, $hour));
        $isLive = $date === $currentDate;

        $materializedStatus = $isLive
            ? [
                'contract' => 'erp_finance_v8_i08_hourly_live',
                'ready' => true,
                'state' => 'live_current_business_date',
                'source' => 'report_sale_business_dates + sales/sale_items (one business date only)',
                'http_backfill' => false,
            ]
            : $this->hourlySummaryService->readContractStatus($outletIds, $date);

        $useExactHistoricalFallback = ! $isLive && ! ($materializedStatus['ready'] ?? false);

        if ($useExactHistoricalFallback) {
            $series = $this->hourlySummaryService->exactSalesSeries($outletIds, $date);
            $categories = $this->dailyTopItemsService->forDate(
                $outletIds,
                $date,
                false,
                10,
                true,
            );
            $status = array_merge($materializedStatus, [
                'ready' => true,
                'materialized_ready' => false,
                'state' => 'served_by_exact_historical_fallback',
                'read_mode' => 'live_historical_fallback',
                'source' => 'sales + sale_items exact one-business-date TransactionDate resolver',
                'fallback_reason' => 'hourly_materialization_not_ready',
                'http_backfill' => false,
            ]);
        } else {
            $series = $isLive
                ? $this->hourlySummaryService->liveSalesSeries($outletIds, $date)
                : $this->hourlySummaryService->historicalSalesSeries($outletIds, $date);

            // Top Items by Category is intentionally whole-day (00:00-23:59).
            $categories = $this->dailyTopItemsService->forDate($outletIds, $date, $isLive, 10);

            $status = array_merge($materializedStatus, [
                'materialized_ready' => ! $isLive,
                'read_mode' => $isLive ? 'live_current_business_date' : 'materialized',
            ]);
        }

        $selected = $series[$hour] ?? ['gross_amount_sales' => 0, 'trx_count' => 0];
        $totalGross = (int) collect($series)->sum('gross_amount_sales');
        $totalTrx = (int) collect($series)->sum('trx_count');

        return [
            'ready' => true,
            'report' => [
                'date' => $date,
                'hour' => $hour,
                'hour_label' => sprintf('%02d:00 - %02d:59', $hour, $hour),
                'top_items_range_start' => '00:00',
                'top_items_range_end' => '23:59',
                'top_items_range_label' => '00:00 - 23:59',
                'report_time' => CarbonImmutable::now($timezone)->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
                'is_live' => $isLive,
            ],
            'summary' => [
                'gross_amount_sales' => $totalGross,
                'trx_count' => $totalTrx,
                'selected_hour_gross_amount_sales' => (int) ($selected['gross_amount_sales'] ?? 0),
                'selected_hour_trx_count' => (int) ($selected['trx_count'] ?? 0),
                'category_count' => count($categories),
            ],
            'hourly_gross' => $series,
            'top_items_by_category' => $categories,
            'meta' => [
                'contract' => 'erp_finance_hf04_hourly_summary_fallback',
                'source' => $useExactHistoricalFallback
                    ? 'raw exact one-business-date sales + sale_items fallback'
                    : ($isLive
                        ? 'live one-business-date canonical query'
                        : 'report_hourly_sales_summaries + report_hourly_product_summaries'),
                'reporting_source' => $status,
                'hourly_materialized' => ! $useExactHistoricalFallback && ! $isLive,
                'fallback_active' => $useExactHistoricalFallback,
                'fallback_scope' => $useExactHistoricalFallback ? 'one_business_date_only' : null,
                'top_item_metric' => 'item_sold',
                'top_items_window' => '00:00-23:59 whole business date',
                'top_items_limit_per_category' => 10,
                'gross_metric' => 'sales.grand_total / hourly materialized gross_amount_sales',
                'http_backfill' => false,
            ],
        ];
    }
}