<?php

namespace App\Services;

use App\Models\Sale;
use App\Services\CashierAlignedSaleScopeService;
use App\Services\ReportSaleScopeCacheService;
use App\Support\DeliveryNoTaxReadModel;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function __construct(
        private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope,
        private readonly ReportSaleScopeCacheService $reportSaleScopeCache,
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
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = TransactionDate::dateRange(
            $dateFrom,
            $dateTo,
            $timezone ?: $this->currentTimezone()
        );

        $tokens = $this->dateTokensForScope($dateFrom, $dateTo, $timezone);
        if (!$saleNumberColumn || empty($tokens)) {
            $query->whereBetween($createdAtColumn, [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()]);

            return [$fromLocal, $toLocal, $fromUtc, $toUtc];
        }

        $query->where(function ($outer) use ($saleNumberColumn, $createdAtColumn, $tokens, $fromUtc, $toUtc) {
            $outer->where(function ($saleNumberScope) use ($saleNumberColumn, $tokens) {
                foreach ($tokens as $index => $token) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $saleNumberScope->{$method}($saleNumberColumn, 'like', '%-' . $token . '-%');
                }
            })->orWhere(function ($fallbackScope) use ($saleNumberColumn, $createdAtColumn, $fromUtc, $toUtc) {
                $fallbackScope
                    ->where(function ($legacyScope) use ($saleNumberColumn) {
                        $legacyScope
                            ->whereNull($saleNumberColumn)
                            ->orWhere($saleNumberColumn, 'not like', 'S.%-%-%');
                    })
                    ->whereBetween($createdAtColumn, [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()]);
            });
        });

        return [$fromLocal, $toLocal, $fromUtc, $toUtc];
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
        return $this->reportSaleScopeCache->remember(
            $namespace,
            [
                'outlet_ids' => array_values(array_unique(array_map('strval', $scopeOutletIds))),
                'date_from' => $params['date_from'] ?? null,
                'date_to' => $params['date_to'] ?? null,
                'timezone' => $scopeTimezone,
            ],
            fn () => $this->cashierAlignedSaleScope->eligibleSaleIds(
                $scopeOutletIds,
                $params['date_from'] ?? null,
                $params['date_to'] ?? null,
                $scopeTimezone
            )
        );
    }

    private function cachedSaleIdSubquery(array $saleScope): QueryBuilder
    {
        return $this->reportSaleScopeCache->subquery((string) ($saleScope['scope_key'] ?? ''));
    }

    private function paginate(QueryBuilder $q, int $perPage, int $page): LengthAwarePaginator
    {
        // paginate is available on query builder in Laravel
        return $q->paginate(perPage: $perPage, page: $page);
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

    private function buildLedgerReport(array $params, ?string $outletId, bool $markedOnly = false): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();
        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $saleScope = $this->resolveCachedSaleScope($scopeOutletIds, $params, $scopeTimezone);

        $q = DB::table('sales as s')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));


        if (!empty($scopeOutletIds)) {
            $q->whereIn('s.outlet_id', $scopeOutletIds);
        }
        if ($markedOnly) {
            $q->where('s.marking', '=', 1);
        }
        if (!empty($params['payment_method_name'])) {
            $q->where('spm.payment_method_name', '=', $params['payment_method_name']);
        }
        if (!empty($params['channel'])) {
            $q->where('s.channel', '=', $params['channel']);
        }
        if (!empty($params['sale_number'])) {
            $q->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        }

        $q->select([
            's.id as sale_id',
            'o.id as outlet_id',
            'o.code as outlet_code',
            'o.name as outlet_name',
            'o.timezone as outlet_timezone',
            's.sale_number',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, COALESCE(s.payment_method_name, '-')) as payment_method_name"),
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

        $paginator = $this->paginate($q, $perPage, $page);

        $items = collect($paginator->items())->map(function ($r) {
            $dateText = TransactionDate::formatSaleLocal($r->created_at, $r->outlet_timezone, isset($r->sale_number) ? (string) $r->sale_number : null);
            return [
                'sale_id' => (string) $r->sale_id,
                'outlet_id' => (string) ($r->outlet_id ?? ''),
                'outlet_code' => (string) ($r->outlet_code ?? ''),
                'outlet_name' => (string) ($r->outlet_name ?? ''),
                'sale_number' => (string) ($r->sale_number ?? ''),
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total' => (int) ($r->total ?? 0),
                'marking' => (int) ($r->marking ?? 1),
                'date' => TransactionDate::formatSaleLocal($r->created_at, $r->outlet_timezone, isset($r->sale_number) ? (string) $r->sale_number : null, 'Y-m-d'),
                'time' => TransactionDate::formatSaleLocal($r->created_at, $r->outlet_timezone, isset($r->sale_number) ? (string) $r->sale_number : null, 'H:i:s'),
                'created_at' => $dateText,
            ];
        })->values()->all();

        $salesSummary = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));
        if (!empty($scopeOutletIds)) $salesSummary->whereIn('s.outlet_id', $scopeOutletIds);
        if ($markedOnly) $salesSummary->where('s.marking', '=', 1);
        if (!empty($params['payment_method_name'])) $salesSummary->where('spm.payment_method_name', '=', $params['payment_method_name']);
        if (!empty($params['channel'])) $salesSummary->where('s.channel', '=', $params['channel']);
        if (!empty($params['sale_number'])) $salesSummary->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        $salesSummary = $salesSummary->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)),0) as grand_total, COUNT(DISTINCT s.id) as transaction_count')->first();

        $itemSummaryQ = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));
        if (!empty($scopeOutletIds)) $itemSummaryQ->whereIn('s.outlet_id', $scopeOutletIds);
        if ($markedOnly) $itemSummaryQ->where('s.marking', '=', 1);
        if (!empty($params['payment_method_name'])) $itemSummaryQ->where('spm.payment_method_name', '=', $params['payment_method_name']);
        if (!empty($params['channel'])) $itemSummaryQ->where('s.channel', '=', $params['channel']);
        if (!empty($params['sale_number'])) $itemSummaryQ->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        $itemSummary = $itemSummaryQ->selectRaw('COALESCE(SUM(si.qty),0) as items_sold')->first();

        return [
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'summary' => [
                'grand_total' => (int) ($salesSummary->grand_total ?? 0),
                'transaction_count' => (int) ($salesSummary->transaction_count ?? 0),
                'items_sold' => (int) ($itemSummary->items_sold ?? 0),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'timezone' => $scopeTimezone,
                'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
            ],
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
        $perPage = (int) ($params['per_page'] ?? 50);
        $page = (int) ($params['page'] ?? 1);

        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $saleScope = $this->resolveCachedSaleScope($scopeOutletIds, $params, $scopeTimezone);

        $q = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            'si.product_name as item',
            'si.variant_name as variant',
            DB::raw('SUM(si.qty) as qty'),
            DB::raw('AVG(si.unit_price) as unit_price'),
            DB::raw('SUM(si.line_total) as total'),
        ])
        ->groupBy('si.product_name', 'si.variant_name')
        ->orderBy('si.product_name')
        ->orderBy('si.variant_name');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(fn($r) => [
            'item' => (string) ($r->item ?? ''),
            'variant' => (string) ($r->variant ?? ''),
            'qty' => (int) ($r->qty ?? 0),
            'unit_price' => (int) ($r->unit_price ?? 0),
            'total' => (int) ($r->total ?? 0),
        ])->values()->all();

        $sumQ = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($outletId)) $sumQ->where('s.outlet_id', '=', $outletId);

        $summary = $sumQ->selectRaw('COUNT(DISTINCT CONCAT(COALESCE(si.product_name, ""), "||", COALESCE(si.variant_name, ""))) as item_count, COALESCE(SUM(si.qty),0) as qty_total, COALESCE(SUM(si.line_total),0) as grand_total')->first();

        return [
            'range' => ['date_from' => $fromLocal->toDateString(), 'date_to' => $toLocal->toDateString()],
            'summary' => [
                'item_count' => (int) ($summary->item_count ?? 0),
                'qty_total' => (int) ($summary->qty_total ?? 0),
                'grand_total' => (int) ($summary->grand_total ?? 0),
            ],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function itemByProduct(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 50);
        $page = (int) ($params['page'] ?? 1);

        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $saleScope = $this->resolveCachedSaleScope($scopeOutletIds, $params, $scopeTimezone);

        $q = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            'si.product_name as item_product',
            DB::raw('SUM(si.qty) as qty'),
            DB::raw('AVG(si.unit_price) as unit_price'),
            DB::raw('SUM(si.line_total) as total'),
        ])
        ->groupBy('si.product_name')
        ->orderBy('si.product_name');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(fn($r) => [
            'item_product' => (string) ($r->item_product ?? ''),
            'qty' => (int) ($r->qty ?? 0),
            'unit_price' => (int) ($r->unit_price ?? 0),
            'total' => (int) ($r->total ?? 0),
        ])->values()->all();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function itemByVariant(array $params, ?string $outletId): array
    {
        // same as itemSold (already groups by product+variant)
        return $this->itemSold($params, $outletId);
    }


    public function rounding(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();

        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $saleScope = $this->resolveCachedSaleScope($scopeOutletIds, $params, $scopeTimezone);

        $q = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->where('s.rounding_total', '!=', 0)
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);
        if (!empty($params['sale_number'])) $q->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        if (!empty($params['channel'])) $q->where('s.channel', '=', $params['channel']);
        if (!empty($params['payment_method_name'])) $q->where('spm.payment_method_name', '=', $params['payment_method_name']);

        $q->select([
            's.id as sale_id',
            's.sale_number',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, '-') as payment_method_name"),
            DB::raw('GREATEST(COALESCE(s.grand_total, 0) - COALESCE(s.rounding_total, 0), 0) as total_before_rounding'),
            's.rounding_total as rounding',
            DB::raw('COALESCE(s.grand_total, 0) as total'),
            's.created_at',
        ])->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) {
            return [
                'sale_id' => (string) $r->sale_id,
                'sale_number' => (string) $r->sale_number,
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total_before_rounding' => (int) ($r->total_before_rounding ?? 0),
                'rounding' => (int) ($r->rounding ?? 0),
                'total' => (int) ($r->total ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at, null, isset($r->sale_number) ? (string) $r->sale_number : null),
            ];
        })->values()->all();

        $sumQ = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->where('s.rounding_total', '!=', 0)
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($outletId)) $sumQ->where('s.outlet_id', '=', $outletId);
        if (!empty($params['sale_number'])) $sumQ->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        if (!empty($params['channel'])) $sumQ->where('s.channel', '=', $params['channel']);
        if (!empty($params['payment_method_name'])) $sumQ->where('spm.payment_method_name', '=', $params['payment_method_name']);

        $summary = $sumQ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(s.rounding_total),0) as rounding_total, COALESCE(SUM(CASE WHEN s.rounding_total > 0 THEN s.rounding_total ELSE 0 END),0) as rounding_up_total, COALESCE(ABS(SUM(CASE WHEN s.rounding_total < 0 THEN s.rounding_total ELSE 0 END)),0) as rounding_down_total')->first();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'summary' => [
                'transaction_count' => (int) ($summary->transaction_count ?? 0),
                'rounding_total' => (int) ($summary->rounding_total ?? 0),
                'rounding_up_total' => (int) ($summary->rounding_up_total ?? 0),
                'rounding_down_total' => (int) ($summary->rounding_down_total ?? 0),
            ],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function tax(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();

        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $saleScope = $this->resolveCachedSaleScope($scopeOutletIds, $params, $scopeTimezone);

        $q = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($scopeOutletIds)) $q->whereIn('s.outlet_id', $scopeOutletIds);
        if (!empty($params['sale_number'])) $q->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        if (!empty($params['channel'])) $q->where('s.channel', '=', $params['channel']);
        if (!empty($params['payment_method_name'])) $q->where('spm.payment_method_name', '=', $params['payment_method_name']);

        $q->select([
            's.id as sale_id',
            's.sale_number',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, '-') as payment_method_name"),
            DB::raw('COALESCE(s.grand_total, 0) as total'),
            DB::raw('COALESCE(s.tax_total, 0) as tax'),
            's.created_at',
        ])
        ->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) use ($scopeTimezone) {
            return [
                'sale_id' => (string) $r->sale_id,
                'sale_number' => (string) $r->sale_number,
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total' => (int) ($r->total ?? 0),
                'tax' => (int) ($r->tax ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at, $scopeTimezone, isset($r->sale_number) ? (string) $r->sale_number : null),
            ];
        })->values()->all();

        $summaryQ = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));
        if (!empty($scopeOutletIds)) $summaryQ->whereIn('s.outlet_id', $scopeOutletIds);
        if (!empty($params['sale_number'])) $summaryQ->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        if (!empty($params['channel'])) $summaryQ->where('s.channel', '=', $params['channel']);
        if (!empty($params['payment_method_name'])) $summaryQ->where('spm.payment_method_name', '=', $params['payment_method_name']);
        $summary = $summaryQ->selectRaw('COUNT(DISTINCT s.id) as transaction_count, COALESCE(SUM(COALESCE(s.grand_total, 0)),0) as grand_total, COALESCE(SUM(COALESCE(s.tax_total, 0)),0) as tax_total')->first();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'summary' => [
                'transaction_count' => (int) ($summary->transaction_count ?? 0),
                'grand_total' => (int) ($summary->grand_total ?? 0),
                'tax_total' => (int) ($summary->tax_total ?? 0),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
                'timezone' => $scopeTimezone,
                'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
            ],
        ];
    }

    public function discount(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();

        $scopeOutletIds = $this->resolveReportScopeOutletIds($params, $outletId);
        $scopeTimezone = $this->resolveReportScopeTimezone($params, $outletId);
        $saleScope = $this->resolveCachedSaleScope($scopeOutletIds, $params, $scopeTimezone);

        $q = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->where('s.discount_amount', '>', 0)
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        if (!empty($outletId)) {
            $q->where('s.outlet_id', '=', $outletId);
        }

        if (!empty($params['sale_number'])) {
            $q->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        }
        if (!empty($params['channel'])) {
            $q->where('s.channel', '=', $params['channel']);
        }
        if (!empty($params['payment_method_name'])) {
            $q->where('spm.payment_method_name', '=', $params['payment_method_name']);
        }
        if (!empty($params['discount_name'])) {
            $needle = trim((string) $params['discount_name']);
            $jsonNeedle = '%"name":"' . $needle . '"%';
            $q->where(function ($discountScope) use ($needle, $jsonNeedle) {
                $discountScope
                    ->where('s.discount_name_snapshot', '=', $needle)
                    ->orWhere('s.discounts_snapshot', 'like', $jsonNeedle)
                    ->orWhere('s.discounts_snapshot', 'like', '%' . $needle . '%');
            });
        }
        if (!empty($params['discount_squad_nisj'])) {
            $q->where('s.discount_squad_nisj', 'like', '%' . $params['discount_squad_nisj'] . '%');
        }

        $q->select([
            's.id as sale_id',
            's.sale_number',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, '-') as payment_method_name"),
            DB::raw('COALESCE(s.grand_total, 0) as total'),
            's.discount_amount as discount',
            's.discount_name_snapshot',
            's.discounts_snapshot',
            's.discount_squad_nisj',
            's.created_at',
        ])
        ->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) {
            $discountNames = $this->extractDiscountNames($r->discount_name_snapshot ?? null, $r->discounts_snapshot ?? null);

            return [
                'sale_id' => (string) $r->sale_id,
                'sale_number' => (string) $r->sale_number,
                'discount_name' => !empty($discountNames) ? implode(', ', $discountNames) : '-',
                'discount_names' => $discountNames,
                'discount_squad_nisj' => (string) ($r->discount_squad_nisj ?? ''),
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total' => (int) ($r->total ?? 0),
                'discount' => (int) ($r->discount ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at, null, isset($r->sale_number) ? (string) $r->sale_number : null),
            ];
        })->values()->all();

        $optionQ = DB::table('sales as s')
            ->where('s.status', '=', 'PAID')
            ->where('s.discount_amount', '>', 0);
        $this->applyOutletUtcDateRange($optionQ, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null, $outletId);
        if (!empty($outletId)) $optionQ->where('s.outlet_id', '=', $outletId);
        $optionsRows = $optionQ->select(['s.discount_name_snapshot', 's.discounts_snapshot'])->get();
        $discountNames = [];
        foreach ($optionsRows as $row) {
            foreach ($this->extractDiscountNames($row->discount_name_snapshot ?? null, $row->discounts_snapshot ?? null) as $name) {
                if ($name !== '') {
                    $discountNames[$name] = true;
                }
            }
        }
        ksort($discountNames);

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
            'filter_options' => [
                'discount_names' => array_values(array_keys($discountNames)),
            ],
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
            'grand_total' => (int) ($sale->grand_total ?? 0),
            'paid_total' => (int) ($sale->paid_total ?? 0),
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

        if ($this->isMakassarCashierBusinessTimezone($timezone)) {
            $candidateDateTo = $window['requested_to']->addDay()->toDateString();
            $this->applyBusinessDateScope(
                $salesQuery,
                'sale_number',
                'created_at',
                $window['requested_from']->toDateString(),
                $candidateDateTo,
                $timezone
            );
        } else {
            $this->applyBusinessDateScope(
                $salesQuery,
                'sale_number',
                'created_at',
                $window['requested_from']->toDateString(),
                $window['requested_to']->toDateString(),
                $timezone
            );
        }

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

        $summary = [
            'transaction_count' => $sales->count(),
            'grand_total' => (int) $sales->sum('grand_total'),
            'paid_total' => (int) $sales->sum('paid_total'),
            'change_total' => (int) $sales->sum('change_total'),
            'items_sold' => (int) $sales->sum(fn ($sale) => $sale->items->sum('qty')),
            'payment_methods' => $this->summarizePaymentMethods($sales),
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
        ];
    }

    public function cashierReportCashiers(array $params, ?string $outletId): array
    {
        $data = $this->cashierReport($params, $outletId);

        return [
            'range' => $data['range'],
            'summary' => $data['summary'],
            'items' => $data['cashiers'],
        ];
    }

}
