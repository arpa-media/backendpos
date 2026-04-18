<?php

namespace App\Services;

use App\Support\FinanceCategorySegment;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportDailySummaryService
{
    private const HOT_REFRESH_MINUTES = 5;
    private const REBUILD_BATCH_DAYS = 3;
    private const DEFAULT_OUTLET_CHUNK_SIZE = 5;
    private const COVERAGE_READY_TTL_SECONDS = 300;
    private const HOT_COVERAGE_READY_TTL_SECONDS = 45;
    private const COVERAGE_LOCK_SECONDS = 120;

    public function __construct(
        private readonly ReportSaleBusinessDateIndexService $businessDateIndex,
    ) {
    }

    public function ensureCoverage(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null, array $options = []): void
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return;
        }

        $fallbackTimezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $fallbackTimezone);
        $outletChunkSize = $this->normalizeOutletChunkSize($options['outlet_chunk'] ?? null);
        $dateChunkDays = $this->normalizeDateChunkDays($options['date_chunk_days'] ?? $options['date_chunk'] ?? null);

        $cacheKey = $this->coverageReadyCacheKey($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays);
        if (Cache::has($cacheKey)) {
            return;
        }

        $lockKey = $this->coverageReadyLockKey($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays);
        $this->withCoverageLock($lockKey, function () use ($cacheKey, $normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays) {
            if (Cache::has($cacheKey)) {
                return;
            }

            $this->businessDateIndex->ensureCoverage($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, [
                'outlet_chunk' => $outletChunkSize,
                'date_chunk_days' => $dateChunkDays,
            ]);

            $rows = DB::table('report_daily_summary_coverage')
                ->selectRaw('business_date, COUNT(DISTINCT outlet_id) as outlet_count, MAX(synced_at) as last_synced_at')
                ->whereIn('outlet_id', $normalizedOutletIds)
                ->whereBetween('business_date', [$fromDate, $toDate])
                ->groupBy('business_date')
                ->get()
                ->keyBy(fn ($row) => (string) ($row->business_date ?? ''));

            $requiredOutletCount = count($normalizedOutletIds);
            $refreshThreshold = now()->subMinutes(self::HOT_REFRESH_MINUTES);
            $hotDates = [$toDate];
            if ($fromDate < $toDate) {
                $hotDates[] = CarbonImmutable::parse($toDate, $fallbackTimezone)->subDay()->toDateString();
            }
            $hotDates = array_values(array_unique(array_filter($hotDates)));

            $datesNeedingRefresh = [];
            for ($cursor = CarbonImmutable::parse($fromDate, $fallbackTimezone); $cursor->lessThanOrEqualTo(CarbonImmutable::parse($toDate, $fallbackTimezone)); $cursor = $cursor->addDay()) {
                $businessDate = $cursor->toDateString();
                $row = $rows->get($businessDate);

                if (! $row || (int) ($row->outlet_count ?? 0) < $requiredOutletCount) {
                    $datesNeedingRefresh[] = $businessDate;
                    continue;
                }

                if (! in_array($businessDate, $hotDates, true)) {
                    continue;
                }

                try {
                    $lastSyncedAt = CarbonImmutable::parse((string) ($row->last_synced_at ?? ''), config('app.timezone', 'Asia/Jakarta'));
                } catch (\Throwable $e) {
                    $datesNeedingRefresh[] = $businessDate;
                    continue;
                }

                if ($lastSyncedAt->lessThan($refreshThreshold)) {
                    $datesNeedingRefresh[] = $businessDate;
                }
            }

            if ($datesNeedingRefresh !== []) {
                foreach ($this->batchedWindows($datesNeedingRefresh, $fallbackTimezone, $dateChunkDays) as [$windowFrom, $windowTo]) {
                    foreach (array_chunk($normalizedOutletIds, $outletChunkSize) as $outletChunk) {
                        $this->rebuildWindow($outletChunk, $windowFrom, $windowTo);
                    }
                }
            }

            $this->rememberCoverageReady($cacheKey, $fromDate, $toDate, $fallbackTimezone);
        });
    }


    public function refreshExactCoverage(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null, array $options = []): void
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return;
        }

        $fallbackTimezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $fallbackTimezone);
        $outletChunkSize = $this->normalizeOutletChunkSize($options['outlet_chunk'] ?? null);
        $dateChunkDays = $this->normalizeDateChunkDays($options['date_chunk_days'] ?? $options['date_chunk'] ?? null);

        $this->businessDateIndex->refreshExactCoverage($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, [
            'outlet_chunk' => $outletChunkSize,
            'date_chunk_days' => $dateChunkDays,
        ]);

        $allDates = [];
        for ($cursor = CarbonImmutable::parse($fromDate, $fallbackTimezone); $cursor->lessThanOrEqualTo(CarbonImmutable::parse($toDate, $fallbackTimezone)); $cursor = $cursor->addDay()) {
            $allDates[] = $cursor->toDateString();
        }

        foreach ($this->batchedWindows($allDates, $fallbackTimezone, $dateChunkDays) as [$windowFrom, $windowTo]) {
            foreach (array_chunk($normalizedOutletIds, $outletChunkSize) as $outletChunk) {
                $this->rebuildWindow($outletChunk, $windowFrom, $windowTo);
            }
        }

        $this->rememberCoverageReady(
            $this->coverageReadyCacheKey($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays),
            $fromDate,
            $toDate,
            $fallbackTimezone
        );
    }

    public function salesSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        return DB::table('report_daily_sales_summaries as rdss')
            ->whereIn('rdss.outlet_id', $this->normalizeOutletIds($outletIds))
            ->whereBetween('rdss.business_date', [$fromDate, $toDate]);
    }

    public function paymentSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        return DB::table('report_daily_payment_summaries as rdps')
            ->whereIn('rdps.outlet_id', $this->normalizeOutletIds($outletIds))
            ->whereBetween('rdps.business_date', [$fromDate, $toDate]);
    }

    public function channelSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        return DB::table('report_daily_channel_summaries as rdcs')
            ->whereIn('rdcs.outlet_id', $this->normalizeOutletIds($outletIds))
            ->whereBetween('rdcs.business_date', [$fromDate, $toDate]);
    }

    public function categorySummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        $query = DB::table('report_daily_category_summaries as rdcat')
            ->whereIn('rdcat.outlet_id', $this->normalizeOutletIds($outletIds))
            ->whereBetween('rdcat.business_date', [$fromDate, $toDate]);

        FinanceCategorySegment::apply($query, 'rdcat.category_name', $categorySegment);

        return $query;
    }

    public function productSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        $query = DB::table('report_daily_product_summaries as rdprod')
            ->whereIn('rdprod.outlet_id', $this->normalizeOutletIds($outletIds))
            ->whereBetween('rdprod.business_date', [$fromDate, $toDate]);

        FinanceCategorySegment::apply($query, 'rdprod.category_name', $categorySegment);

        return $query;
    }

    public function variantSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        $query = DB::table('report_daily_variant_summaries as rdvar')
            ->whereIn('rdvar.outlet_id', $this->normalizeOutletIds($outletIds))
            ->whereBetween('rdvar.business_date', [$fromDate, $toDate]);

        FinanceCategorySegment::apply($query, 'rdvar.category_name', $categorySegment);

        return $query;
    }

    private function rebuildWindow(array $outletIds, string $fromDate, string $toDate): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($outletIds, $fromDate, $toDate, $timestamp) {
            foreach ([
                'report_daily_sales_summaries',
                'report_daily_payment_summaries',
                'report_daily_channel_summaries',
                'report_daily_category_summaries',
                'report_daily_product_summaries',
                'report_daily_variant_summaries',
            ] as $table) {
                DB::table($table)
                    ->whereIn('outlet_id', $outletIds)
                    ->whereBetween('business_date', [$fromDate, $toDate])
                    ->delete();
            }

            DB::table('report_daily_summary_coverage')
                ->whereIn('outlet_id', $outletIds)
                ->whereBetween('business_date', [$fromDate, $toDate])
                ->delete();

            $this->insertSalesDailySummary($outletIds, $fromDate, $toDate, $timestamp);
            $this->insertPaymentDailySummary($outletIds, $fromDate, $toDate, $timestamp);
            $this->insertChannelDailySummary($outletIds, $fromDate, $toDate, $timestamp);
            $this->insertCategoryDailySummary($outletIds, $fromDate, $toDate, $timestamp);
            $this->insertProductDailySummary($outletIds, $fromDate, $toDate, $timestamp);
            $this->insertVariantDailySummary($outletIds, $fromDate, $toDate, $timestamp);
            $this->insertCoverageRows($outletIds, $fromDate, $toDate, $timestamp);
        }, 3);
    }

    private function insertSalesDailySummary(array $outletIds, string $fromDate, string $toDate, string $timestamp): void
    {
        $scopeSales = $this->scopeSalesSubquery($outletIds, $fromDate, $toDate);
        $itemsPerSale = DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('si.voided_at')
            ->groupBy('scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_qty_sold');

        $source = DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($itemsPerSale, 'items_per_sale', fn ($join) => $join->on('items_per_sale.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone')
            ->selectRaw('scope_sales.outlet_id')
            ->selectRaw('scope_sales.business_date')
            ->selectRaw('scope_sales.business_timezone')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.subtotal, 0)), 0) as subtotal_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.subtotal, 0) ELSE 0 END), 0) as marked_subtotal_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as grand_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.grand_total, 0) ELSE 0 END), 0) as marked_grand_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.discount_total, 0)), 0) as discount_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.discount_total, 0) ELSE 0 END), 0) as marked_discount_total')
            ->selectRaw('COALESCE(SUM(COALESCE(s.tax_total, 0)), 0) as tax_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.tax_total, 0) ELSE 0 END), 0) as marked_tax_total')
            ->selectRaw('COALESCE(SUM(COALESCE(s.service_charge_total, 0)), 0) as service_charge_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.service_charge_total, 0) ELSE 0 END), 0) as marked_service_charge_total')
            ->selectRaw('COALESCE(SUM(COALESCE(s.rounding_total, 0)), 0) as rounding_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.rounding_total, 0) ELSE 0 END), 0) as marked_rounding_total')
            ->selectRaw('COALESCE(SUM(COALESCE(items_per_sale.item_qty_sold, 0)), 0) as item_qty_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(items_per_sale.item_qty_sold, 0) ELSE 0 END), 0) as marked_item_qty_sold')
            ->selectRaw('? as created_at', [$timestamp])
            ->selectRaw('? as updated_at', [$timestamp]);

        DB::table('report_daily_sales_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone',
            'trx_count', 'marked_trx_count',
            'subtotal_sales', 'marked_subtotal_sales',
            'grand_sales', 'marked_grand_sales',
            'discount_total', 'marked_discount_total',
            'tax_total', 'marked_tax_total',
            'service_charge_total', 'marked_service_charge_total',
            'rounding_total', 'marked_rounding_total',
            'item_qty_sold', 'marked_item_qty_sold',
            'created_at', 'updated_at',
        ], $source);
    }

    private function insertPaymentDailySummary(array $outletIds, string $fromDate, string $toDate, string $timestamp): void
    {
        $scopeSales = $this->scopeSalesSubquery($outletIds, $fromDate, $toDate);

        $normalizedPaymentRows = DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->join('sale_payments as sp', 'sp.sale_id', '=', 'scope_sales.sale_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->selectRaw('scope_sales.outlet_id')
            ->selectRaw('scope_sales.business_date')
            ->selectRaw('scope_sales.business_timezone')
            ->selectRaw("COALESCE(NULLIF(TRIM(pm.name), ''), NULLIF(TRIM(s.payment_method_name), ''), '-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_type), ''), '') as payment_method_type")
            ->selectRaw('1 as trx_count')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END as marked_trx_count')
            ->selectRaw("CASE WHEN LOWER(TRIM(COALESCE(pm.name, ''))) IN ('cash', 'tunai') AND COALESCE(sp.amount, 0) > 0 THEN GREATEST(COALESCE(sp.amount, 0) - COALESCE(s.change_total, 0), 0) ELSE COALESCE(sp.amount, 0) END as gross_sales")
            ->selectRaw("CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN (CASE WHEN LOWER(TRIM(COALESCE(pm.name, ''))) IN ('cash', 'tunai') AND COALESCE(sp.amount, 0) > 0 THEN GREATEST(COALESCE(sp.amount, 0) - COALESCE(s.change_total, 0), 0) ELSE COALESCE(sp.amount, 0) END) ELSE 0 END as marked_gross_sales");

        $fallbackRows = DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->whereNotExists(function ($exists) {
                $exists->selectRaw('1')
                    ->from('sale_payments as sp_check')
                    ->whereColumn('sp_check.sale_id', 'scope_sales.sale_id');
            })
            ->selectRaw('scope_sales.outlet_id')
            ->selectRaw('scope_sales.business_date')
            ->selectRaw('scope_sales.business_timezone')
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_name), ''), '-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_type), ''), '') as payment_method_type")
            ->selectRaw('1 as trx_count')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END as marked_trx_count')
            ->selectRaw('COALESCE(s.grand_total, 0) as gross_sales')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.grand_total, 0) ELSE 0 END as marked_gross_sales');

        $source = DB::query()
            ->fromSub($normalizedPaymentRows->unionAll($fallbackRows), 'payment_rows')
            ->groupBy('outlet_id', 'business_date', 'business_timezone', 'payment_method_name', 'payment_method_type')
            ->selectRaw('outlet_id')
            ->selectRaw('business_date')
            ->selectRaw('business_timezone')
            ->selectRaw('payment_method_name')
            ->selectRaw('payment_method_type')
            ->selectRaw('COALESCE(SUM(trx_count), 0) as trx_count')
            ->selectRaw('COALESCE(SUM(marked_trx_count), 0) as marked_trx_count')
            ->selectRaw('COALESCE(SUM(gross_sales), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(marked_gross_sales), 0) as marked_gross_sales')
            ->selectRaw('? as created_at', [$timestamp])
            ->selectRaw('? as updated_at', [$timestamp]);

        DB::table('report_daily_payment_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone', 'payment_method_name', 'payment_method_type',
            'trx_count', 'marked_trx_count', 'gross_sales', 'marked_gross_sales',
            'created_at', 'updated_at',
        ], $source);
    }

    private function insertChannelDailySummary(array $outletIds, string $fromDate, string $toDate, string $timestamp): void
    {
        $scopeSales = $this->scopeSalesSubquery($outletIds, $fromDate, $toDate);
        $channelMap = $this->channelMapSubquery($scopeSales);

        $source = DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($channelMap, 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'channel_map.display_channel')
            ->selectRaw('scope_sales.outlet_id')
            ->selectRaw('scope_sales.business_date')
            ->selectRaw('scope_sales.business_timezone')
            ->selectRaw("COALESCE(channel_map.display_channel, '') as display_channel")
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.grand_total, 0) ELSE 0 END), 0) as marked_gross_sales')
            ->selectRaw('? as created_at', [$timestamp])
            ->selectRaw('? as updated_at', [$timestamp]);

        DB::table('report_daily_channel_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone', 'display_channel',
            'trx_count', 'marked_trx_count', 'gross_sales', 'marked_gross_sales',
            'created_at', 'updated_at',
        ], $source);
    }

    private function insertCategoryDailySummary(array $outletIds, string $fromDate, string $toDate, string $timestamp): void
    {
        $source = $this->buildItemDimensionSource($outletIds, $fromDate, $toDate, $timestamp, 'category');

        DB::table('report_daily_category_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone', 'category_id', 'category_name', 'category_kind',
            'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales', 'discount_basis', 'marked_discount_basis',
            'created_at', 'updated_at',
        ], $source);
    }

    private function insertProductDailySummary(array $outletIds, string $fromDate, string $toDate, string $timestamp): void
    {
        $source = $this->buildItemDimensionSource($outletIds, $fromDate, $toDate, $timestamp, 'product');

        DB::table('report_daily_product_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone', 'product_id', 'product_name', 'category_id', 'category_name', 'category_kind',
            'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales', 'discount_basis', 'marked_discount_basis',
            'created_at', 'updated_at',
        ], $source);
    }

    private function insertVariantDailySummary(array $outletIds, string $fromDate, string $toDate, string $timestamp): void
    {
        $source = $this->buildItemDimensionSource($outletIds, $fromDate, $toDate, $timestamp, 'variant');

        DB::table('report_daily_variant_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone', 'product_id', 'variant_id', 'product_name', 'variant_name', 'category_id', 'category_name', 'category_kind',
            'line_count', 'marked_line_count', 'unit_price_sum', 'marked_unit_price_sum',
            'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales', 'discount_basis', 'marked_discount_basis',
            'created_at', 'updated_at',
        ], $source);
    }

    private function buildItemDimensionSource(array $outletIds, string $fromDate, string $toDate, string $timestamp, string $dimension): Builder
    {
        $scopeSales = $this->scopeSalesSubquery($outletIds, $fromDate, $toDate);
        $saleTotals = DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sale_items as tsi', 'tsi.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('tsi.voided_at')
            ->groupBy('scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw('COALESCE(SUM(tsi.line_total), 0) as items_gross_sales');

        $query = DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoinSub($saleTotals, 'sale_totals', fn ($join) => $join->on('sale_totals.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->whereNull('si.voided_at');

        $metricSelects = [
            "COALESCE(p.category_id, '') as category_id",
            "COALESCE(NULLIF(c.name, ''), 'Uncategorized') as category_name",
            "MAX(COALESCE(NULLIF(si.category_kind_snapshot, ''), '')) as category_kind",
            'COALESCE(SUM(si.qty), 0) as item_sold',
            'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN si.qty ELSE 0 END), 0) as marked_item_sold',
            'COALESCE(SUM(si.line_total), 0) as gross_sales',
            'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN si.line_total ELSE 0 END), 0) as marked_gross_sales',
            'COALESCE(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0) as discount_basis',
            'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0) as marked_discount_basis',
            '? as created_at',
            '? as updated_at',
        ];

        if ($dimension === 'category') {
            return $query
                ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'p.category_id', 'c.name')
                ->selectRaw(implode(', ', array_merge([
                    'scope_sales.outlet_id',
                    'scope_sales.business_date',
                    'scope_sales.business_timezone',
                ], $metricSelects)), [$timestamp, $timestamp]);
        }

        if ($dimension === 'product') {
            return $query
                ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'si.product_id', 'si.product_name', 'p.category_id', 'c.name')
                ->selectRaw(implode(', ', array_merge([
                    'scope_sales.outlet_id',
                    'scope_sales.business_date',
                    'scope_sales.business_timezone',
                    "COALESCE(si.product_id, '') as product_id",
                    "COALESCE(NULLIF(si.product_name, ''), '-') as product_name",
                ], $metricSelects)), [$timestamp, $timestamp]);
        }

        return $query
            ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'si.product_id', 'si.variant_id', 'si.product_name', 'si.variant_name', 'p.category_id', 'c.name')
            ->selectRaw(implode(', ', [
                'scope_sales.outlet_id',
                'scope_sales.business_date',
                'scope_sales.business_timezone',
                "COALESCE(si.product_id, '') as product_id",
                "COALESCE(si.variant_id, '') as variant_id",
                "COALESCE(NULLIF(si.product_name, ''), '-') as product_name",
                "COALESCE(NULLIF(si.variant_name, ''), '') as variant_name",
                "COALESCE(p.category_id, '') as category_id",
                "COALESCE(NULLIF(c.name, ''), 'Uncategorized') as category_name",
                "MAX(COALESCE(NULLIF(si.category_kind_snapshot, ''), '')) as category_kind",
                'COUNT(*) as line_count',
                'SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END) as marked_line_count',
                'COALESCE(SUM(si.unit_price), 0) as unit_price_sum',
                'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN si.unit_price ELSE 0 END), 0) as marked_unit_price_sum',
                'COALESCE(SUM(si.qty), 0) as item_sold',
                'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN si.qty ELSE 0 END), 0) as marked_item_sold',
                'COALESCE(SUM(si.line_total), 0) as gross_sales',
                'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN si.line_total ELSE 0 END), 0) as marked_gross_sales',
                'COALESCE(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0) as discount_basis',
                'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0) as marked_discount_basis',
                '? as created_at',
                '? as updated_at',
            ]), [$timestamp, $timestamp]);
    }

    private function insertCoverageRows(array $outletIds, string $fromDate, string $toDate, string $timestamp): void
    {
        $rows = [];
        $cursor = CarbonImmutable::parse($fromDate, TransactionDate::appTimezone());
        $end = CarbonImmutable::parse($toDate, TransactionDate::appTimezone());

        for (; $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addDay()) {
            $businessDate = $cursor->toDateString();
            foreach ($outletIds as $outletId) {
                $rows[] = [
                    'outlet_id' => (string) $outletId,
                    'business_date' => $businessDate,
                    'synced_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('report_daily_summary_coverage')->insertOrIgnore($chunk);
        }
    }

    private function scopeSalesSubquery(array $outletIds, string $fromDate, string $toDate): Builder
    {
        return DB::table('report_sale_business_dates as rsbd')
            ->selectRaw('rsbd.sale_id')
            ->selectRaw('rsbd.outlet_id')
            ->selectRaw('rsbd.business_date')
            ->selectRaw('rsbd.business_timezone')
            ->selectRaw('COALESCE(CAST(rsbd.marking AS SIGNED), 0) as marking')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->whereBetween('rsbd.business_date', [$fromDate, $toDate]);
    }

    private function channelMapSubquery(Builder $scopeSales): Builder
    {
        return DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sales as s1', 's1.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($this->saleItemChannelsSubquery($scopeSales), 'item_channels', fn ($join) => $join->on('item_channels.sale_id', '=', 'scope_sales.sale_id'))
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw("COALESCE(NULLIF(CASE
                WHEN UPPER(COALESCE(s1.channel, '')) = 'DELIVERY' AND NULLIF(TRIM(COALESCE(s1.online_order_source, '')), '') IS NOT NULL THEN LOWER(TRIM(s1.online_order_source))
                WHEN UPPER(COALESCE(s1.channel, '')) = 'MIXED' AND NULLIF(TRIM(COALESCE(item_channels.channel_display, '')), '') IS NOT NULL THEN item_channels.channel_display
                ELSE UPPER(COALESCE(s1.channel, ''))
            END, ''), '') as display_channel");
    }

    private function saleItemChannelsSubquery(Builder $scopeSales): Builder
    {
        return DB::query()
            ->fromSub($scopeSales, 'scope_sales')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT si.channel ORDER BY FIELD(si.channel, 'DINE_IN', 'TAKEAWAY', 'DELIVERY'), si.channel SEPARATOR ' + ') as channel_display")
            ->whereNull('si.voided_at')
            ->groupBy('scope_sales.sale_id');
    }


    private function coverageReadyCacheKey(array $outletIds, string $fromDate, string $toDate, string $timezone, int $outletChunkSize, int $dateChunkDays): string
    {
        return 'report-daily-summary:coverage-ready:' . sha1(json_encode([
            'outlets' => array_values($outletIds),
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'timezone' => $timezone,
            'outlet_chunk' => $outletChunkSize,
            'date_chunk' => $dateChunkDays,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function coverageReadyLockKey(array $outletIds, string $fromDate, string $toDate, string $timezone, int $outletChunkSize, int $dateChunkDays): string
    {
        return 'report-daily-summary:coverage-lock:' . sha1(json_encode([
            'outlets' => array_values($outletIds),
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'timezone' => $timezone,
            'outlet_chunk' => $outletChunkSize,
            'date_chunk' => $dateChunkDays,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function rememberCoverageReady(string $cacheKey, string $fromDate, string $toDate, string $timezone): void
    {
        Cache::put(
            $cacheKey,
            now()->format('Y-m-d H:i:s'),
            now()->addSeconds($this->coverageReadyTtlSeconds($fromDate, $toDate, $timezone))
        );
    }

    private function coverageReadyTtlSeconds(string $fromDate, string $toDate, string $timezone): int
    {
        $today = TransactionDate::businessTodayDateString($timezone);
        $yesterday = CarbonImmutable::parse($today, $timezone)->subDay()->toDateString();

        if ($fromDate <= $today && $toDate >= $yesterday) {
            return self::HOT_COVERAGE_READY_TTL_SECONDS;
        }

        return self::COVERAGE_READY_TTL_SECONDS;
    }

    private function withCoverageLock(string $lockKey, callable $callback): void
    {
        $store = Cache::getStore();
        if (! method_exists($store, 'lock')) {
            $callback();
            return;
        }

        try {
            Cache::lock($lockKey, self::COVERAGE_LOCK_SECONDS)->block(8, $callback);
        } catch (\Throwable $e) {
            $callback();
        }
    }

    private function normalizeOutletIds(array $outletIds): array
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        return $normalizedOutletIds;
    }

    private function normalizeOutletChunkSize(mixed $value): int
    {
        $size = (int) $value;

        return max(1, min(50, $size > 0 ? $size : self::DEFAULT_OUTLET_CHUNK_SIZE));
    }

    private function normalizeDateChunkDays(mixed $value): int
    {
        $days = (int) $value;

        return max(1, min(31, $days > 0 ? $days : self::REBUILD_BATCH_DAYS));
    }

    private function normalizeDateRange(?string $dateFrom, ?string $dateTo, ?string $fallbackTimezone = null): array
    {
        $tz = TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
        $today = CarbonImmutable::parse(TransactionDate::businessTodayDateString($tz), $tz)->toDateString();

        try {
            $from = $dateFrom ? CarbonImmutable::parse($dateFrom, $tz)->toDateString() : $today;
        } catch (\Throwable $e) {
            $from = $today;
        }

        try {
            $to = $dateTo ? CarbonImmutable::parse($dateTo, $tz)->toDateString() : $today;
        } catch (\Throwable $e) {
            $to = $today;
        }

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function batchedWindows(array $datesNeedingRefresh, string $timezone, ?int $maxDays = null): array
    {
        $maxWindowDays = $this->normalizeDateChunkDays($maxDays);
        $datesNeedingRefresh = array_values(array_unique(array_filter($datesNeedingRefresh)));
        sort($datesNeedingRefresh);

        $windows = [];
        $windowStart = null;
        $previous = null;
        $daysInWindow = 0;

        foreach ($datesNeedingRefresh as $date) {
            $current = CarbonImmutable::parse($date, $timezone);
            if ($windowStart === null) {
                $windowStart = $date;
                $previous = $current;
                $daysInWindow = 1;
                continue;
            }

            $isContiguous = $previous && $current->equalTo($previous->addDay());
            if (! $isContiguous || $daysInWindow >= $maxWindowDays) {
                $windows[] = [$windowStart, $previous?->toDateString() ?? $windowStart];
                $windowStart = $date;
                $previous = $current;
                $daysInWindow = 1;
                continue;
            }

            $previous = $current;
            $daysInWindow++;
        }

        if ($windowStart !== null) {
            $windows[] = [$windowStart, $previous?->toDateString() ?? $windowStart];
        }

        return $windows;
    }
}
