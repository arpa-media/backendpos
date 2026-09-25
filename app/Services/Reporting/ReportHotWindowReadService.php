<?php

namespace App\Services\Reporting;

use App\Services\ReportDailySummaryService;
use App\Support\FinanceCategorySegment;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportHotWindowReadService
{
    public const HOT_WINDOW_DAYS = 5;
    public const MATERIALIZED_FIRST_DAYS = 5;

    /** @var array<string,array> */
    private array $effectivePlanCache = [];

    private const SPECS = [
        'sales' => ['alias' => 'rdss', 'columns' => [
            'outlet_id', 'business_date', 'business_timezone', 'trx_count', 'marked_trx_count',
            'discounted_trx_count', 'rounding_trx_count', 'marked_discounted_trx_count', 'marked_rounding_trx_count',
            'subtotal_sales', 'marked_subtotal_sales', 'grand_sales', 'marked_grand_sales', 'discount_total',
            'marked_discount_total', 'tax_total', 'marked_tax_total', 'service_charge_total', 'marked_service_charge_total',
            'rounding_total', 'marked_rounding_total', 'rounding_up_total', 'rounding_down_total',
            'marked_rounding_up_total', 'marked_rounding_down_total', 'item_qty_sold', 'marked_item_qty_sold',
        ]],
        'payment' => ['alias' => 'rdps', 'columns' => [
            'outlet_id', 'business_date', 'business_timezone', 'payment_method_name', 'payment_method_type',
            'trx_count', 'marked_trx_count', 'gross_sales', 'marked_gross_sales',
        ]],
        'channel' => ['alias' => 'rdcs', 'columns' => [
            'outlet_id', 'business_date', 'business_timezone', 'display_channel',
            'trx_count', 'marked_trx_count', 'gross_sales', 'marked_gross_sales',
        ]],
        'category' => ['alias' => 'rdcat', 'columns' => [
            'outlet_id', 'business_date', 'business_timezone', 'category_id', 'category_name', 'category_kind',
            'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales', 'discount_basis', 'marked_discount_basis',
        ]],
        'product' => ['alias' => 'rdprod', 'columns' => [
            'outlet_id', 'business_date', 'business_timezone', 'product_id', 'product_name', 'category_id', 'category_name', 'category_kind',
            'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales', 'discount_basis', 'marked_discount_basis',
        ]],
        'variant' => ['alias' => 'rdvar', 'columns' => [
            'outlet_id', 'business_date', 'business_timezone', 'product_id', 'variant_id', 'product_name', 'variant_name',
            'category_id', 'category_name', 'category_kind', 'line_count', 'marked_line_count', 'unit_price_sum',
            'marked_unit_price_sum', 'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales',
            'discount_basis', 'marked_discount_basis',
        ]],
    ];

    public function __construct(private readonly ReportDailySummaryService $dailySummaryService)
    {
    }

    public function readPlan(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeRange($dateFrom, $dateTo, $timezone);
        $plan = $this->plan($fromDate, $toDate, $timezone);

        return array_merge([
            'contract' => 'erp_pos_console_i05_materialized_first_plan_v1',
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'timezone' => $timezone,
            'hot_window_days' => self::HOT_WINDOW_DAYS,
            'materialized_first_days' => self::MATERIALIZED_FIRST_DAYS,
        ], $plan);
    }

    public function readContractStatus(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeRange($dateFrom, $dateTo, $timezone);
        $plan = $this->effectivePlan($outletIds, $fromDate, $toDate, $timezone);

        $requestedDays = CarbonImmutable::parse($fromDate, $timezone)->diffInDays(CarbonImmutable::parse($toDate, $timezone)) + 1;
        $requestedRows = count($outletIds) * $requestedDays;
        $liveDays = (int) ($plan['live_days'] ?? 0);
        $liveRows = count($outletIds) * $liveDays;

        $materializedStatus = null;
        if (! empty($plan['historical_from']) && ! empty($plan['historical_to'])) {
            $materializedStatus = $this->dailySummaryService->readContractStatus(
                $outletIds,
                $plan['historical_from'],
                $plan['historical_to'],
                $timezone,
            );
        }

        $materializedExpectedRows = (int) ($materializedStatus['expected_coverage_rows'] ?? 0);
        $materializedCoveredRows = (int) ($materializedStatus['covered_rows'] ?? 0);
        $materializedMissingRows = (int) ($materializedStatus['missing_rows'] ?? 0);
        $materializedGateReady = $materializedStatus === null || (bool) ($materializedStatus['ready'] ?? false);
        $readyRows = min($requestedRows, $liveRows + $materializedCoveredRows);
        $missingRows = max(0, $requestedRows - $readyRows);
        $coveragePercent = $requestedRows > 0 ? round(($readyRows / $requestedRows) * 100, 2) : 100.0;

        $mode = (string) ($plan['mode'] ?? 'materialized');
        $state = ! $materializedGateReady
            ? 'warming_required'
            : match ($mode) {
                'live_fallback' => 'live_fallback_materialized_not_ready',
                'hybrid_fallback' => 'hybrid_fallback_materialized_not_ready',
                default => (string) ($materializedStatus['state'] ?? 'ready'),
            };

        $preferredStatus = is_array($plan['preferred_materialized_status'] ?? null)
            ? $plan['preferred_materialized_status']
            : null;

        return [
            'contract' => 'erp_pos_console_i05_materialized_first_live_fallback_v1',
            'consumer_contract' => 'materialized_first_then_live_fallback_max_5_days',
            'source' => match ($mode) {
                'live_fallback' => 'live_sales_canonical (materialized fallback)',
                'hybrid_fallback' => 'materialized_history + live_sales_canonical fallback',
                default => (string) ($materializedStatus['source'] ?? 'report_daily_*_summaries'),
            },
            'read_mode' => $mode,
            'state' => $state,
            'ready' => $materializedGateReady,
            'http_backfill' => false,
            'materialized_first' => true,
            'preferred_materialized_ready' => (bool) ($plan['preferred_materialized_ready'] ?? true),
            'fallback_reason' => $plan['fallback_reason'] ?? null,
            'business_date_source' => $mode === 'materialized'
                ? (string) ($materializedStatus['business_date_source'] ?? 'report_sale_business_dates')
                : 'materialized history + sales.created_at/sale_number exact business-date resolver',
            'hot_window_days' => self::HOT_WINDOW_DAYS,
            'materialized_first_days' => self::MATERIALIZED_FIRST_DAYS,
            'hot_window_from' => $plan['hot_window_from'],
            'hot_window_to' => $plan['hot_window_to'],
            'preferred_date_from' => $plan['preferred_from'],
            'preferred_date_to' => $plan['preferred_to'],
            'live_date_from' => $plan['live_from'],
            'live_date_to' => $plan['live_to'],
            'materialized_date_from' => $plan['historical_from'],
            'materialized_date_to' => $plan['historical_to'],
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'range_days' => $requestedDays,
            'outlet_count' => count($outletIds),
            'outlet_ids' => $outletIds,
            'timezone' => $timezone,
            'expected_coverage_rows' => $requestedRows,
            'covered_rows' => $readyRows,
            'missing_rows' => $missingRows,
            'coverage_percent' => $coveragePercent,
            'materialized_expected_rows' => $materializedExpectedRows,
            'materialized_covered_rows' => $materializedCoveredRows,
            'materialized_missing_rows' => $materializedMissingRows,
            'pending_refresh_rows' => (int) ($materializedStatus['pending_refresh_rows'] ?? 0),
            'preferred_materialized_pending_refresh_rows' => (int) ($preferredStatus['pending_refresh_rows'] ?? 0),
            'oldest_synced_at' => $materializedStatus['oldest_synced_at'] ?? null,
            'latest_synced_at' => $materializedStatus['latest_synced_at'] ?? null,
            'recovery_pipeline' => $materializedGateReady ? null : 'daily',
            'live_rows' => $liveRows,
            'historical_status' => $materializedStatus,
            'preferred_materialized_status' => $preferredStatus,
        ];
    }

    public function salesSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): Builder
    {
        return $this->summaryQuery('sales', $outletIds, $dateFrom, $dateTo, $timezone);
    }

    public function paymentSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): Builder
    {
        return $this->summaryQuery('payment', $outletIds, $dateFrom, $dateTo, $timezone);
    }

    public function channelSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): Builder
    {
        return $this->summaryQuery('channel', $outletIds, $dateFrom, $dateTo, $timezone);
    }

    public function categorySummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null, ?string $timezone = null): Builder
    {
        $query = $this->summaryQuery('category', $outletIds, $dateFrom, $dateTo, $timezone);
        FinanceCategorySegment::apply($query, 'rdcat.category_name', $categorySegment);

        return $query;
    }

    public function productSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null, ?string $timezone = null): Builder
    {
        $query = $this->summaryQuery('product', $outletIds, $dateFrom, $dateTo, $timezone);
        FinanceCategorySegment::apply($query, 'rdprod.category_name', $categorySegment);

        return $query;
    }

    public function variantSummaryQuery(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $categorySegment = null, ?string $timezone = null): Builder
    {
        $query = $this->summaryQuery('variant', $outletIds, $dateFrom, $dateTo, $timezone);
        FinanceCategorySegment::apply($query, 'rdvar.category_name', $categorySegment);

        return $query;
    }

    public function recentSaleIds(array $outletIds, ?string $dateFrom, ?string $dateTo, string $timezone, int $limit, bool $markedOnly = false): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) return [];

        [$fromDate, $toDate] = $this->normalizeRange($dateFrom, $dateTo, $timezone);
        $plan = $this->effectivePlan($outletIds, $fromDate, $toDate, $timezone);
        $limit = max(1, min(100, $limit));
        $ids = [];

        if ($plan['live_from'] && $plan['live_to']) {
            $liveScope = $this->liveScopeSalesSubquery($outletIds, $plan['live_from'], $plan['live_to']);
            $query = DB::query()
                ->fromSub($liveScope, 'live_scope')
                ->join('sales as s', 's.id', '=', 'live_scope.sale_id')
                ->when($markedOnly, fn ($q) => $q->whereRaw('COALESCE(CAST(live_scope.marking AS SIGNED), 0) = 1'))
                ->selectRaw('live_scope.sale_id')
                ->selectRaw('MAX(s.created_at) as latest_created_at')
                ->groupBy('live_scope.sale_id')
                ->orderByDesc('latest_created_at')
                ->orderByDesc('live_scope.sale_id')
                ->limit($limit);
            foreach ($query->pluck('live_scope.sale_id') as $id) $ids[(string) $id] = true;
        }

        if (count($ids) < $limit && $plan['historical_from'] && $plan['historical_to']) {
            $remaining = $limit - count($ids);
            $query = DB::table('report_sale_business_dates as rsbd')
                ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
                ->whereIn('rsbd.outlet_id', $outletIds)
                ->whereBetween('rsbd.business_date', [$plan['historical_from'], $plan['historical_to']])
                ->whereNull('s.deleted_at')
                ->where('s.status', 'PAID')
                ->when($markedOnly, fn ($q) => $q->whereRaw('COALESCE(CAST(s.marking AS SIGNED), 0) = 1'))
                ->selectRaw('rsbd.sale_id')
                ->selectRaw('MAX(s.created_at) as latest_created_at')
                ->groupBy('rsbd.sale_id')
                ->orderByDesc('latest_created_at')
                ->orderByDesc('rsbd.sale_id')
                ->limit($remaining);
            foreach ($query->pluck('rsbd.sale_id') as $id) $ids[(string) $id] = true;
        }

        if ($ids === []) return [];

        return DB::table('sales')
            ->whereIn('id', array_keys($ids))
            ->when($markedOnly, fn ($q) => $q->whereRaw('COALESCE(CAST(marking AS SIGNED), 0) = 1'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function saleExists(array $outletIds, ?string $dateFrom, ?string $dateTo, string $timezone, string $saleId, bool $markedOnly = false): bool
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $saleId = trim($saleId);
        if ($outletIds === [] || $saleId === '') return false;

        [$fromDate, $toDate] = $this->normalizeRange($dateFrom, $dateTo, $timezone);
        $plan = $this->effectivePlan($outletIds, $fromDate, $toDate, $timezone);

        if ($plan['live_from'] && $plan['live_to']) {
            $scope = $this->liveScopeSalesSubquery($outletIds, $plan['live_from'], $plan['live_to']);
            $query = DB::query()->fromSub($scope, 'live_scope')->where('live_scope.sale_id', $saleId);
            if ($markedOnly) $query->whereRaw('COALESCE(CAST(live_scope.marking AS SIGNED), 0) = 1');
            if ($query->exists()) return true;
        }

        if ($plan['historical_from'] && $plan['historical_to']) {
            return DB::table('report_sale_business_dates as rsbd')
                ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
                ->where('rsbd.sale_id', $saleId)
                ->whereIn('rsbd.outlet_id', $outletIds)
                ->whereBetween('rsbd.business_date', [$plan['historical_from'], $plan['historical_to']])
                ->when($markedOnly, fn ($q) => $q->whereRaw('COALESCE(CAST(s.marking AS SIGNED), 0) = 1'))
                ->exists();
        }

        return false;
    }

    public function cacheTtlSeconds(array $reportingSource, int $materializedTtl = 300): int
    {
        return in_array((string) ($reportingSource['read_mode'] ?? ''), ['live', 'hybrid', 'live_fallback', 'hybrid_fallback'], true)
            ? 30
            : $materializedTtl;
    }

    private function summaryQuery(string $family, array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): Builder
    {
        $spec = self::SPECS[$family] ?? null;
        if (! $spec) throw new \InvalidArgumentException("Unknown hot-window report family {$family}");

        $outletIds = $this->normalizeOutletIds($outletIds);
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeRange($dateFrom, $dateTo, $timezone);
        $plan = $this->effectivePlan($outletIds, $fromDate, $toDate, $timezone);
        $parts = [];

        if ($plan['historical_from'] && $plan['historical_to']) {
            $historical = $this->historicalSummaryQuery($family, $outletIds, $plan['historical_from'], $plan['historical_to']);
            $parts[] = $historical->select(array_map(fn ($column) => $spec['alias'].'.'.$column, $spec['columns']));
        }

        if ($plan['live_from'] && $plan['live_to']) {
            $parts[] = $this->liveSummaryQuery($family, $outletIds, $plan['live_from'], $plan['live_to']);
        }

        if ($parts === []) {
            return DB::query()->fromSub(
                DB::query()->selectRaw('1 as __empty')->whereRaw('1=0'),
                $spec['alias']
            );
        }

        $union = array_shift($parts);
        foreach ($parts as $part) $union->unionAll($part);

        return DB::query()->fromSub($union, $spec['alias']);
    }

    private function historicalSummaryQuery(string $family, array $outletIds, string $fromDate, string $toDate): Builder
    {
        return match ($family) {
            'sales' => $this->dailySummaryService->salesSummaryQuery($outletIds, $fromDate, $toDate),
            'payment' => $this->dailySummaryService->paymentSummaryQuery($outletIds, $fromDate, $toDate),
            'channel' => $this->dailySummaryService->channelSummaryQuery($outletIds, $fromDate, $toDate),
            'category' => $this->dailySummaryService->categorySummaryQuery($outletIds, $fromDate, $toDate),
            'product' => $this->dailySummaryService->productSummaryQuery($outletIds, $fromDate, $toDate),
            'variant' => $this->dailySummaryService->variantSummaryQuery($outletIds, $fromDate, $toDate),
            default => throw new \InvalidArgumentException("Unknown historical report family {$family}"),
        };
    }

    private function liveSummaryQuery(string $family, array $outletIds, string $fromDate, string $toDate): Builder
    {
        return match ($family) {
            'sales' => $this->liveSalesSummaryQuery($outletIds, $fromDate, $toDate),
            'payment' => $this->livePaymentSummaryQuery($outletIds, $fromDate, $toDate),
            'channel' => $this->liveChannelSummaryQuery($outletIds, $fromDate, $toDate),
            'category' => $this->liveItemDimensionQuery($outletIds, $fromDate, $toDate, 'category'),
            'product' => $this->liveItemDimensionQuery($outletIds, $fromDate, $toDate, 'product'),
            'variant' => $this->liveItemDimensionQuery($outletIds, $fromDate, $toDate, 'variant'),
            default => throw new \InvalidArgumentException("Unknown live report family {$family}"),
        };
    }

    private function liveSalesSummaryQuery(array $outletIds, string $fromDate, string $toDate): Builder
    {
        $scope = $this->liveScopeSalesSubquery($outletIds, $fromDate, $toDate);
        $itemsPerSale = DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('si.voided_at')
            ->groupBy('scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_qty_sold');

        return DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($itemsPerSale, 'items_per_sale', fn ($join) => $join->on('items_per_sale.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone')
            ->selectRaw('scope_sales.outlet_id')
            ->selectRaw('scope_sales.business_date')
            ->selectRaw('scope_sales.business_timezone')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(s.discount_total, 0) > 0 THEN 1 ELSE 0 END) as discounted_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(s.rounding_total, 0) <> 0 THEN 1 ELSE 0 END) as rounding_trx_count')
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
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.rounding_total, 0) ELSE 0 END), 0) as marked_rounding_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(s.rounding_total, 0) > 0 THEN COALESCE(s.rounding_total, 0) ELSE 0 END), 0) as rounding_up_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(s.rounding_total, 0) < 0 THEN ABS(COALESCE(s.rounding_total, 0)) ELSE 0 END), 0) as rounding_down_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(s.rounding_total, 0) > 0 THEN COALESCE(s.rounding_total, 0) ELSE 0 END), 0) as marked_rounding_up_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(s.rounding_total, 0) < 0 THEN ABS(COALESCE(s.rounding_total, 0)) ELSE 0 END), 0) as marked_rounding_down_total')
            ->selectRaw('COALESCE(SUM(COALESCE(items_per_sale.item_qty_sold, 0)), 0) as item_qty_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(items_per_sale.item_qty_sold, 0) ELSE 0 END), 0) as marked_item_qty_sold');
    }

    private function livePaymentSummaryQuery(array $outletIds, string $fromDate, string $toDate): Builder
    {
        $scope = $this->liveScopeSalesSubquery($outletIds, $fromDate, $toDate);
        $normalized = DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->join('sale_payments as sp', 'sp.sale_id', '=', 'scope_sales.sale_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')
            ->selectRaw('scope_sales.outlet_id, scope_sales.business_date, scope_sales.business_timezone')
            ->selectRaw("COALESCE(NULLIF(TRIM(pm.name), ''), NULLIF(TRIM(s.payment_method_name), ''), '-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(pm.type), ''), NULLIF(TRIM(s.payment_method_type), ''), '') as payment_method_type")
            ->selectRaw('1 as trx_count')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END as marked_trx_count')
            ->selectRaw("CASE WHEN LOWER(TRIM(COALESCE(pm.name, ''))) IN ('cash', 'tunai') AND COALESCE(sp.amount, 0) > 0 THEN GREATEST(COALESCE(sp.amount, 0) - COALESCE(s.change_total, 0), 0) ELSE COALESCE(sp.amount, 0) END as gross_sales")
            ->selectRaw("CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN (CASE WHEN LOWER(TRIM(COALESCE(pm.name, ''))) IN ('cash', 'tunai') AND COALESCE(sp.amount, 0) > 0 THEN GREATEST(COALESCE(sp.amount, 0) - COALESCE(s.change_total, 0), 0) ELSE COALESCE(sp.amount, 0) END) ELSE 0 END as marked_gross_sales");

        $fallback = DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')
            ->whereNotExists(function ($exists) {
                $exists->selectRaw('1')->from('sale_payments as sp_check')->whereColumn('sp_check.sale_id', 'scope_sales.sale_id');
            })
            ->selectRaw('scope_sales.outlet_id, scope_sales.business_date, scope_sales.business_timezone')
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_name), ''), '-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_type), ''), '') as payment_method_type")
            ->selectRaw('1 as trx_count')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END as marked_trx_count')
            ->selectRaw('COALESCE(s.grand_total, 0) as gross_sales')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.grand_total, 0) ELSE 0 END as marked_gross_sales');

        return DB::query()
            ->fromSub($normalized->unionAll($fallback), 'payment_rows')
            ->groupBy('outlet_id', 'business_date', 'business_timezone', 'payment_method_name', 'payment_method_type')
            ->selectRaw('outlet_id, business_date, business_timezone, payment_method_name, payment_method_type')
            ->selectRaw('COALESCE(SUM(trx_count), 0) as trx_count')
            ->selectRaw('COALESCE(SUM(marked_trx_count), 0) as marked_trx_count')
            ->selectRaw('COALESCE(SUM(gross_sales), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(marked_gross_sales), 0) as marked_gross_sales');
    }

    private function liveChannelSummaryQuery(array $outletIds, string $fromDate, string $toDate): Builder
    {
        $scope = $this->liveScopeSalesSubquery($outletIds, $fromDate, $toDate);
        $channelMap = $this->channelMapSubquery($scope);

        return DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($channelMap, 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')
            ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'channel_map.display_channel')
            ->selectRaw('scope_sales.outlet_id, scope_sales.business_date, scope_sales.business_timezone')
            ->selectRaw("COALESCE(channel_map.display_channel, '') as display_channel")
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN COALESCE(s.grand_total, 0) ELSE 0 END), 0) as marked_gross_sales');
    }

    private function liveItemDimensionQuery(array $outletIds, string $fromDate, string $toDate, string $dimension): Builder
    {
        $scope = $this->liveScopeSalesSubquery($outletIds, $fromDate, $toDate);
        $saleTotals = DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sale_items as tsi', 'tsi.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('tsi.voided_at')
            ->groupBy('scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw('COALESCE(SUM(tsi.line_total), 0) as items_gross_sales');

        $query = DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoinSub($saleTotals, 'sale_totals', fn ($join) => $join->on('sale_totals.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')->whereNull('si.voided_at');

        $common = [
            "COALESCE(p.category_id, '') as category_id",
            "COALESCE(NULLIF(c.name, ''), 'Uncategorized') as category_name",
            "MAX(COALESCE(NULLIF(si.category_kind_snapshot, ''), '')) as category_kind",
            'COALESCE(SUM(si.qty), 0) as item_sold',
            'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN si.qty ELSE 0 END), 0) as marked_item_sold',
            'COALESCE(SUM(si.line_total), 0) as gross_sales',
            'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 THEN si.line_total ELSE 0 END), 0) as marked_gross_sales',
            'COALESCE(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0) as discount_basis',
            'COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking, 0) = 1 AND COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0) as marked_discount_basis',
        ];

        if ($dimension === 'category') {
            return $query
                ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'p.category_id', 'c.name')
                ->selectRaw(implode(', ', array_merge([
                    'scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone',
                ], $common)));
        }

        if ($dimension === 'product') {
            return $query
                ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'si.product_id', 'si.product_name', 'p.category_id', 'c.name')
                ->selectRaw(implode(', ', array_merge([
                    'scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone',
                    "COALESCE(si.product_id, '') as product_id",
                    "COALESCE(NULLIF(si.product_name, ''), '-') as product_name",
                ], $common)));
        }

        return $query
            ->groupBy('scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone', 'si.product_id', 'si.variant_id', 'si.product_name', 'si.variant_name', 'p.category_id', 'c.name')
            ->selectRaw(implode(', ', [
                'scope_sales.outlet_id', 'scope_sales.business_date', 'scope_sales.business_timezone',
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
            ]));
    }

    private function channelMapSubquery(Builder $scope): Builder
    {
        return DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s1', 's1.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($this->saleItemChannelsSubquery($scope), 'item_channels', fn ($join) => $join->on('item_channels.sale_id', '=', 'scope_sales.sale_id'))
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw("COALESCE(NULLIF(CASE
                WHEN UPPER(COALESCE(s1.channel, '')) = 'DELIVERY' AND NULLIF(TRIM(COALESCE(s1.online_order_source, '')), '') IS NOT NULL THEN LOWER(TRIM(s1.online_order_source))
                WHEN UPPER(COALESCE(s1.channel, '')) = 'MIXED' AND NULLIF(TRIM(COALESCE(item_channels.channel_display, '')), '') IS NOT NULL THEN item_channels.channel_display
                ELSE UPPER(COALESCE(s1.channel, ''))
            END, ''), '') as display_channel");
    }

    private function saleItemChannelsSubquery(Builder $scope): Builder
    {
        return DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('si.voided_at')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT si.channel ORDER BY FIELD(si.channel, 'DINE_IN', 'TAKEAWAY', 'DELIVERY'), si.channel SEPARATOR ' + ') as channel_display")
            ->groupBy('scope_sales.sale_id');
    }

    private function liveScopeSalesSubquery(array $outletIds, string $fromDate, string $toDate): Builder
    {
        $timezoneMap = DB::table('outlets')->whereIn('id', $outletIds)->pluck('timezone', 'id');
        $groups = [];
        foreach ($outletIds as $outletId) {
            $tz = TransactionDate::normalizeTimezone((string) ($timezoneMap[$outletId] ?? ''), TransactionDate::appTimezone());
            $groups[$tz][] = $outletId;
        }

        $queries = [];
        foreach ($groups as $timezone => $ids) {
            $resolvedLocal = TransactionDate::resolvedSaleLocalSqlExpression('live_s.created_at', 'live_s.sale_number', $timezone);
            $startHour = TransactionDate::businessDayStartHour($timezone);
            $businessDateExpr = $startHour > 0
                ? "DATE(DATE_SUB(({$resolvedLocal}), INTERVAL {$startHour} HOUR))"
                : "DATE(({$resolvedLocal}))";

            $query = DB::table('sales as live_s')
                ->whereIn('live_s.outlet_id', $ids)
                ->whereNull('live_s.deleted_at')
                ->where('live_s.status', 'PAID');
            TransactionDate::applyExactBusinessDateScope(
                $query,
                'live_s.created_at',
                $fromDate,
                $toDate,
                $timezone,
                'live_s.sale_number',
            );

            $query
                ->selectRaw('live_s.id as sale_id')
                ->selectRaw('live_s.outlet_id')
                ->selectRaw("{$businessDateExpr} as business_date")
                ->selectRaw('? as business_timezone', [$timezone])
                ->selectRaw('COALESCE(CAST(live_s.marking AS SIGNED), 0) as marking');
            $queries[] = $query;
        }

        if ($queries === []) {
            return DB::table('sales as live_s')
                ->selectRaw('live_s.id as sale_id, live_s.outlet_id, DATE(live_s.created_at) as business_date')
                ->selectRaw('? as business_timezone', [TransactionDate::appTimezone()])
                ->selectRaw('COALESCE(CAST(live_s.marking AS SIGNED), 0) as marking')
                ->whereRaw('1=0');
        }

        $union = array_shift($queries);
        foreach ($queries as $query) $union->unionAll($query);

        return DB::query()->fromSub($union, 'live_scope_sales')
            ->select(['live_scope_sales.sale_id', 'live_scope_sales.outlet_id', 'live_scope_sales.business_date', 'live_scope_sales.business_timezone', 'live_scope_sales.marking']);
    }

    /**
     * Date-only structural plan. The latest five business dates are candidates for
     * materialized-first reads. Runtime readiness is resolved by effectivePlan().
     */
    private function plan(string $fromDate, string $toDate, string $timezone): array
    {
        $today = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);
        $hotStart = $today->subDays(self::MATERIALIZED_FIRST_DAYS - 1);
        $from = CarbonImmutable::parse($fromDate, $timezone);
        $to = CarbonImmutable::parse($toDate, $timezone);

        $preferredFrom = $from->greaterThan($hotStart) ? $from : $hotStart;
        $preferredTo = $to->lessThan($today) ? $to : $today;
        if ($preferredTo->lessThan($preferredFrom)) {
            $preferredFromString = null;
            $preferredToString = null;
            $preferredDays = 0;
        } else {
            $preferredFromString = $preferredFrom->toDateString();
            $preferredToString = $preferredTo->toDateString();
            $preferredDays = $preferredFrom->diffInDays($preferredTo) + 1;
        }

        $historicalTo = $to->lessThan($hotStart) ? $to : $hotStart->subDay();
        if ($historicalTo->lessThan($from)) {
            $historicalFromString = null;
            $historicalToString = null;
        } else {
            $historicalFromString = $from->toDateString();
            $historicalToString = $historicalTo->toDateString();
        }

        $hasPreferred = $preferredDays > 0;
        $hasHistorical = $historicalFromString !== null;

        return [
            'mode' => $hasPreferred ? ($hasHistorical ? 'materialized_first_hybrid' : 'materialized_first') : 'materialized',
            'hot_window_from' => $hotStart->toDateString(),
            'hot_window_to' => $today->toDateString(),
            'preferred_from' => $preferredFromString,
            'preferred_to' => $preferredToString,
            'preferred_days' => $preferredDays,
            // Structural live candidate used by recovery hardening; effectivePlan
            // may switch this segment back to materialized when coverage is fresh.
            'live_from' => $preferredFromString,
            'live_to' => $preferredToString,
            'live_days' => $preferredDays,
            'historical_from' => $historicalFromString,
            'historical_to' => $historicalToString,
        ];
    }

    private function effectivePlan(array $outletIds, string $fromDate, string $toDate, string $timezone): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $cacheKey = md5(json_encode([$outletIds, $fromDate, $toDate, $timezone]));
        if (isset($this->effectivePlanCache[$cacheKey])) {
            return $this->effectivePlanCache[$cacheKey];
        }

        $structural = $this->plan($fromDate, $toDate, $timezone);
        if (empty($structural['preferred_from']) || empty($structural['preferred_to'])) {
            return $this->effectivePlanCache[$cacheKey] = array_merge($structural, [
                'mode' => 'materialized',
                'historical_from' => $fromDate,
                'historical_to' => $toDate,
                'live_from' => null,
                'live_to' => null,
                'live_days' => 0,
                'preferred_materialized_ready' => true,
                'preferred_materialized_status' => null,
                'fallback_reason' => null,
            ]);
        }

        $preferredStatus = $this->dailySummaryService->readContractStatus(
            $outletIds,
            (string) $structural['preferred_from'],
            (string) $structural['preferred_to'],
            $timezone,
        );
        $pendingRefresh = (int) ($preferredStatus['pending_refresh_rows'] ?? 0);
        $preferredReady = (bool) ($preferredStatus['ready'] ?? false) && $pendingRefresh === 0;

        if ($preferredReady) {
            return $this->effectivePlanCache[$cacheKey] = array_merge($structural, [
                'mode' => 'materialized',
                'historical_from' => $fromDate,
                'historical_to' => $toDate,
                'live_from' => null,
                'live_to' => null,
                'live_days' => 0,
                'preferred_materialized_ready' => true,
                'preferred_materialized_status' => $preferredStatus,
                'fallback_reason' => null,
            ]);
        }

        $hasHistorical = ! empty($structural['historical_from']) && ! empty($structural['historical_to']);
        $reason = ! (bool) ($preferredStatus['ready'] ?? false)
            ? 'coverage_not_ready'
            : ($pendingRefresh > 0 ? 'refresh_pending' : 'materialized_not_fresh');

        return $this->effectivePlanCache[$cacheKey] = array_merge($structural, [
            'mode' => $hasHistorical ? 'hybrid_fallback' : 'live_fallback',
            'preferred_materialized_ready' => false,
            'preferred_materialized_status' => $preferredStatus,
            'fallback_reason' => $reason,
        ]);
    }

    private function normalizeRange(?string $dateFrom, ?string $dateTo, string $timezone): array
    {
        $today = TransactionDate::businessTodayDateString($timezone);
        try { $from = $dateFrom ? CarbonImmutable::parse($dateFrom, $timezone)->toDateString() : $today; }
        catch (\Throwable) { $from = $today; }
        try { $to = $dateTo ? CarbonImmutable::parse($dateTo, $timezone)->toDateString() : $today; }
        catch (\Throwable) { $to = $today; }
        if ($to < $from) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    private function normalizeOutletIds(array $outletIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => trim((string) $id), $outletIds))));
        sort($ids);
        return $ids;
    }
}
