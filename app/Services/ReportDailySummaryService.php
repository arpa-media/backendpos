<?php

namespace App\Services;

use App\Support\FinanceCategorySegment;
use App\Support\TransactionDate;
use App\Services\Reporting\ReportHybridReadPlanner;
use App\Services\Reporting\ReportReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReportDailySummaryService
{
    private const HOT_REFRESH_MINUTES = 5;
    private const REBUILD_BATCH_DAYS = 31;
    private const DEFAULT_OUTLET_CHUNK_SIZE = 25;
    private const COVERAGE_READY_TTL_SECONDS = 300;
    private const HOT_COVERAGE_READY_TTL_SECONDS = 45;
    private const COVERAGE_LOCK_SECONDS = 120;

    /** @var array<string,array> */
    private array $coverageAnomalyCache = [];

    public function __construct(
        private readonly ReportSaleBusinessDateIndexService $businessDateIndex,
        private readonly ReportHybridReadPlanner $hybridReadPlanner,
        private readonly ReportReadinessService $readinessService,
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
        $progressCallback = is_callable($options['progress_callback'] ?? null) ? $options['progress_callback'] : null;

        $cacheKey = $this->coverageReadyCacheKey($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays);
        if (Cache::has($cacheKey)) {
            return;
        }

        $lockKey = $this->coverageReadyLockKey($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays);
        $this->withCoverageLock($lockKey, function () use ($cacheKey, $normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays, $progressCallback) {
            if (Cache::has($cacheKey)) {
                return;
            }

            $this->businessDateIndex->ensureCoverage($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, [
                'outlet_chunk' => $outletChunkSize,
                'date_chunk_days' => $dateChunkDays,
                'progress_callback' => $progressCallback,
            ]);

            $rows = DB::table('report_daily_summary_coverage')
                ->selectRaw('business_date, COUNT(DISTINCT outlet_id) as outlet_count, MIN(synced_at) as first_synced_at, MAX(synced_at) as last_synced_at')
                ->whereIn('outlet_id', $normalizedOutletIds)
                ->whereBetween('business_date', [$fromDate, $toDate])
                ->groupBy('business_date')
                ->get()
                ->keyBy(fn ($row) => (string) ($row->business_date ?? ''));

            $requiredOutletCount = count($normalizedOutletIds);
            $pendingRefreshDates = [];
            if (Schema::hasTable('report_daily_summary_refresh_queue')) {
                $pendingRefreshDates = DB::table('report_daily_summary_refresh_queue')
                    ->whereIn('outlet_id', $normalizedOutletIds)
                    ->whereBetween('business_date', [$fromDate, $toDate])
                    ->whereIn('status', ['pending', 'processing'])
                    ->distinct()
                    ->pluck('business_date')
                    ->mapWithKeys(fn ($date) => [(string) $date => true])
                    ->all();
            }

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

                if ($this->coverageWasPremature($row->first_synced_at ?? null, $businessDate, $fallbackTimezone)) {
                    $datesNeedingRefresh[] = $businessDate;
                    continue;
                }

                if (isset($pendingRefreshDates[$businessDate])) {
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

            $workItems = [];
            if ($datesNeedingRefresh !== []) {
                foreach ($this->batchedWindows($datesNeedingRefresh, $fallbackTimezone, $dateChunkDays) as [$windowFrom, $windowTo]) {
                    foreach (array_chunk($normalizedOutletIds, $outletChunkSize) as $outletChunk) {
                        $workItems[] = [$outletChunk, $windowFrom, $windowTo];
                    }
                }
            }

            $this->emitProgress($progressCallback, [
                'phase' => 'daily_summary',
                'event' => 'plan',
                'total' => count($workItems),
                'dates_needing_refresh' => count($datesNeedingRefresh),
            ]);

            foreach ($workItems as $index => [$outletChunk, $windowFrom, $windowTo]) {
                $payload = [
                    'phase' => 'daily_summary',
                    'current' => $index + 1,
                    'total' => count($workItems),
                    'date_from' => $windowFrom,
                    'date_to' => $windowTo,
                    'timezone' => $fallbackTimezone,
                    'outlet_count' => count($outletChunk),
                ];
                $this->emitProgress($progressCallback, array_merge($payload, ['event' => 'start']));
                $startedAt = microtime(true);
                $this->rebuildWindow($outletChunk, $windowFrom, $windowTo);
                $this->emitProgress($progressCallback, array_merge($payload, [
                    'event' => 'complete',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]));
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
        $progressCallback = is_callable($options['progress_callback'] ?? null) ? $options['progress_callback'] : null;

        $this->businessDateIndex->refreshExactCoverage($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, [
            'outlet_chunk' => $outletChunkSize,
            'date_chunk_days' => $dateChunkDays,
            'progress_callback' => $progressCallback,
        ]);

        $allDates = [];
        for ($cursor = CarbonImmutable::parse($fromDate, $fallbackTimezone); $cursor->lessThanOrEqualTo(CarbonImmutable::parse($toDate, $fallbackTimezone)); $cursor = $cursor->addDay()) {
            $allDates[] = $cursor->toDateString();
        }

        $workItems = [];
        foreach ($this->batchedWindows($allDates, $fallbackTimezone, $dateChunkDays) as [$windowFrom, $windowTo]) {
            foreach (array_chunk($normalizedOutletIds, $outletChunkSize) as $outletChunk) {
                $workItems[] = [$outletChunk, $windowFrom, $windowTo];
            }
        }

        $this->emitProgress($progressCallback, [
            'phase' => 'daily_summary',
            'event' => 'plan',
            'total' => count($workItems),
            'dates_needing_refresh' => count($allDates),
            'forced' => true,
        ]);

        foreach ($workItems as $index => [$outletChunk, $windowFrom, $windowTo]) {
            $payload = [
                'phase' => 'daily_summary',
                'current' => $index + 1,
                'total' => count($workItems),
                'date_from' => $windowFrom,
                'date_to' => $windowTo,
                'timezone' => $fallbackTimezone,
                'outlet_count' => count($outletChunk),
                'forced' => true,
            ];
            $this->emitProgress($progressCallback, array_merge($payload, ['event' => 'start']));
            $startedAt = microtime(true);
            $this->rebuildWindow($outletChunk, $windowFrom, $windowTo);
            $this->emitProgress($progressCallback, array_merge($payload, [
                'event' => 'complete',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]));
        }

        $this->rememberCoverageReady(
            $this->coverageReadyCacheKey($normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, $outletChunkSize, $dateChunkDays),
            $fromDate,
            $toDate,
            $fallbackTimezone
        );
    }

    /**
     * Read-only materialized-report contract used by V8 I03 aggregate endpoints.
     *
     * IMPORTANT: this method never builds/backfills coverage. It only reports whether
     * the requested outlet/date matrix has already been materialized by CLI/scheduler.
     */
    /**
     * Read-only integrity signal for report consumers.
     *
     * Dirty means at least one outlet-date is missing, was pre-seeded before the
     * business date started, or has an outstanding checkout/void refresh request.
     * No backfill is performed here.
     */
    public function coverageAnomalies(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $normalizedOutletIds = $this->normalizeOutletIds($outletIds);
        sort($normalizedOutletIds);

        $fallbackTimezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $fallbackTimezone);
        $cacheKey = sha1(json_encode([$normalizedOutletIds, $fromDate, $toDate, $fallbackTimezone, 'v11-i01']));

        if (isset($this->coverageAnomalyCache[$cacheKey])) {
            return $this->coverageAnomalyCache[$cacheKey];
        }

        $status = $this->readinessService->dailyMatrixStatus(
            $normalizedOutletIds,
            $fromDate,
            $toDate,
            $fallbackTimezone,
        );

        return $this->coverageAnomalyCache[$cacheKey] = [
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'dirty_dates' => array_values($status['dirty_dates'] ?? []),
            'missing_dates' => array_values($status['missing_dates'] ?? []),
            'premature_dates' => array_values($status['premature_dates'] ?? []),
            // Backward-compatible name now means effective pending only. Stale queue
            // markers are exposed separately and no longer make a report falsely dirty.
            'pending_dates' => array_values($status['effective_pending_dates'] ?? []),
            'effective_pending_dates' => array_values($status['effective_pending_dates'] ?? []),
            'stale_refresh_flag_dates' => array_values($status['stale_refresh_flag_dates'] ?? []),
        ];
    }

    private function coverageWasPremature($syncedAt, string $businessDate, string $timezone): bool
    {
        $raw = trim((string) ($syncedAt ?? ''));
        if ($raw === '') {
            return true;
        }

        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());

        try {
            $synced = CarbonImmutable::parse($raw, config('app.timezone', 'UTC'))->setTimezone($timezone);
            $businessStart = CarbonImmutable::parse($businessDate, $timezone)
                ->startOfDay()
                ->addHours(TransactionDate::businessDayStartHour($timezone));

            return $synced->lessThan($businessStart);
        } catch (\Throwable) {
            return true;
        }
    }

    public function readContractStatus(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $normalizedOutletIds = $this->normalizeOutletIds($outletIds);
        sort($normalizedOutletIds);

        $fallbackTimezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $fallbackTimezone);
        $matrix = $this->readinessService->dailyMatrixStatus(
            $normalizedOutletIds,
            $fromDate,
            $toDate,
            $fallbackTimezone,
        );

        $expectedRows = (int) ($matrix['expected_rows'] ?? 0);
        $physicalCoveredRows = (int) ($matrix['physical_covered_rows'] ?? 0);
        $readyRows = (int) ($matrix['ready_rows'] ?? 0);
        $missingRows = max(0, $expectedRows - $readyRows);
        $effectivePending = (int) ($matrix['effective_pending_refresh_rows'] ?? 0);
        $rawPending = (int) ($matrix['raw_pending_refresh_rows'] ?? 0);
        $staleFlags = (int) ($matrix['stale_refresh_flag_rows'] ?? 0);
        $prematureRows = (int) ($matrix['premature_rows'] ?? 0);
        $physicalMissingRows = (int) ($matrix['physical_missing_rows'] ?? 0);
        $ready = (bool) ($matrix['ready'] ?? false);

        $state = 'ready';
        if (! Schema::hasTable('report_daily_summary_coverage')) {
            $state = 'coverage_table_missing';
        } elseif ($physicalMissingRows > 0) {
            $state = 'warming_required_missing_coverage';
        } elseif ($prematureRows > 0) {
            $state = 'warming_required_premature_coverage';
        } elseif ($effectivePending > 0) {
            $state = 'refresh_pending';
        } elseif (! $ready) {
            $state = 'warming_required';
        }

        return [
            'contract' => 'erp_pos_v11_i01_real_readiness',
            'source' => 'report_daily_*_summaries',
            'business_date_source' => 'report_sale_business_dates',
            'http_backfill' => false,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'range_days' => (int) ($matrix['range_days'] ?? 0),
            'outlet_count' => count($normalizedOutletIds),
            'outlet_ids' => $normalizedOutletIds,
            'timezone' => $fallbackTimezone,
            'recovery_pipeline' => 'daily',
            'expected_coverage_rows' => $expectedRows,

            // Compatibility fields now follow REAL readiness so a displayed 100%
            // means the range is actually usable by report consumers.
            'covered_rows' => $readyRows,
            'missing_rows' => $missingRows,
            'coverage_percent' => (float) ($matrix['readiness_percent'] ?? 0.0),

            // Physical coverage remains visible for diagnostics without masquerading
            // as report readiness.
            'physical_covered_rows' => $physicalCoveredRows,
            'physical_missing_rows' => $physicalMissingRows,
            'physical_coverage_percent' => (float) ($matrix['physical_coverage_percent'] ?? 0.0),
            'readiness_rows' => $readyRows,
            'readiness_percent' => (float) ($matrix['readiness_percent'] ?? 0.0),
            'freshness_percent' => (float) ($matrix['freshness_percent'] ?? 0.0),
            'premature_rows' => $prematureRows,

            // Backward-compatible pending_refresh_rows is intentionally EFFECTIVE
            // pending. Raw/stale flag counts are exposed separately for I02 cleanup.
            'pending_refresh_rows' => $effectivePending,
            'effective_pending_refresh_rows' => $effectivePending,
            'raw_pending_refresh_rows' => $rawPending,
            'stale_refresh_flag_rows' => $staleFlags,

            'oldest_synced_at' => $matrix['oldest_synced_at'] ?? null,
            'latest_synced_at' => $matrix['latest_synced_at'] ?? null,
            'dirty_business_dates' => array_values($matrix['dirty_dates'] ?? []),
            'missing_business_dates' => array_values($matrix['missing_dates'] ?? []),
            'premature_coverage_dates' => array_values($matrix['premature_dates'] ?? []),
            'pending_business_dates' => array_values($matrix['effective_pending_dates'] ?? []),
            'stale_refresh_flag_dates' => array_values($matrix['stale_refresh_flag_dates'] ?? []),
            'dirty_outlet_ids' => array_values($matrix['dirty_outlet_ids'] ?? []),
            'ready' => $ready,
            'state' => $state,
        ];
    }

    public function salesSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        return $this->hybridReadPlanner->summaryQuery('sales', $this->normalizeOutletIds($outletIds), $fromDate, $toDate);
    }

    public function paymentSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        return $this->hybridReadPlanner->summaryQuery('payment', $this->normalizeOutletIds($outletIds), $fromDate, $toDate);
    }

    public function channelSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        return $this->hybridReadPlanner->summaryQuery('channel', $this->normalizeOutletIds($outletIds), $fromDate, $toDate);
    }

    public function categorySummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        $query = $this->hybridReadPlanner->summaryQuery('category', $this->normalizeOutletIds($outletIds), $fromDate, $toDate);

        FinanceCategorySegment::apply($query, 'rdcat.category_name', $categorySegment);

        return $query;
    }

    public function productSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        $query = $this->hybridReadPlanner->summaryQuery('product', $this->normalizeOutletIds($outletIds), $fromDate, $toDate);

        FinanceCategorySegment::apply($query, 'rdprod.category_name', $categorySegment);

        return $query;
    }

    public function variantSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null): Builder
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, TransactionDate::appTimezone());

        $query = $this->hybridReadPlanner->summaryQuery('variant', $this->normalizeOutletIds($outletIds), $fromDate, $toDate);

        FinanceCategorySegment::apply($query, 'rdvar.category_name', $categorySegment);

        return $query;
    }

    private function rebuildWindow(array $outletIds, string $fromDate, string $toDate): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $generationUlid = (string) Str::ulid();

        DB::transaction(function () use ($outletIds, $fromDate, $toDate, $timestamp, $generationUlid) {
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
            $this->insertCoverageRows($outletIds, $fromDate, $toDate, $timestamp, $generationUlid);
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
            ->selectRaw('SUM(CASE WHEN COALESCE(s.discount_total, 0) > 0 THEN 1 ELSE 0 END) as discounted_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(s.rounding_total, 0) <> 0 THEN 1 ELSE 0 END) as rounding_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(s.discount_total, 0) > 0 THEN 1 ELSE 0 END) as marked_discounted_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(s.rounding_total, 0) <> 0 THEN 1 ELSE 0 END) as marked_rounding_trx_count')
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
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(s.rounding_total, 0) > 0 THEN COALESCE(s.rounding_total, 0) ELSE 0 END), 0) as rounding_up_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(s.rounding_total, 0) < 0 THEN ABS(COALESCE(s.rounding_total, 0)) ELSE 0 END), 0) as rounding_down_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.rounding_total, 0) ELSE 0 END), 0) as marked_rounding_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(s.rounding_total, 0) > 0 THEN COALESCE(s.rounding_total, 0) ELSE 0 END), 0) as marked_rounding_up_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(s.rounding_total, 0) < 0 THEN ABS(COALESCE(s.rounding_total, 0)) ELSE 0 END), 0) as marked_rounding_down_total')
            ->selectRaw('COALESCE(SUM(COALESCE(items_per_sale.item_qty_sold, 0)), 0) as item_qty_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(items_per_sale.item_qty_sold, 0) ELSE 0 END), 0) as marked_item_qty_sold')
            ->selectRaw('? as created_at', [$timestamp])
            ->selectRaw('? as updated_at', [$timestamp]);

        DB::table('report_daily_sales_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone',
            'trx_count', 'discounted_trx_count', 'rounding_trx_count',
            'marked_trx_count', 'marked_discounted_trx_count', 'marked_rounding_trx_count',
            'subtotal_sales', 'marked_subtotal_sales',
            'grand_sales', 'marked_grand_sales',
            'discount_total', 'marked_discount_total',
            'tax_total', 'marked_tax_total',
            'service_charge_total', 'marked_service_charge_total',
            'rounding_total', 'rounding_up_total', 'rounding_down_total',
            'marked_rounding_total', 'marked_rounding_up_total', 'marked_rounding_down_total',
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
            ->selectRaw("COALESCE(NULLIF(TRIM(pm.type), ''), NULLIF(TRIM(s.payment_method_type), ''), '') as payment_method_type")
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

    private function insertCoverageRows(array $outletIds, string $fromDate, string $toDate, string $timestamp, string $generationUlid): void
    {
        $rows = [];
        $hasGeneration = Schema::hasColumn('report_daily_summary_coverage', 'generation_ulid');
        $cursor = CarbonImmutable::parse($fromDate, TransactionDate::appTimezone());
        $end = CarbonImmutable::parse($toDate, TransactionDate::appTimezone());

        for (; $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addDay()) {
            $businessDate = $cursor->toDateString();
            foreach ($outletIds as $outletId) {
                $row = [
                    'outlet_id' => (string) $outletId,
                    'business_date' => $businessDate,
                    'synced_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                if ($hasGeneration) {
                    $row['generation_ulid'] = $generationUlid;
                }
                $rows[] = $row;
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

    private function emitProgress(?callable $callback, array $payload): void
    {
        if ($callback === null) {
            return;
        }

        try {
            $callback($payload);
        } catch (\Throwable $e) {
            // CLI/UI progress is observability-only and must never break reporting.
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
