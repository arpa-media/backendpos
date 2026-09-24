<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleCancelRequest;
use App\Services\CashierAlignedSaleScopeService;
use App\Services\ReportSaleScopeCacheService;
use App\Services\Reporting\ItemReportReadService;
use App\Support\DeliveryNoTaxReadModel;
use App\Support\SaleRounding;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private const DISCOUNT_OPTION_LIMIT = 500;

    public function __construct(
        private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope,
        private readonly ReportSaleScopeCacheService $reportSaleScopeCache,
        private readonly ReportDailySummaryService $dailySummaryService,
        private readonly ItemReportReadService $itemReportReadService,
    ) {
    }

    private function currentTimezone(): string
    {
        $fallback = 'Asia/Jakarta';

        return TransactionDate::normalizeTimezone((string) config('app.timezone', $fallback), $fallback);
    }

    private function resolveTimezone(?string $outletId): string
    {
        $defaultTimezone = $this->currentTimezone();

        if (!$outletId) {
            return $defaultTimezone;
        }

        $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');

        return TransactionDate::normalizeTimezone(filled($timezone) ? (string) $timezone : $defaultTimezone, $defaultTimezone);
    }

    private function resolveOutletUtcRange(?string $dateFrom, ?string $dateTo, ?string $outletId): array
    {
        $timezone = $this->resolveTimezone($outletId);

        [$fromLocal, $toLocal, $fromUtc, $toUtc] = TransactionDate::dateRange($dateFrom, $dateTo, $timezone);

        return [$fromLocal, $toLocal, $fromUtc, $toUtc, $timezone];
    }

    private function formatCreatedAt($value, ?string $timezone = null, ?string $saleNumber = null): ?string
    {
        return TransactionDate::formatSaleLocal($value, $timezone ?: $this->currentTimezone(), $saleNumber);
    }

    private function resolveRange(?string $dateFrom, ?string $dateTo): array
    {
        [$fromLocal, $toLocal] = TransactionDate::dateRange(
            $dateFrom,
            $dateTo,
            $this->currentTimezone()
        );

        return [$fromLocal, $toLocal];
    }

    private function applyDateRange(object $query, string $column, ?string $dateFrom, ?string $dateTo): array
    {
        $saleNumberColumn = preg_replace('/created_at$/', 'sale_number', $column);

        return $this->applyBusinessDateScope(
            $query,
            is_string($saleNumberColumn) ? $saleNumberColumn : null,
            $column,
            $dateFrom,
            $dateTo,
            $this->currentTimezone()
        );
    }

    private function applyOutletUtcDateRange(object $query, string $column, ?string $dateFrom, ?string $dateTo, ?string $outletId): array
    {
        $saleNumberColumn = preg_replace('/created_at$/', 'sale_number', $column);
        $timezone = $this->resolveTimezone($outletId);

        return $this->applyBusinessDateScope(
            $query,
            is_string($saleNumberColumn) ? $saleNumberColumn : null,
            $column,
            $dateFrom,
            $dateTo,
            $timezone
        );
    }

    private function dateTokensForScope(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        return TransactionDate::dateTokens($dateFrom, $dateTo, $timezone ?: $this->currentTimezone());
    }

    private function applyBusinessDateScope(object $query, ?string $saleNumberColumn, string $createdAtColumn, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        // I05 PERFORMANCE: keep the exact business-date semantics, but make the
        // first predicate index-friendly. The legacy implementation generated one
        // leading-wildcard LIKE on sale_number for every day in the requested
        // range (30 days = 30 OR-ed table scans). TransactionDate first applies a
        // coarse created_at range that can use the reporting indexes, then resolves
        // the UTC/local storage ambiguity with the same sale-number date token.
        $window = TransactionDate::applyExactBusinessDateScope(
            $query,
            $createdAtColumn,
            $dateFrom,
            $dateTo,
            $timezone ?: $this->currentTimezone(),
            $saleNumberColumn
        );

        return [
            $window['requested_from'],
            $window['requested_to'],
            $window['from_utc'],
            $window['to_utc'],
        ];
    }


    private function resolveReportScopeOutletIds(array $params, ?string $outletId): array
    {
        $ids = array_values(array_filter(array_map('strval', $params['scope_outlet_ids'] ?? [])));
        if ($ids !== []) {
            return $ids;
        }

        return $outletId ? [(string) $outletId] : [];
    }

    private function resolveReportScopeTimezone(array $params, ?string $outletId): string
    {
        if (!empty($params['scope_timezone'])) {
            return TransactionDate::normalizeTimezone((string) $params['scope_timezone'], $this->currentTimezone());
        }

        return $this->resolveTimezone($outletId);
    }

    private function resolveCachedSaleScope(array $scopeOutletIds, array $params, string $scopeTimezone, string $namespace = 'report_service_cashier_aligned'): array
    {
        return $this->cashierAlignedSaleScope->rememberScope(
            $this->reportSaleScopeCache,
            $namespace,
            $scopeOutletIds,
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $scopeTimezone,
        );
    }

    private function cachedSaleIdSubquery(array $saleScope): QueryBuilder
    {
        return $this->reportSaleScopeCache->subquery((string) ($saleScope['scope_key'] ?? ''));
    }

    /**
     * V8 I04 historical read contract for Cashier-family reads.
     *
     * Prefer the canonical report_sale_business_dates index only when its coverage
     * is already fresh. Never build/backfill that index inside the HTTP request.
     * If coverage is missing/stale, fall back to the pre-I04 exact Cashier resolver.
     * This preserves transaction membership/cutoff semantics 100%.
     *
     * @return string query strategy used for diagnostics
     */
    private function applyCashierHistoricalScope(
        object $query,
        array $scopeOutletIds,
        ?string $outletId,
        array $window,
        string $timezone,
        string $idColumn = 'sales.id',
        string $saleNumberColumn = 'sale_number',
        string $createdAtColumn = 'created_at',
    ): string {
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $scopeOutletIds))));
        if ($outletIds === [] && filled($outletId)) {
            $outletIds = [(string) $outletId];
        }

        if ($outletIds !== []) {
            $covered = $this->cashierAlignedSaleScope->coveredSaleIdsSubquery(
                $outletIds,
                $window['requested_from']->toDateString(),
                $window['requested_to']->toDateString(),
                $timezone,
                false,
            );

            if ($covered !== null) {
                $query->whereIn($idColumn, $covered);
                return 'report_sale_business_dates_covered';
            }
        }

        // Fallback is intentionally the exact pre-I04 resolver. Do not simplify it
        // to DATE(created_at): Asia/Makassar uses the existing 01:00 business cutoff
        // and historical storage can contain UTC/local ambiguities.
        if ($this->isMakassarCashierBusinessTimezone($timezone)) {
            $candidateDateTo = $window['requested_to']->addDay()->toDateString();
            $this->applyBusinessDateScope(
                $query,
                $saleNumberColumn,
                $createdAtColumn,
                $window['requested_from']->toDateString(),
                $candidateDateTo,
                $timezone
            );
        } else {
            $this->applyBusinessDateScope(
                $query,
                $saleNumberColumn,
                $createdAtColumn,
                $window['requested_from']->toDateString(),
                $window['requested_to']->toDateString(),
                $timezone
            );
        }

        return 'exact_cashier_fallback';
    }

    private function paginate(QueryBuilder $q, int $perPage, int $page): LengthAwarePaginator
    {
        // paginate is available on query builder in Laravel
        return $q->paginate(perPage: $perPage, page: $page);
    }

    private function paginateKnownTotal(QueryBuilder $q, int $perPage, int $page, int $total): LengthAwarePaginator
    {
        $items = (clone $q)->forPage($page, $perPage)->get();

        return new LengthAwarePaginator($items, max(0, $total), $perPage, $page);
    }

    /**
     * Derived table: 1 payment method name per sale (phase1 usually single payment)
     * - Prevents row duplication when joining sale_payments.
     */
    private function salePaymentMethodSubquery(): QueryBuilder
    {
        return DB::table('sale_payments as sp')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->selectRaw('sp.sale_id, MIN(pm.name) as payment_method_name')
            ->groupBy('sp.sale_id');
    }

    /**
     * V8 I02: canonical detail scope. Do not copy a whole 1-year sale-id set into
     * report_sale_scope_cache. report_sale_business_dates is already the exact
     * Cashier-aligned business-date index produced by the existing cutoff resolver.
     */
    private function canonicalReportSalesQuery(array $scopeOutletIds, array $params, bool $markedOnly = false): QueryBuilder
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);

        $query = DB::table('report_sale_business_dates as rsbd')
            ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
            ->whereBetween('rsbd.business_date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        if ($scopeOutletIds !== []) {
            $query->whereIn('rsbd.outlet_id', $scopeOutletIds);
        }

        if ($markedOnly) {
            $query->whereRaw('COALESCE(CAST(rsbd.marking AS SIGNED), 0) = 1');
        }

        if (!empty($params['sale_number'])) {
            $query->where('s.sale_number', 'like', '%' . trim((string) $params['sale_number']) . '%');
        }
        if (!empty($params['channel'])) {
            $query->where('s.channel', '=', trim((string) $params['channel']));
        }
        if (!empty($params['payment_method_name'])) {
            $this->applyReportPaymentMethodFilter($query, trim((string) $params['payment_method_name']));
        }

        return $query;
    }

    private function applyReportPaymentMethodFilter(QueryBuilder $query, string $paymentMethod): void
    {
        if ($paymentMethod === '') {
            return;
        }

        // Preserve the pre-I02 Report Center semantics: filtering is based on the
        // actual sale_payments relation. The optimization changes *where* the
        // aggregation happens (page IDs only), not which transactions match.
        $query->whereExists(function ($paymentExists) use ($paymentMethod): void {
            $paymentExists
                ->selectRaw('1')
                ->from('sale_payments as sp_filter')
                ->join('payment_methods as pm_filter', 'pm_filter.id', '=', 'sp_filter.payment_method_id')
                ->whereColumn('sp_filter.sale_id', 's.id')
                ->where('pm_filter.name', '=', $paymentMethod);
        });
    }

    private function pagePaymentMethodMap(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_filter(array_map('strval', $saleIds))));
        if ($saleIds === []) {
            return [];
        }

        return DB::table('sale_payments as sp')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereIn('sp.sale_id', $saleIds)
            ->selectRaw('sp.sale_id, MIN(pm.name) as payment_method_name')
            ->groupBy('sp.sale_id')
            ->pluck('payment_method_name', 'sale_id')
            ->mapWithKeys(fn ($value, $key) => [(string) $key => (string) $value])
            ->all();
    }

    /**
     * Read-only materialized coverage check. It never backfills data from an HTTP
     * request; incomplete coverage falls back to the canonical sales aggregate.
     */
    private function hasCompleteDailySummaryCoverage(array $scopeOutletIds, array $params): bool
    {
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $scopeOutletIds))));
        if ($outletIds === []) {
            return false;
        }

        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $expectedDays = $from->diffInDays($to) + 1;
        $expectedRows = count($outletIds) * $expectedDays;
        sort($outletIds);

        $cacheKey = 'report-daily-coverage-ready:v8i02:' . sha1(json_encode([
            'outlets' => $outletIds,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ], JSON_UNESCAPED_SLASHES));

        return (bool) Cache::remember($cacheKey, now()->addSeconds(60), function () use ($outletIds, $from, $to, $expectedRows) {
            $coveredRows = DB::table('report_daily_summary_coverage')
                ->whereIn('outlet_id', $outletIds)
                ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                ->count();

            return $coveredRows >= $expectedRows;
        });
    }

    private function commonReportFilterOptions(array $scopeOutletIds, array $params, bool $withDiscountNames = false): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $paymentNames = [];

        if ($scopeOutletIds !== []) {
            $paymentNames = $this->dailySummaryService
                ->paymentSummaryQuery($scopeOutletIds, $from->toDateString(), $to->toDateString())
                ->selectRaw('TRIM(rdps.payment_method_name) as payment_method_name')
                ->whereRaw("TRIM(COALESCE(rdps.payment_method_name, '')) <> ''")
                ->distinct()
                ->orderBy('payment_method_name')
                ->pluck('payment_method_name')
                ->map(fn ($name) => trim((string) $name))
                ->filter()
                ->values()
                ->all();
        }

        $options = [
            'outlet_filters' => array_values((array) ($params['outlet_filter_options'] ?? [])),
            'payment_method_names' => $paymentNames,
        ];

        if ($withDiscountNames) {
            $options['discount_names'] = $this->discountNameOptions($scopeOutletIds, $params);
        }

        return $options;
    }

    private function discountNameOptions(array $scopeOutletIds, array $params): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $scopeOutletIds))));
        sort($normalizedOutletIds);

        $cacheKey = 'report-discount-options:v8i02:' . sha1(json_encode([
            'outlets' => $normalizedOutletIds,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($normalizedOutletIds, $params) {
            $base = $this->canonicalReportSalesQuery($normalizedOutletIds, [
                'date_from' => $params['date_from'] ?? null,
                'date_to' => $params['date_to'] ?? null,
            ])->where('s.discount_amount', '>', 0);

            $names = [];
            $primaryNames = (clone $base)
                ->whereNotNull('s.discount_name_snapshot')
                ->where('s.discount_name_snapshot', '<>', '')
                ->select('s.discount_name_snapshot')
                ->distinct()
                ->orderBy('s.discount_name_snapshot')
                ->limit(self::DISCOUNT_OPTION_LIMIT)
                ->pluck('s.discount_name_snapshot');

            foreach ($primaryNames as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }

            $snapshotValues = (clone $base)
                ->whereNotNull('s.discounts_snapshot')
                ->where('s.discounts_snapshot', '<>', '')
                ->select('s.discounts_snapshot')
                ->distinct()
                ->limit(self::DISCOUNT_OPTION_LIMIT)
                ->pluck('s.discounts_snapshot');

            foreach ($snapshotValues as $snapshot) {
                foreach ($this->extractDiscountNames(null, $snapshot) as $name) {
                    if ($name !== '') {
                        $names[$name] = true;
                    }
                }
            }

            ksort($names, SORT_NATURAL | SORT_FLAG_CASE);
            return array_values(array_keys($names));
        });
    }


    private function buildLedgerReport(array $params, ?string $outletId, bool $markedOnly = false): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(200, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);

        $base = $this->canonicalReportSalesQuery($scopeOutletIds, $params, $markedOnly);
        $q = (clone $base)
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->select([
                's.id as sale_id',
                'o.id as outlet_id',
                'o.code as outlet_code',
                'o.name as outlet_name',
                'o.timezone as outlet_timezone',
                's.sale_number',
                's.channel',
                's.payment_method_name as payment_method_snapshot',
                's.subtotal',
                's.discount_total',
                's.service_charge_total',
                's.grand_total',
                's.paid_total',
                's.change_total',
                's.marking',
                's.created_at',
            ])
            ->selectRaw('COALESCE(s.grand_total, 0) as total')
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id');

        $salesSummary = $this->dailySalesSummary($scopeOutletIds, $params, $scopeTimezone, $markedOnly);
        $usedDailySummary = $salesSummary !== null;
        if (! $salesSummary) {
            $salesSummary = (clone $base)
                ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)),0) as grand_total, COUNT(*) as transaction_count')
                ->first();
        }

        $paginator = $this->paginateKnownTotal($q, $perPage, $page, (int) ($salesSummary->transaction_count ?? 0));
        $pageRows = collect($paginator->items());
        $paymentMap = $this->pagePaymentMethodMap($pageRows->pluck('sale_id')->all());

        $items = $pageRows->map(function ($r) use ($paymentMap) {
            $dateText = TransactionDate::formatSaleLocal($r->created_at, $r->outlet_timezone, isset($r->sale_number) ? (string) $r->sale_number : null);
            $saleId = (string) $r->sale_id;
            $snapshot = trim((string) ($r->payment_method_snapshot ?? ''));

            return [
                'sale_id' => $saleId,
                'outlet_id' => (string) ($r->outlet_id ?? ''),
                'outlet_code' => (string) ($r->outlet_code ?? ''),
                'outlet_name' => (string) ($r->outlet_name ?? ''),
                'sale_number' => (string) ($r->sale_number ?? ''),
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => $paymentMap[$saleId] ?? ($snapshot !== '' ? $snapshot : '-'),
                'total' => (int) ($r->total ?? 0),
                'marking' => (int) ($r->marking ?? 1),
                'date' => TransactionDate::formatSaleLocal($r->created_at, $r->outlet_timezone, isset($r->sale_number) ? (string) $r->sale_number : null, 'Y-m-d'),
                'time' => TransactionDate::formatSaleLocal($r->created_at, $r->outlet_timezone, isset($r->sale_number) ? (string) $r->sale_number : null, 'H:i:s'),
                'created_at' => $dateText,
            ];
        })->values()->all();

        $itemSummary = null;
        if (! $usedDailySummary) {
            $filteredIds = (clone $base)->select('s.id')->distinct();
            $itemSummary = DB::table('sale_items as si')
                ->joinSub($filteredIds, 'filtered_sales', fn ($join) => $join->on('filtered_sales.id', '=', 'si.sale_id'))
                ->selectRaw('COALESCE(SUM(si.qty),0) as items_sold')
                ->first();
        }

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'summary' => [
                'grand_total' => (int) ($salesSummary->grand_total ?? 0),
                'transaction_count' => (int) ($salesSummary->transaction_count ?? 0),
                'items_sold' => (int) ($salesSummary->items_sold ?? $itemSummary->items_sold ?? 0),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'timezone' => $scopeTimezone,
                'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
                'query_strategy' => 'report_sale_business_dates_direct',
                'payment_decoration' => 'current_page_sale_ids_only',
                'summary_source' => $usedDailySummary ? 'report_daily_sales_summaries' : 'canonical_filtered_sales',
            ],
            'filter_options' => $this->commonReportFilterOptions($scopeOutletIds, $params),
        ];
    }

    public function ledger(array $params, ?string $outletId): array
    {
        return $this->buildLedgerReport($params, $outletId, false);
    }

    public function marking(array $params, ?string $outletId): array
    {
        return $this->buildLedgerReport($params, $outletId, true);
    }

    public function recentSales(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $saleScope = $this->resolveCachedSaleScope($scopeOutletIds, $params, $scopeTimezone);

        $q = DB::table('sales as s')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            's.id as sale_id',
            'o.code as outlet_code',
            's.sale_number',
            DB::raw('COALESCE(SUM(si.qty),0) as items_sold'),
            DB::raw('COALESCE(s.grand_total, 0) as total'),
            's.paid_total as paid',
            's.created_at',
        ])
        ->groupBy('s.id', 'o.code', 's.sale_number', 's.grand_total', 's.paid_total', 's.created_at')
        ->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) {
            return [
                'sale_id' => (string) $r->sale_id,
                'outlet_code' => (string) ($r->outlet_code ?? ''),
                'sale_number' => (string) $r->sale_number,
                'customer_name' => '-', // phase1: sales table has no customer_id
                'items_sold' => (int) ($r->items_sold ?? 0),
                'total' => (int) ($r->total ?? 0),
                'paid' => (int) ($r->paid ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at, null, isset($r->sale_number) ? (string) $r->sale_number : null),
            ];
        })->values()->all();

        return [
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
            ],
        ];
    }

    public function itemSold(array $params, ?string $outletId): array
    {
        [$fromLocal, $toLocal] = $this->resolveOutletUtcRange($params['date_from'] ?? null, $params['date_to'] ?? null, $outletId);
        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);

        return $this->itemReportReadService->itemSold(
            $params,
            $scopeOutletIds,
            $scopeTimezone,
            $fromLocal->toDateString(),
            $toLocal->toDateString(),
        );
    }

    public function itemByProduct(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);

        return $this->itemReportReadService->itemByProduct(
            $params,
            $scopeOutletIds,
            $scopeTimezone,
            $from->toDateString(),
            $to->toDateString(),
        );
    }

    public function itemByVariant(array $params, ?string $outletId): array
    {
        return $this->itemSold($params, $outletId);
    }


    private function canUseDailySalesSummary(array $params): bool
    {
        foreach (['sale_number', 'channel', 'payment_method_name', 'discount_name', 'discount_squad_nisj'] as $key) {
            if (trim((string) ($params[$key] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function dailySalesSummary(array $scopeOutletIds, array $params, string $scopeTimezone, bool $markedOnly = false): ?object
    {
        if ($scopeOutletIds === [] || ! $this->canUseDailySalesSummary($params) || ! $this->hasCompleteDailySummaryCoverage($scopeOutletIds, $params)) {
            return null;
        }

        // V8 I02: read-only hot path. I01 scheduler/CLI owns historical warm-up.
        // If coverage is incomplete, caller aggregates the canonical indexed scope
        // rather than rebuilding hundreds of dates inside the browser request.

        $trxColumn = $markedOnly ? 'marked_trx_count' : 'trx_count';
        $discountedTrxColumn = $markedOnly ? 'marked_discounted_trx_count' : 'discounted_trx_count';
        $roundingTrxColumn = $markedOnly ? 'marked_rounding_trx_count' : 'rounding_trx_count';
        $grandColumn = $markedOnly ? 'marked_grand_sales' : 'grand_sales';
        $discountColumn = $markedOnly ? 'marked_discount_total' : 'discount_total';
        $taxColumn = $markedOnly ? 'marked_tax_total' : 'tax_total';
        $roundingColumn = $markedOnly ? 'marked_rounding_total' : 'rounding_total';
        $roundingUpColumn = $markedOnly ? 'marked_rounding_up_total' : 'rounding_up_total';
        $roundingDownColumn = $markedOnly ? 'marked_rounding_down_total' : 'rounding_down_total';
        $itemQtyColumn = $markedOnly ? 'marked_item_qty_sold' : 'item_qty_sold';

        return $this->dailySummaryService
            ->salesSummaryQuery($scopeOutletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw("COALESCE(SUM(rdss.{$trxColumn}),0) as transaction_count")
            ->selectRaw("COALESCE(SUM(rdss.{$discountedTrxColumn}),0) as discounted_transaction_count")
            ->selectRaw("COALESCE(SUM(rdss.{$roundingTrxColumn}),0) as rounding_transaction_count")
            ->selectRaw("COALESCE(SUM(rdss.{$grandColumn}),0) as grand_total")
            ->selectRaw("COALESCE(SUM(rdss.{$discountColumn}),0) as discount_total")
            ->selectRaw("COALESCE(SUM(rdss.{$taxColumn}),0) as tax_total")
            ->selectRaw("COALESCE(SUM(rdss.{$roundingColumn}),0) as rounding_total")
            ->selectRaw("COALESCE(SUM(rdss.{$roundingUpColumn}),0) as rounding_up_total")
            ->selectRaw("COALESCE(SUM(rdss.{$roundingDownColumn}),0) as rounding_down_total")
            ->selectRaw("COALESCE(SUM(rdss.{$itemQtyColumn}),0) as items_sold")
            ->first();
    }

    public function rounding(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(200, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);

        $base = $this->canonicalReportSalesQuery($scopeOutletIds, $params)
            ->where('s.rounding_total', '!=', 0);

        $q = (clone $base)
            ->select([
                's.id as sale_id', 's.sale_number', 's.channel',
                's.payment_method_name as payment_method_snapshot',
                DB::raw('GREATEST(COALESCE(s.grand_total, 0) - COALESCE(s.rounding_total, 0), 0) as total_before_rounding'),
                's.rounding_total as rounding', DB::raw('COALESCE(s.grand_total, 0) as total'), 's.created_at',
            ])
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id');

        $summary = $this->dailySalesSummary($scopeOutletIds, $params, $scopeTimezone);
        if (! $summary) {
            $summary = (clone $base)
                ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(s.rounding_total),0) as rounding_total, COALESCE(SUM(CASE WHEN s.rounding_total > 0 THEN s.rounding_total ELSE 0 END),0) as rounding_up_total, COALESCE(ABS(SUM(CASE WHEN s.rounding_total < 0 THEN s.rounding_total ELSE 0 END)),0) as rounding_down_total')
                ->first();
        }
        $roundingTotal = (int) ($summary->rounding_transaction_count ?? $summary->transaction_count ?? 0);
        $p = $this->paginateKnownTotal($q, $perPage, $page, $roundingTotal);
        $pageRows = collect($p->items());
        $paymentMap = $this->pagePaymentMethodMap($pageRows->pluck('sale_id')->all());
        $items = $pageRows->map(function ($r) use ($paymentMap, $scopeTimezone) {
            $saleId = (string) $r->sale_id;
            $snapshot = trim((string) ($r->payment_method_snapshot ?? ''));
            return [
                'sale_id' => $saleId,
                'sale_number' => (string) $r->sale_number,
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => $paymentMap[$saleId] ?? ($snapshot !== '' ? $snapshot : '-'),
                'total_before_rounding' => (int) ($r->total_before_rounding ?? 0),
                'rounding' => (int) ($r->rounding ?? 0),
                'total' => (int) ($r->total ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at, $scopeTimezone, isset($r->sale_number) ? (string) $r->sale_number : null),
            ];
        })->values()->all();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'summary' => [
                'transaction_count' => (int) ($summary->rounding_transaction_count ?? $summary->transaction_count ?? 0),
                'rounding_total' => (int) ($summary->rounding_total ?? 0),
                'rounding_up_total' => (int) ($summary->rounding_up_total ?? 0),
                'rounding_down_total' => (int) ($summary->rounding_down_total ?? 0),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(),
                'timezone' => $scopeTimezone, 'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
                'query_strategy' => 'report_sale_business_dates_direct', 'payment_decoration' => 'current_page_sale_ids_only',
            ],
            'filter_options' => $this->commonReportFilterOptions($scopeOutletIds, $params),
        ];
    }

    public function tax(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(200, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $base = $this->canonicalReportSalesQuery($scopeOutletIds, $params);

        $q = (clone $base)
            ->select([
                's.id as sale_id', 's.sale_number', 's.channel',
                's.payment_method_name as payment_method_snapshot',
                DB::raw('COALESCE(s.grand_total, 0) as total'),
                DB::raw('COALESCE(s.tax_total, 0) as tax'), 's.created_at',
            ])
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id');

        $summary = $this->dailySalesSummary($scopeOutletIds, $params, $scopeTimezone);
        if (! $summary) {
            $summary = (clone $base)
                ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(COALESCE(s.grand_total, 0)),0) as grand_total, COALESCE(SUM(COALESCE(s.tax_total, 0)),0) as tax_total')
                ->first();
        }
        $p = $this->paginateKnownTotal($q, $perPage, $page, (int) ($summary->transaction_count ?? 0));
        $pageRows = collect($p->items());
        $paymentMap = $this->pagePaymentMethodMap($pageRows->pluck('sale_id')->all());
        $items = $pageRows->map(function ($r) use ($paymentMap, $scopeTimezone) {
            $saleId = (string) $r->sale_id;
            $snapshot = trim((string) ($r->payment_method_snapshot ?? ''));
            return [
                'sale_id' => $saleId,
                'sale_number' => (string) $r->sale_number,
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => $paymentMap[$saleId] ?? ($snapshot !== '' ? $snapshot : '-'),
                'total' => (int) ($r->total ?? 0),
                'tax' => (int) ($r->tax ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at, $scopeTimezone, isset($r->sale_number) ? (string) $r->sale_number : null),
            ];
        })->values()->all();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'summary' => [
                'transaction_count' => (int) ($summary->transaction_count ?? 0),
                'grand_total' => (int) ($summary->grand_total ?? 0),
                'tax_total' => (int) ($summary->tax_total ?? 0),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(),
                'timezone' => $scopeTimezone, 'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
                'query_strategy' => 'report_sale_business_dates_direct', 'payment_decoration' => 'current_page_sale_ids_only',
            ],
            'filter_options' => $this->commonReportFilterOptions($scopeOutletIds, $params),
        ];
    }

    public function discount(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(200, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);

        $base = $this->canonicalReportSalesQuery($scopeOutletIds, $params)
            ->where('s.discount_amount', '>', 0);

        if (!empty($params['discount_name'])) {
            $needle = trim((string) $params['discount_name']);
            $jsonNeedle = '%"name":"' . $needle . '"%';
            $base->where(function ($discountScope) use ($needle, $jsonNeedle): void {
                $discountScope
                    ->where('s.discount_name_snapshot', '=', $needle)
                    ->orWhere('s.discounts_snapshot', 'like', $jsonNeedle)
                    ->orWhere('s.discounts_snapshot', 'like', '%' . $needle . '%');
            });
        }
        if (!empty($params['discount_squad_nisj'])) {
            $base->where('s.discount_squad_nisj', 'like', '%' . trim((string) $params['discount_squad_nisj']) . '%');
        }

        $q = (clone $base)
            ->leftJoin('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoin('employees as de', 'de.nisj', '=', 's.discount_squad_nisj')
            ->leftJoin('users as du', 'du.nisj', '=', 's.discount_squad_nisj')
            ->select([
                's.id as sale_id', 's.sale_number', 's.outlet_id',
                DB::raw("COALESCE(o.name, o.code, '-') as outlet_name"), DB::raw("COALESCE(o.code, '') as outlet_code"),
                's.channel', 's.payment_method_name as payment_method_snapshot', DB::raw('COALESCE(s.grand_total, 0) as total'),
                's.discount_amount as discount', 's.discount_name_snapshot', 's.discounts_snapshot', 's.discount_squad_nisj',
                DB::raw("COALESCE(NULLIF(s.discount_squad_name, ''), NULLIF(de.full_name, ''), NULLIF(du.name, ''), '-') as discount_squad_full_name"),
                's.created_at',
            ])
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id');

        $summary = $this->dailySalesSummary($scopeOutletIds, $params, $scopeTimezone);
        if (! $summary) {
            $summary = (clone $base)
                ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as grand_total, COALESCE(SUM(COALESCE(s.discount_amount, 0)), 0) as discount_total')
                ->first();
        }
        $discountTotal = (int) ($summary->discounted_transaction_count ?? $summary->transaction_count ?? 0);
        $p = $this->paginateKnownTotal($q, $perPage, $page, $discountTotal);
        $pageRows = collect($p->items());
        $paymentMap = $this->pagePaymentMethodMap($pageRows->pluck('sale_id')->all());
        $items = $pageRows->map(function ($r) use ($paymentMap, $scopeTimezone) {
            $discountNames = $this->extractDiscountNames($r->discount_name_snapshot ?? null, $r->discounts_snapshot ?? null);
            $saleId = (string) $r->sale_id;
            $snapshot = trim((string) ($r->payment_method_snapshot ?? ''));
            return [
                'sale_id' => $saleId,
                'sale_number' => (string) $r->sale_number,
                'outlet_id' => (string) ($r->outlet_id ?? ''),
                'outlet_name' => (string) ($r->outlet_name ?? '-'),
                'outlet_code' => (string) ($r->outlet_code ?? ''),
                'discount_name' => !empty($discountNames) ? implode(', ', $discountNames) : '-',
                'discount_names' => $discountNames,
                'discount_squad_nisj' => (string) ($r->discount_squad_nisj ?? ''),
                'discount_squad_full_name' => (string) ($r->discount_squad_full_name ?? '-'),
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => $paymentMap[$saleId] ?? ($snapshot !== '' ? $snapshot : '-'),
                'total' => (int) ($r->total ?? 0),
                'discount' => (int) ($r->discount ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at, $scopeTimezone, isset($r->sale_number) ? (string) $r->sale_number : null),
            ];
        })->values()->all();

        $filterOptions = $this->commonReportFilterOptions($scopeOutletIds, $params, true);

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'summary' => [
                'transaction_count' => (int) ($summary->discounted_transaction_count ?? $summary->transaction_count ?? 0),
                'grand_total' => (int) ($summary->grand_total ?? 0),
                'discount_total' => (int) ($summary->discount_total ?? 0),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(),
                'timezone' => $scopeTimezone, 'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
                'query_strategy' => 'report_sale_business_dates_direct', 'payment_decoration' => 'current_page_sale_ids_only',
                'discount_options_cache_minutes' => 30,
            ],
            'filter_options' => $filterOptions,
        ];
    }

    private function formatLocalTime($value, ?string $timezone = null): ?string
    {
        return TransactionDate::formatSaleLocal($value, $timezone, null, 'H:i');
    }

    private function rawCreatedAtValue($value)
    {
        if ($value instanceof Sale) {
            if (method_exists($value, 'getRawOriginal')) {
                $raw = $value->getRawOriginal('created_at');
                if ($raw !== null && $raw !== '') {
                    return $raw;
                }
            }

            if ($value->created_at) {
                return $value->created_at;
            }
        }

        return $value;
    }

    private function decodeDiscountSnapshot($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($row) => is_array($row)));
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values(array_filter($decoded, fn ($row) => is_array($row))) : [];
        }

        return [];
    }

    private function extractDiscountNames($discountNameSnapshot, $discountsSnapshot): array
    {
        $names = [];
        $primary = trim((string) ($discountNameSnapshot ?? ''));
        if ($primary !== '') {
            $names[$primary] = true;
        }

        foreach ($this->decodeDiscountSnapshot($discountsSnapshot) as $snapshot) {
            $name = trim((string) ($snapshot['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_values(array_keys($names));
    }

    private function joinDiscountNames($discountNameSnapshot, $discountsSnapshot): string
    {
        $names = $this->extractDiscountNames($discountNameSnapshot, $discountsSnapshot);

        return !empty($names) ? implode(', ', $names) : '-';
    }

    private function resolveSalePaymentMethodName(Sale $sale, $payment = null): string
    {
        $candidate = null;

        if ($payment) {
            $candidate = $payment->paymentMethod->name ?? null;
            if (!$candidate) {
                $candidate = $payment->payment_method_name ?? null;
            }
        }

        if (!$candidate) {
            $candidate = $sale->payment_method_name ?? null;
        }

        return (string) ($candidate ?: '-');
    }

    private function isCashPaymentMethodName(?string $name): bool
    {
        $normalized = mb_strtolower(trim((string) $name));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'cash') || str_contains($normalized, 'tunai');
    }

    private function resolvePaymentSnapshotAmount(Sale $sale, $payment = null): int
    {
        $paymentName = $this->resolveSalePaymentMethodName($sale, $payment);
        $amount = (int) ($payment?->amount ?? 0);
        $changeTotal = max(0, (int) ($sale->change_total ?? 0));

        if ($payment && $amount > 0) {
            if ($this->isCashPaymentMethodName($paymentName) && $changeTotal > 0) {
                return max(0, $amount - $changeTotal);
            }

            return $amount;
        }

        if (($sale->grand_total ?? 0) > 0) {
            return (int) $sale->grand_total;
        }

        $paidTotal = (int) ($sale->paid_total ?? 0);
        if ($this->isCashPaymentMethodName($paymentName) && $changeTotal > 0) {
            return max(0, $paidTotal - $changeTotal);
        }

        return $paidTotal;
    }

    private function summarizePaymentMethods($sales): array
    {
        $totals = [];

        foreach ($sales as $sale) {
            $payments = $sale->payments ?? collect();
            if ($payments instanceof \Illuminate\Support\Collection && $payments->isNotEmpty()) {
                foreach ($payments as $payment) {
                    $name = $this->resolveSalePaymentMethodName($sale, $payment);
                    if (!isset($totals[$name])) {
                        $totals[$name] = [
                            'name' => $name,
                            'total' => 0,
                            'transaction_count' => 0,
                        ];
                    }
                    $totals[$name]['total'] += $this->resolvePaymentSnapshotAmount($sale, $payment);
                    $totals[$name]['transaction_count'] += 1;
                }
                continue;
            }

            $name = $this->resolveSalePaymentMethodName($sale, null);
            if (!isset($totals[$name])) {
                $totals[$name] = [
                    'name' => $name,
                    'total' => 0,
                    'transaction_count' => 0,
                ];
            }
            $totals[$name]['total'] += $this->resolvePaymentSnapshotAmount($sale, null);
            $totals[$name]['transaction_count'] += 1;
        }

        return collect($totals)
            ->sortByDesc('total')
            ->values()
            ->map(fn ($row) => [
                'name' => (string) ($row['name'] ?? '-'),
                'total' => (int) ($row['total'] ?? 0),
                'transaction_count' => (int) ($row['transaction_count'] ?? 0),
            ])
            ->all();
    }


    private function cashierReportAdjustmentRequests(array $params, ?string $outletId, array $window, string $timezone, array $scopeOutletIds, bool $withItems = true)
    {
        $relations = ['sale.payments.paymentMethod', 'sale.outlet'];
        if ($withItems) {
            $relations[] = 'sale.items';
        }

        $query = SaleCancelRequest::query()
            ->with($relations)
            ->where('status', SaleCancelRequest::STATUS_APPROVED)
            ->whereIn('request_type', [SaleCancelRequest::REQUEST_TYPE_CANCEL, SaleCancelRequest::REQUEST_TYPE_VOID])
            ->whereHas('sale', function ($saleQuery) use ($params, $outletId, $window, $timezone, $scopeOutletIds) {
                $this->applyCashierHistoricalScope(
                    $saleQuery,
                    $scopeOutletIds,
                    $outletId,
                    $window,
                    $timezone,
                    'sales.id',
                    'sale_number',
                    'created_at',
                );

                if (count($scopeOutletIds) === 1) {
                    $saleQuery->where('outlet_id', '=', $scopeOutletIds[0]);
                } elseif (count($scopeOutletIds) > 1) {
                    $saleQuery->whereIn('outlet_id', $scopeOutletIds);
                } elseif (!empty($outletId)) {
                    $saleQuery->where('outlet_id', '=', $outletId);
                }

                if (!empty($params['cashier_id'])) {
                    if ((string) $params['cashier_id'] === 'unknown') {
                        $saleQuery->whereNull('cashier_id');
                    } else {
                        $saleQuery->where('cashier_id', '=', $params['cashier_id']);
                    }
                }
            })
            ->orderBy('created_at');

        $requests = $query->get();

        if ($this->isMakassarCashierBusinessTimezone($timezone)) {
            $requests = $requests
                ->filter(fn (SaleCancelRequest $request) => $request->sale && $this->saleFallsWithinCashierBusinessWindow($request->sale, $window['from_local'], $window['to_exclusive_local'], $timezone))
                ->values();
        }

        return $requests;
    }

    private function voidRequestAmount(SaleCancelRequest $request): int
    {
        $snapshot = is_array($request->void_items_snapshot ?? null) ? $request->void_items_snapshot : [];
        return (int) collect($snapshot)->sum(fn ($item) => (int) round((float) ($item['line_total'] ?? $item['total'] ?? 0)));
    }

    private function saleAmountAttribute(Sale $sale, array $names, int $fallback = 0): int
    {
        foreach ($names as $name) {
            $value = $sale->getAttribute($name);
            if ($value !== null && $value !== '') {
                return (int) round((float) $value);
            }
        }

        return $fallback;
    }

    private function proportionalReportAmount(int $originalAmount, float $ratio): int
    {
        if ($originalAmount <= 0 || $ratio <= 0) {
            return 0;
        }

        return max(0, (int) round($originalAmount * max(0, min(1, $ratio))));
    }

    private function roundedVoidSaleTotals(Sale $sale, int $remainingSubtotal): array
    {
        $originalSubtotal = max(0, $this->saleAmountAttribute($sale, ['subtotal', 'sub_total']));
        if ($originalSubtotal <= 0 && $sale->relationLoaded('items')) {
            $originalSubtotal = max(0, (int) $sale->items->sum(fn ($item) => (int) ($item->line_total ?? 0)));
        }

        $remainingSubtotal = max(0, $remainingSubtotal);
        if ($originalSubtotal > 0) {
            $remainingSubtotal = min($originalSubtotal, $remainingSubtotal);
        }

        $ratio = $originalSubtotal > 0
            ? max(0, min(1, $remainingSubtotal / max(1, $originalSubtotal)))
            : ($remainingSubtotal > 0 ? 1.0 : 0.0);

        $discountTotal = min($remainingSubtotal, $this->proportionalReportAmount(max(0, $this->saleAmountAttribute($sale, ['discount_total', 'discount_amount'])), $ratio));
        $taxTotal = $this->proportionalReportAmount(max(0, $this->saleAmountAttribute($sale, ['tax_total', 'tax_amount'])), $ratio);
        $serviceChargeTotal = $this->proportionalReportAmount(max(0, $this->saleAmountAttribute($sale, ['service_charge_total', 'service_charge_amount'])), $ratio);
        $beforeRounding = max(0, $remainingSubtotal - $discountTotal + $taxTotal + $serviceChargeTotal);
        $rounding = SaleRounding::apply($beforeRounding);

        return [
            'subtotal' => $remainingSubtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'service_charge_total' => $serviceChargeTotal,
            'total_before_rounding' => (int) ($rounding['before_rounding'] ?? $beforeRounding),
            'rounding_total' => (int) ($rounding['rounding_total'] ?? 0),
            'grand_total' => (int) ($rounding['after_rounding'] ?? $beforeRounding),
        ];
    }

    private function voidRequestFinancialAmount(SaleCancelRequest $request): int
    {
        $voidSubtotal = $this->voidRequestAmount($request);
        if ($voidSubtotal <= 0 || ! $request->sale) {
            return $voidSubtotal;
        }

        $sale = $request->sale;
        $originalGrand = max(0, $this->saleAmountAttribute($sale, ['grand_total']));
        if ($originalGrand <= 0) {
            return $voidSubtotal;
        }

        $originalSubtotal = max(0, $this->saleAmountAttribute($sale, ['subtotal', 'sub_total']));
        if ($originalSubtotal <= 0 && $sale->relationLoaded('items')) {
            $originalSubtotal = max(0, (int) $sale->items->sum(fn ($item) => (int) ($item->line_total ?? 0)));
        }

        if ($originalSubtotal <= 0) {
            return min($originalGrand, $voidSubtotal);
        }

        $totals = $this->roundedVoidSaleTotals($sale, max(0, $originalSubtotal - min($originalSubtotal, $voidSubtotal)));

        return max(0, min($originalGrand, $originalGrand - (int) ($totals['grand_total'] ?? 0)));
    }

    private function approvedVoidAmountsBySale($requests): array
    {
        $totals = [];

        foreach ($requests as $request) {
            if ((string) ($request->request_type ?? '') !== SaleCancelRequest::REQUEST_TYPE_VOID) {
                continue;
            }

            $saleId = (string) ($request->sale_id ?? $request->sale?->id ?? '');
            if ($saleId === '') {
                continue;
            }

            $amount = $this->voidRequestAmount($request);
            if ($amount <= 0) {
                continue;
            }

            $totals[$saleId] = ($totals[$saleId] ?? 0) + $amount;
        }

        return $totals;
    }

    private function voidSnapshotQtyByItemId(SaleCancelRequest $request): array
    {
        $qty = [];
        $snapshot = is_array($request->void_items_snapshot ?? null) ? $request->void_items_snapshot : [];

        foreach ($snapshot as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = (string) ($item['id'] ?? $item['sale_item_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $qty[$id] = ($qty[$id] ?? 0) + max(1, (int) ($item['qty'] ?? 1));
        }

        return $qty;
    }

    private function saleHasMaterializedVoidRows(Sale $sale): bool
    {
        if (! $sale->relationLoaded('items')) {
            return false;
        }

        return $sale->items->contains(fn ($item) => ! is_null($item->voided_at ?? null));
    }

    private function approvedVoidQtyBySaleAndItem($requests): array
    {
        $totals = [];

        foreach ($requests as $request) {
            if ((string) ($request->request_type ?? '') !== SaleCancelRequest::REQUEST_TYPE_VOID) {
                continue;
            }

            $saleId = (string) ($request->sale_id ?? $request->sale?->id ?? '');
            if ($saleId === '') {
                continue;
            }

            foreach ($this->voidSnapshotQtyByItemId($request) as $itemId => $qty) {
                $totals[$saleId][$itemId] = ($totals[$saleId][$itemId] ?? 0) + $qty;
            }
        }

        return $totals;
    }

    private function applyApprovedVoidAdjustmentsToCashierSales($sales, $requests)
    {
        $voidAmounts = $this->approvedVoidAmountsBySale($requests);
        $voidQtyBySale = $this->approvedVoidQtyBySaleAndItem($requests);

        if (empty($voidAmounts) && empty($voidQtyBySale)) {
            return $sales;
        }

        return $sales->map(function (Sale $sale) use ($voidAmounts, $voidQtyBySale) {
            // Newer approve-void flow physically splits sale_items into:
            // - remaining paid qty with normal price
            // - voided qty with unit_price/line_total = 0
            // In that case the sale totals coming from server are already net.
            // Do not subtract void snapshot again in cashier report.
            if ($this->saleHasMaterializedVoidRows($sale)) {
                return $sale;
            }

            $saleId = (string) $sale->id;
            $originalGrand = max(0, (int) ($sale->grand_total ?? 0));
            $originalSubtotal = max(0, $this->saleAmountAttribute($sale, ['subtotal', 'sub_total']));
            if ($originalSubtotal <= 0 && $sale->relationLoaded('items')) {
                $originalSubtotal = max(0, (int) $sale->items->sum(fn ($item) => (int) ($item->line_total ?? 0)));
            }
            $voidSubtotal = max(0, (int) ($voidAmounts[$saleId] ?? 0));
            $voidSubtotal = $originalSubtotal > 0 ? min($originalSubtotal, $voidSubtotal) : min($originalGrand, $voidSubtotal);
            $totals = $this->roundedVoidSaleTotals($sale, max(0, $originalSubtotal - $voidSubtotal));
            $remainingGrand = max(0, (int) ($totals['grand_total'] ?? 0));
            $voidAmount = max(0, min($originalGrand, $originalGrand - $remainingGrand));

            if ($voidSubtotal > 0 || $voidAmount > 0) {
                $originalPaymentAmounts = [];
                $payments = $sale->payments ?? collect();
                if ($payments instanceof \Illuminate\Support\Collection && $payments->isNotEmpty()) {
                    foreach ($payments as $payment) {
                        $originalPaymentAmounts[(string) $payment->id] = $this->resolvePaymentSnapshotAmount($sale, $payment);
                    }
                    $originalPaymentTotal = max(0, array_sum($originalPaymentAmounts));
                    $ratio = $originalPaymentTotal > 0 ? max(0, min(1, $remainingGrand / $originalPaymentTotal)) : 0;
                    $allocated = 0;
                    $lastIndex = $payments->count() - 1;
                    foreach ($payments->values() as $index => $payment) {
                        $base = max(0, (int) ($originalPaymentAmounts[(string) $payment->id] ?? 0));
                        $amount = $index === $lastIndex ? max(0, $remainingGrand - $allocated) : max(0, (int) round($base * $ratio));
                        $allocated += $amount;
                        $payment->setAttribute('amount', $amount);
                    }
                }

                $sale->setAttribute('report_original_grand_total', $originalGrand);
                $sale->setAttribute('report_void_total', $voidAmount);
                $sale->setAttribute('report_void_item_total', $voidSubtotal);
                $sale->setAttribute('subtotal', (int) ($totals['subtotal'] ?? 0));
                $sale->setAttribute('sub_total', (int) ($totals['subtotal'] ?? 0));
                $sale->setAttribute('discount_total', (int) ($totals['discount_total'] ?? 0));
                $sale->setAttribute('discount_amount', (int) ($totals['discount_total'] ?? 0));
                $sale->setAttribute('tax_total', (int) ($totals['tax_total'] ?? 0));
                $sale->setAttribute('tax_amount', (int) ($totals['tax_total'] ?? 0));
                $sale->setAttribute('service_charge_total', (int) ($totals['service_charge_total'] ?? 0));
                $sale->setAttribute('service_charge_amount', (int) ($totals['service_charge_total'] ?? 0));
                $sale->setAttribute('total_before_rounding', (int) ($totals['total_before_rounding'] ?? 0));
                $sale->setAttribute('rounding_total', (int) ($totals['rounding_total'] ?? 0));
                $sale->setAttribute('rounding_amount', (int) ($totals['rounding_total'] ?? 0));
                $sale->setAttribute('grand_total', $remainingGrand);
                $sale->setAttribute('paid_total', $remainingGrand);
                $sale->setAttribute('change_total', 0);
            }

            $voidQty = $voidQtyBySale[$saleId] ?? [];
            if (! empty($voidQty) && $sale->relationLoaded('items')) {
                $sale->setRelation('items', $sale->items->map(function ($item) use (&$voidQty) {
                    $itemId = (string) ($item->id ?? '');
                    $voidedQty = max(0, (int) ($voidQty[$itemId] ?? 0));
                    if ($itemId === '' || $voidedQty <= 0) {
                        return $item;
                    }

                    $originalQty = max(0, (int) ($item->qty ?? 0));
                    $remainingQty = max(0, $originalQty - $voidedQty);
                    $unitPrice = max(0, (int) ($item->unit_price ?? 0));
                    $item->setAttribute('report_original_qty', $originalQty);
                    $item->setAttribute('report_void_qty', min($originalQty, $voidedQty));
                    $item->setAttribute('qty', $remainingQty);
                    $item->setAttribute('line_total', $remainingQty * $unitPrice);
                    return $item;
                }));
            }

            return $sale;
        });
    }

    private function summarizeCancelVoidPaymentMethods($requests): array
    {
        $totals = [];

        foreach ($requests as $request) {
            $sale = $request->sale;
            if (! $sale) {
                continue;
            }

            $type = (string) ($request->request_type ?? SaleCancelRequest::REQUEST_TYPE_CANCEL);
            $kind = $type === SaleCancelRequest::REQUEST_TYPE_VOID ? 'void_bill' : 'cancel_bill';
            $prefix = $kind === 'void_bill' ? 'Void' : 'Cancel Bill';
            $requestAmount = $kind === 'void_bill' ? $this->voidRequestFinancialAmount($request) : null;
            if ($kind === 'void_bill' && $requestAmount <= 0) {
                continue;
            }

            $payments = $sale->payments ?? collect();
            if ($payments instanceof \Illuminate\Support\Collection && $payments->isNotEmpty()) {
                foreach ($payments as $payment) {
                    $name = $this->resolveSalePaymentMethodName($sale, $payment);
                    $baseAmount = $this->resolvePaymentSnapshotAmount($sale, $payment);
                    $amount = $kind === 'void_bill' ? min($requestAmount, max(0, (int) $baseAmount)) : $baseAmount;
                    if ($amount <= 0) {
                        continue;
                    }
                    $key = $kind . ':' . $name;
                    if (!isset($totals[$key])) {
                        $totals[$key] = [
                            'name' => $prefix . ' - ' . $name,
                            'payment_method_name' => $name,
                            'total' => 0,
                            'transaction_count' => 0,
                            'kind' => $kind,
                        ];
                    }
                    $totals[$key]['total'] += $amount;
                    $totals[$key]['transaction_count'] += 1;
                }
                continue;
            }

            $name = $this->resolveSalePaymentMethodName($sale, null);
            $amount = $kind === 'void_bill' ? $requestAmount : $this->resolvePaymentSnapshotAmount($sale, null);
            if ($amount <= 0) {
                continue;
            }
            $key = $kind . ':' . $name;
            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'name' => $prefix . ' - ' . $name,
                    'payment_method_name' => $name,
                    'total' => 0,
                    'transaction_count' => 0,
                    'kind' => $kind,
                ];
            }
            $totals[$key]['total'] += $amount;
            $totals[$key]['transaction_count'] += 1;
        }

        return collect($totals)
            ->sortBy(fn ($row) => ($row['kind'] ?? '') . ':' . ($row['payment_method_name'] ?? ''))
            ->values()
            ->map(fn ($row) => [
                'name' => (string) ($row['name'] ?? '-'),
                'payment_method_name' => (string) ($row['payment_method_name'] ?? '-'),
                'total' => (int) ($row['total'] ?? 0),
                'transaction_count' => (int) ($row['transaction_count'] ?? 0),
                'kind' => (string) ($row['kind'] ?? 'adjustment'),
                'is_adjustment' => true,
                'tone' => 'danger',
            ])
            ->all();
    }

    private function saleLocalIsoForSorting($sale, ?string $timezone = null): ?string
    {
        if (!$sale) {
            return null;
        }

        return TransactionDate::toSaleIso(
            $this->rawCreatedAtValue($sale),
            $timezone,
            $sale?->sale_number ? (string) $sale->sale_number : null
        );
    }

    private function summarizeCashierGroup($group, ?string $timezone = null): array
    {
        $sorted = collect($group)
            ->sortBy(fn ($sale) => $this->saleLocalIsoForSorting($sale, $timezone) ?: (string) ($this->rawCreatedAtValue($sale) ?? ''))
            ->values();
        $first = $sorted->first();
        $last = $sorted->last();

        $firstLocalIso = $this->saleLocalIsoForSorting($first, $timezone);
        $lastLocalIso = $this->saleLocalIsoForSorting($last, $timezone);

        return [
            'cashier_id' => $first?->cashier_id ? (string) $first->cashier_id : 'unknown',
            'cashier_name' => (string) ($first?->cashier_name ?? 'Unknown Cashier'),
            'transaction_count' => $sorted->count(),
            'grand_total' => (int) $sorted->sum('grand_total'),
            'paid_total' => (int) $sorted->sum('paid_total'),
            'items_sold' => (int) $sorted->sum(fn ($sale) => $sale->items->sum('qty')),
            'first_transaction_at' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone) : null,
            'first_transaction_date' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone, 'Y-m-d') : null,
            'first_transaction_time' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone, 'H:i') : null,
            'last_transaction_at' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone) : null,
            'last_transaction_date' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone, 'Y-m-d') : null,
            'last_transaction_time' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone, 'H:i') : null,
            'payment_methods' => $this->summarizePaymentMethods($sorted),
        ];
    }

    private function summarizeCashierGroupLite($group, array $itemQtyBySale, ?string $timezone = null): array
    {
        $sorted = collect($group)
            ->sortBy(fn ($sale) => $this->saleLocalIsoForSorting($sale, $timezone) ?: (string) ($this->rawCreatedAtValue($sale) ?? ''))
            ->values();
        $first = $sorted->first();
        $last = $sorted->last();

        $firstLocalIso = $this->saleLocalIsoForSorting($first, $timezone);
        $lastLocalIso = $this->saleLocalIsoForSorting($last, $timezone);

        return [
            'cashier_id' => $first?->cashier_id ? (string) $first->cashier_id : 'unknown',
            'cashier_name' => (string) ($first?->cashier_name ?? 'Unknown Cashier'),
            'transaction_count' => $sorted->count(),
            'grand_total' => (int) $sorted->sum('grand_total'),
            'paid_total' => (int) $sorted->sum('paid_total'),
            'items_sold' => (int) $sorted->sum(fn ($sale) => (int) ($itemQtyBySale[(string) $sale->id] ?? 0)),
            'first_transaction_at' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone) : null,
            'first_transaction_date' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone, 'Y-m-d') : null,
            'first_transaction_time' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone, 'H:i') : null,
            'last_transaction_at' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone) : null,
            'last_transaction_date' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone, 'Y-m-d') : null,
            'last_transaction_time' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone, 'H:i') : null,
            'payment_methods' => $this->summarizePaymentMethods($sorted),
        ];
    }

    private function isMakassarCashierBusinessTimezone(?string $timezone = null): bool
    {
        return TransactionDate::normalizeTimezone($timezone, $this->currentTimezone()) === 'Asia/Makassar';
    }

    private function resolveCashierReportBusinessToday(?string $timezone = null): string
    {
        $tz = TransactionDate::normalizeTimezone($timezone, $this->currentTimezone());
        $now = CarbonImmutable::now($tz);

        if ($this->isMakassarCashierBusinessTimezone($tz)) {
            return $now->subHour()->toDateString();
        }

        return $now->toDateString();
    }

    private function resolveCashierReportBusinessWindow(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $tz = TransactionDate::normalizeTimezone($timezone, $this->currentTimezone());
        $today = CarbonImmutable::parse($this->resolveCashierReportBusinessToday($tz), $tz)->startOfDay();

        try {
            $requestedFrom = $dateFrom ? CarbonImmutable::parse($dateFrom, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $requestedFrom = $today;
        }

        try {
            $requestedTo = $dateTo ? CarbonImmutable::parse($dateTo, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $requestedTo = $today;
        }

        if ($requestedTo->lessThan($requestedFrom)) {
            [$requestedFrom, $requestedTo] = [$requestedTo, $requestedFrom];
        }

        if ($this->isMakassarCashierBusinessTimezone($tz)) {
            $fromLocal = $requestedFrom->addHour();
            $toExclusiveLocal = $requestedTo->addDay()->addHour();
        } else {
            $fromLocal = $requestedFrom->startOfDay();
            $toExclusiveLocal = $requestedTo->addDay()->startOfDay();
        }

        return [
            'timezone' => $tz,
            'requested_from' => $requestedFrom,
            'requested_to' => $requestedTo,
            'from_local' => $fromLocal,
            'to_exclusive_local' => $toExclusiveLocal,
            'to_inclusive_local' => $toExclusiveLocal->subSecond(),
        ];
    }

    private function saleLocalMomentForCashierWindow(Sale $sale, ?string $timezone = null): ?CarbonImmutable
    {
        $localIso = $this->saleLocalIsoForSorting($sale, $timezone);
        if (!$localIso) {
            return null;
        }

        try {
            return CarbonImmutable::parse($localIso);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function saleFallsWithinCashierBusinessWindow(Sale $sale, CarbonImmutable $fromLocal, CarbonImmutable $toExclusiveLocal, ?string $timezone = null): bool
    {
        $moment = $this->saleLocalMomentForCashierWindow($sale, $timezone);
        if (!$moment) {
            return false;
        }

        return $moment->greaterThanOrEqualTo($fromLocal) && $moment->lessThan($toExclusiveLocal);
    }

    private function normalizeCashierReportParams(array $params, ?string $outletId = null): array
    {
        if (!empty($params['date']) && empty($params['date_from']) && empty($params['date_to'])) {
            $params['date_from'] = $params['date'];
            $params['date_to'] = $params['date'];
        }

        if (empty($params['date_from']) && empty($params['date_to'])) {
            $today = $this->resolveCashierReportBusinessToday($this->resolveTimezone($outletId));
            $params['date_from'] = $today;
            $params['date_to'] = $today;
        } elseif (empty($params['date_from']) && !empty($params['date_to'])) {
            $params['date_from'] = $params['date_to'];
        } elseif (empty($params['date_to']) && !empty($params['date_from'])) {
            $params['date_to'] = $params['date_from'];
        }

        return $params;
    }

    private function transformCashierReportSaleWithTimezone(Sale $sale, ?string $timezone = null): array
    {
        return [
            'id' => (string) $sale->id,
            'sale_number' => (string) $sale->sale_number,
            'channel' => (string) ($sale->channel ?? '-'),
            'online_order_source' => (string) ($sale->online_order_source ?? ''),
            'status' => (string) ($sale->status ?? '-'),
            'cashier_id' => $sale->cashier_id ? (string) $sale->cashier_id : null,
            'cashier_name' => (string) ($sale->cashier_name ?? '-'),
            'paid_at' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number),
            'transaction_date' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number, 'Y-m-d'),
            'time_only' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number, 'H:i'),
            'created_at' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number),
            'subtotal' => (int) ($sale->subtotal ?? 0),
            'discount_total' => (int) ($sale->discount_total ?? 0),
            'tax_total' => (int) ($sale->tax_total ?? 0),
            'service_charge_total' => (int) ($sale->service_charge_total ?? 0),
            'rounding_total' => (int) ($sale->rounding_total ?? 0),
            'total_before_rounding' => (int) ($sale->total_before_rounding ?? max(0, (int) ($sale->grand_total ?? 0) - (int) ($sale->rounding_total ?? 0))),
            'grand_total' => (int) ($sale->grand_total ?? 0),
            'paid_total' => (int) ($sale->paid_total ?? 0),
            'void_total' => (int) ($sale->report_void_total ?? 0),
            'void_items_total' => (int) ($sale->report_void_item_total ?? 0),
            'original_grand_total' => (int) ($sale->report_original_grand_total ?? $sale->grand_total ?? 0),
            'change_total' => (int) ($sale->change_total ?? 0),
            'payment_method_type' => (string) ($sale->payment_method_type ?? ''),
            'payment_method_name' => $this->resolveSalePaymentMethodName($sale),
            'payments' => $sale->payments->map(fn ($payment) => [
                'id' => (string) $payment->id,
                'payment_method_id' => $payment->payment_method_id ? (string) $payment->payment_method_id : null,
                'payment_method_name' => $this->resolveSalePaymentMethodName($sale, $payment),
                'amount' => $this->resolvePaymentSnapshotAmount($sale, $payment),
                'reference' => $payment->reference,
            ])->values()->all(),
            'items' => $sale->items->map(function ($item) {
                return [
                    'id' => (string) $item->id,
                    'channel' => (string) ($item->channel ?? '-'),
                    'product_name' => (string) ($item->product_name ?? ''),
                    'variant_name' => (string) ($item->variant_name ?? ''),
                    'note' => $item->note,
                    'qty' => (int) ($item->qty ?? 0),
                    'unit_price' => (int) ($item->unit_price ?? 0),
                    'line_total' => (int) ($item->line_total ?? 0),
                ];
            })->values()->all(),
        ];

        return DeliveryNoTaxReadModel::normalizeSaleArray($payload);
    }

    private function transformCashierReportSale(Sale $sale): array
    {
        return $this->transformCashierReportSaleWithTimezone($sale);
    }

    public function cashierReport(array $params, ?string $outletId): array
    {
        $params = $this->normalizeCashierReportParams($params, $outletId);
        $scopeOutletIds = array_values(array_filter(array_map('strval', $params['scope_outlet_ids'] ?? [])));
        $timezone = !empty($params['scope_timezone']) ? (string) $params['scope_timezone'] : $this->resolveTimezone($outletId);
        $window = $this->resolveCashierReportBusinessWindow(
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $timezone
        );

        $salesQuery = Sale::query()
            ->with(['items', 'payments.paymentMethod'])
            ->where('status', '=', 'PAID');

        $scopeStrategy = $this->applyCashierHistoricalScope(
            $salesQuery,
            $scopeOutletIds,
            $outletId,
            $window,
            $timezone,
        );

        $salesQuery
            ->orderBy('created_at')
            ->orderBy('sale_number');

        if (count($scopeOutletIds) === 1) {
            $salesQuery->where('outlet_id', '=', $scopeOutletIds[0]);
        } elseif (count($scopeOutletIds) > 1) {
            $salesQuery->whereIn('outlet_id', $scopeOutletIds);
        } elseif (!empty($outletId)) {
            $salesQuery->where('outlet_id', '=', $outletId);
        }

        if (!empty($params['cashier_id'])) {
            if ((string) $params['cashier_id'] === 'unknown') {
                $salesQuery->whereNull('cashier_id');
            } else {
                $salesQuery->where('cashier_id', '=', $params['cashier_id']);
            }
        }

        $sales = $salesQuery->get();

        if ($this->isMakassarCashierBusinessTimezone($timezone)) {
            $sales = $sales
                ->filter(fn (Sale $sale) => $this->saleFallsWithinCashierBusinessWindow($sale, $window['from_local'], $window['to_exclusive_local'], $timezone))
                ->values();
        }

        $adjustmentRequests = $this->cashierReportAdjustmentRequests($params, $outletId, $window, $timezone, $scopeOutletIds);
        $sales = $this->applyApprovedVoidAdjustmentsToCashierSales($sales, $adjustmentRequests);

        $summary = [
            'transaction_count' => $sales->count(),
            'grand_total' => (int) $sales->sum('grand_total'),
            'paid_total' => (int) $sales->sum('paid_total'),
            'change_total' => (int) $sales->sum('change_total'),
            'items_sold' => (int) $sales->sum(fn ($sale) => $sale->items->sum('qty')),
            'payment_methods' => [
                ...$this->summarizePaymentMethods($sales),
                ...$this->summarizeCancelVoidPaymentMethods($adjustmentRequests),
            ],
        ];

        $cashiers = $sales
            ->groupBy(fn ($sale) => $sale->cashier_id ?: 'unknown')
            ->map(fn ($group) => $this->summarizeCashierGroup($group, $timezone))
            ->values()
            ->sortBy('cashier_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $cashier = null;
        if (!empty($params['cashier_id'])) {
            $selected = $sales->groupBy(fn ($sale) => $sale->cashier_id ?: 'unknown')->first();
            $cashier = $selected ? $this->summarizeCashierGroup($selected, $timezone) : [
                'cashier_id' => (string) $params['cashier_id'],
                'cashier_name' => 'Unknown Cashier',
                'transaction_count' => 0,
                'grand_total' => 0,
                'paid_total' => 0,
                'items_sold' => 0,
                'first_transaction_at' => null,
                'first_transaction_date' => null,
                'first_transaction_time' => null,
                'last_transaction_at' => null,
                'last_transaction_date' => null,
                'last_transaction_time' => null,
                'payment_methods' => [],
            ];
        }

        return [
            'range' => [
                'date_from' => $window['requested_from']->toDateString(),
                'date_to' => $window['requested_to']->toDateString(),
                'date' => $window['requested_from']->toDateString(),
            ],
            'cashier' => $cashier,
            'summary' => $summary,
            'cashiers' => $cashiers,
            'sales' => $sales->map(fn (Sale $sale) => $this->transformCashierReportSaleWithTimezone($sale, $timezone))->values()->all(),
            'meta' => [
                'reporting_source' => [
                    'contract' => 'erp_finance_v8_i04',
                    'query_strategy' => $scopeStrategy,
                    'business_date_source' => $scopeStrategy === 'report_sale_business_dates_covered' ? 'report_sale_business_dates' : 'TransactionDate exact fallback',
                    'http_backfill' => false,
                ],
            ],
        ];
    }

    /**
     * Lightweight Cashier Report source for Finance Reconciliation.
     *
     * IMPORTANT: this deliberately reuses the exact same business-window,
     * timezone, outlet scope, approved cancel/void adjustment helpers as
     * cashierReport(). It only removes data that Reconciliation never consumes:
     * sale items, cashier grouping and the full report presentation payload.
     */
    public function cashierReconciliationSnapshot(array $params, ?string $outletId): array
    {
        $params = $this->normalizeCashierReportParams($params, $outletId);
        $scopeOutletIds = array_values(array_filter(array_map('strval', $params['scope_outlet_ids'] ?? [])));
        $timezone = !empty($params['scope_timezone']) ? (string) $params['scope_timezone'] : $this->resolveTimezone($outletId);
        $window = $this->resolveCashierReportBusinessWindow(
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $timezone
        );

        $salesQuery = Sale::query()
            ->with(['payments.paymentMethod'])
            ->where('status', '=', 'PAID')
            // Keep this list limited to physical columns that exist in the canonical sales table.
            // Derived/report-only fields are resolved from relations or calculated in memory.
            ->select([
                'id','sale_number','outlet_id','cashier_id','status','created_at',
                'subtotal','discount_total','tax_total','service_charge_total','rounding_total',
                'grand_total','paid_total','change_total','marking',
            ]);

        // V8 I04: read the canonical business-date index directly when coverage
        // is already fresh. Unlike the legacy scope-cache path, this never calls
        // ensureCoverage() and never copies a historical sale-id set into a temp
        // scope table during an HTTP request.
        $scopeStrategy = $this->applyCashierHistoricalScope(
            $salesQuery,
            $scopeOutletIds,
            $outletId,
            $window,
            $timezone,
        );

        if (count($scopeOutletIds) === 1) {
            $salesQuery->where('outlet_id', '=', $scopeOutletIds[0]);
        } elseif (count($scopeOutletIds) > 1) {
            $salesQuery->whereIn('outlet_id', $scopeOutletIds);
        } elseif (!empty($outletId)) {
            $salesQuery->where('outlet_id', '=', $outletId);
        }

        $sales = $salesQuery->orderBy('created_at')->orderBy('sale_number')->get();

        // Preserve the pre-I04 Makassar boundary guard even when the read-only
        // canonical index is unavailable and the exact candidate fallback is used.
        // Applying it on the indexed path is harmless and gives an extra parity
        // assertion around the 01:00 business-day cutoff.
        if ($this->isMakassarCashierBusinessTimezone($timezone)) {
            $sales = $sales
                ->filter(fn (Sale $sale) => $this->saleFallsWithinCashierBusinessWindow(
                    $sale, $window['from_local'], $window['to_exclusive_local'], $timezone
                ))
                ->values();
        }

        // cashierReport() loads sale_items for every transaction mainly to detect
        // materialized void rows. Reconciliation does not need item payloads, so
        // detect only the exceptional sales with a single indexed query.
        $saleIds = $sales->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
        if ($saleIds && \Illuminate\Support\Facades\Schema::hasColumn('sale_items', 'voided_at')) {
            $materializedVoidIds = DB::table('sale_items')->whereIn('sale_id', $saleIds)->whereNotNull('voided_at')->distinct()->pluck('sale_id')->map(fn ($id) => (string) $id)->flip();
            foreach ($sales as $sale) {
                if ($materializedVoidIds->has((string) $sale->id)) {
                    $sale->setRelation('items', collect([(object) ['id' => '', 'voided_at' => now()]]));
                }
            }
        }

        // saleScope above is already exact for the outlet business date/timezone.

        // Keep the exact approved CANCEL/VOID business logic used by cashierReport().
        $adjustmentRequests = $this->cashierReportAdjustmentRequests($params, $outletId, $window, $timezone, $scopeOutletIds, false);
        $sales = $this->applyApprovedVoidAdjustmentsToCashierSales($sales, $adjustmentRequests);

        $rows = $sales->map(function (Sale $sale) use ($timezone): array {
            return [
                'id' => (string) $sale->id,
                'created_at' => TransactionDate::formatSaleLocal(
                    $this->rawCreatedAtValue($sale),
                    $timezone,
                    (string) ($sale->sale_number ?? '')
                ),
                'marking' => (int) ($sale->marking ?? 0),
                'discount_total' => (int) ($sale->discount_total ?? 0),
                'tax_total' => (int) ($sale->tax_total ?? 0),
                'rounding_total' => (int) ($sale->rounding_total ?? 0),
                'grand_total' => (int) ($sale->grand_total ?? 0),
                'payments' => $sale->payments->map(fn ($payment) => [
                    'payment_method_id' => $payment->payment_method_id ? (string) $payment->payment_method_id : null,
                    'payment_method_name' => $this->resolveSalePaymentMethodName($sale, $payment),
                    'amount' => $this->resolvePaymentSnapshotAmount($sale, $payment),
                ])->values()->all(),
                'payment_method_name' => $this->resolveSalePaymentMethodName($sale),
            ];
        })->values()->all();

        return [
            'range' => [
                'date_from' => $window['requested_from']->toDateString(),
                'date_to' => $window['requested_to']->toDateString(),
                'date' => $window['requested_from']->toDateString(),
            ],
            'sales' => $rows,
            'meta' => [
                'reporting_source' => [
                    'contract' => 'erp_finance_v8_i04',
                    'query_strategy' => $scopeStrategy,
                    'business_date_source' => $scopeStrategy === 'report_sale_business_dates_covered' ? 'report_sale_business_dates' : 'TransactionDate exact fallback',
                    'http_backfill' => false,
                ],
            ],
        ];
    }

    public function cashierReportCashiers(array $params, ?string $outletId): array
    {
        // I05 hot path: the cashier list endpoint used to build the complete report
        // (all sale_items + transformed sales payload) and then discard `sales`.
        // Keep identical business-date / approved void rules, but load only what
        // the cashier cards consume. Detail remains available via /{cashierId}.
        $params = $this->normalizeCashierReportParams($params, $outletId);
        $scopeOutletIds = array_values(array_filter(array_map('strval', $params['scope_outlet_ids'] ?? [])));
        $timezone = !empty($params['scope_timezone']) ? (string) $params['scope_timezone'] : $this->resolveTimezone($outletId);
        $window = $this->resolveCashierReportBusinessWindow(
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $timezone
        );

        $salesQuery = Sale::query()
            ->with(['payments.paymentMethod'])
            ->where('status', '=', 'PAID')
            ->select([
                'id', 'sale_number', 'outlet_id', 'cashier_id', 'cashier_name', 'status', 'created_at',
                'payment_method_name', 'payment_method_type',
                'subtotal', 'discount_total', 'tax_total', 'service_charge_total', 'rounding_total',
                'grand_total', 'paid_total', 'change_total', 'marking',
            ]);

        $scopeStrategy = $this->applyCashierHistoricalScope(
            $salesQuery,
            $scopeOutletIds,
            $outletId,
            $window,
            $timezone,
        );

        if (count($scopeOutletIds) === 1) {
            $salesQuery->where('outlet_id', '=', $scopeOutletIds[0]);
        } elseif (count($scopeOutletIds) > 1) {
            $salesQuery->whereIn('outlet_id', $scopeOutletIds);
        } elseif (!empty($outletId)) {
            $salesQuery->where('outlet_id', '=', $outletId);
        }

        if (!empty($params['cashier_id'])) {
            if ((string) $params['cashier_id'] === 'unknown') {
                $salesQuery->whereNull('cashier_id');
            } else {
                $salesQuery->where('cashier_id', '=', $params['cashier_id']);
            }
        }

        $sales = $salesQuery->orderBy('created_at')->orderBy('sale_number')->get();

        if ($this->isMakassarCashierBusinessTimezone($timezone)) {
            $sales = $sales
                ->filter(fn (Sale $sale) => $this->saleFallsWithinCashierBusinessWindow(
                    $sale, $window['from_local'], $window['to_exclusive_local'], $timezone
                ))
                ->values();
        }

        $saleIds = $sales->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        $itemQtyBySale = [];
        $materializedVoidIds = collect();

        if ($saleIds !== []) {
            $itemQuery = DB::table('sale_items')->whereIn('sale_id', $saleIds);
            $hasVoidedAt = \Illuminate\Support\Facades\Schema::hasColumn('sale_items', 'voided_at');
            if ($hasVoidedAt) {
                $itemQuery->whereNull('voided_at');
                $materializedVoidIds = DB::table('sale_items')
                    ->whereIn('sale_id', $saleIds)
                    ->whereNotNull('voided_at')
                    ->distinct()
                    ->pluck('sale_id')
                    ->map(fn ($id) => (string) $id)
                    ->flip();
            }

            $itemQtyBySale = $itemQuery
                ->selectRaw('sale_id, COALESCE(SUM(qty), 0) as item_qty')
                ->groupBy('sale_id')
                ->pluck('item_qty', 'sale_id')
                ->map(fn ($qty) => (int) $qty)
                ->all();

            // Make the shared void-adjustment helper aware of physically materialized
            // void rows without hydrating every sale item.
            foreach ($sales as $sale) {
                if ($materializedVoidIds->has((string) $sale->id)) {
                    $sale->setRelation('items', collect([(object) ['id' => '', 'voided_at' => now()]]));
                }
            }
        }

        $adjustmentRequests = $this->cashierReportAdjustmentRequests(
            $params, $outletId, $window, $timezone, $scopeOutletIds, false
        );
        $sales = $this->applyApprovedVoidAdjustmentsToCashierSales($sales, $adjustmentRequests);

        // Legacy VOID did not materialize voided sale_item rows. Subtract its snapshot
        // quantity only for those legacy sales; modern split rows are already excluded
        // by the whereNull(voided_at) aggregate above.
        $legacyVoidQty = $this->approvedVoidQtyBySaleAndItem($adjustmentRequests);
        foreach ($sales as $sale) {
            $saleId = (string) $sale->id;
            $qty = max(0, (int) ($itemQtyBySale[$saleId] ?? 0));
            if (! $materializedVoidIds->has($saleId)) {
                $qty = max(0, $qty - array_sum($legacyVoidQty[$saleId] ?? []));
            }
            $itemQtyBySale[$saleId] = $qty;
        }

        $summary = [
            'transaction_count' => $sales->count(),
            'grand_total' => (int) $sales->sum('grand_total'),
            'paid_total' => (int) $sales->sum('paid_total'),
            'change_total' => (int) $sales->sum('change_total'),
            'items_sold' => (int) $sales->sum(fn ($sale) => (int) ($itemQtyBySale[(string) $sale->id] ?? 0)),
            'payment_methods' => [
                ...$this->summarizePaymentMethods($sales),
                ...$this->summarizeCancelVoidPaymentMethods($adjustmentRequests),
            ],
        ];

        $cashiers = $sales
            ->groupBy(fn ($sale) => $sale->cashier_id ?: 'unknown')
            ->map(fn ($group) => $this->summarizeCashierGroupLite($group, $itemQtyBySale, $timezone))
            ->values()
            ->sortBy('cashier_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'range' => [
                'date_from' => $window['requested_from']->toDateString(),
                'date_to' => $window['requested_to']->toDateString(),
                'date' => $window['requested_from']->toDateString(),
            ],
            'summary' => $summary,
            'items' => $cashiers,
            'meta' => [
                'reporting_source' => [
                    'contract' => 'erp_finance_v8_i04',
                    'query_strategy' => $scopeStrategy,
                    'business_date_source' => $scopeStrategy === 'report_sale_business_dates_covered' ? 'report_sale_business_dates' : 'TransactionDate exact fallback',
                    'http_backfill' => false,
                ],
            ],
        ];
    }

}
