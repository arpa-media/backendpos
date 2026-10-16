<?php

namespace App\Services\Reporting;

use App\Support\TransactionDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only exact transaction membership for Sales/Detail consumers.
 *
 * Aggregate reports may use materialized daily/hourly/monthly summaries, but a
 * transaction list/detail must never disappear only because a materialized sale
 * membership/index/cache is stale or incomplete. This service therefore resolves
 * business-date membership directly from `sales`, using the same exact cashier
 * timestamp resolver and an indexed coarse `created_at` prefilter.
 *
 * IMPORTANT: no coverage build/backfill is executed from HTTP requests.
 */
class ReportExactSaleScopeService
{
    public function scopeQuery(
        array $outletIds,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $fallbackTimezone = null,
        bool $markedOnly = false,
    ): Builder {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) {
            return $this->emptyScopeQuery();
        }

        $timezoneMap = $this->timezoneMap($outletIds, $fallbackTimezone);
        $grouped = [];
        foreach ($outletIds as $outletId) {
            $timezone = $timezoneMap[$outletId] ?? TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
            $grouped[$timezone][] = $outletId;
        }

        $queries = [];
        foreach ($grouped as $timezone => $timezoneOutletIds) {
            $query = DB::table('sales as exact_s')
                ->whereIn('exact_s.outlet_id', $timezoneOutletIds)
                ->whereNull('exact_s.deleted_at')
                ->where('exact_s.status', '=', 'PAID');

            TransactionDate::applyExactBusinessDateScope(
                $query,
                'exact_s.created_at',
                $dateFrom,
                $dateTo,
                $timezone,
                'exact_s.sale_number',
            );

            if ($markedOnly) {
                $query->whereRaw('COALESCE(CAST(exact_s.marking AS SIGNED), 0) = 1');
            }

            $query
                ->selectRaw('exact_s.id as sale_id')
                ->selectRaw('exact_s.outlet_id')
                ->selectRaw('COALESCE(CAST(exact_s.marking AS SIGNED), 0) as marking');

            $queries[] = $query;
        }

        if ($queries === []) {
            return $this->emptyScopeQuery();
        }

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'exact_sale_scope')
            ->select([
                'exact_sale_scope.sale_id',
                'exact_sale_scope.outlet_id',
                'exact_sale_scope.marking',
            ]);
    }

    public function recentSaleIds(
        array $outletIds,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $fallbackTimezone,
        int $limit,
        bool $markedOnly = false,
    ): array {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $scope = $this->scopeQuery($outletIds, $dateFrom, $dateTo, $fallbackTimezone, $markedOnly);

        return DB::query()
            ->fromSub($scope, 'sale_scope')
            ->join('sales as s', 's.id', '=', 'sale_scope.sale_id')
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id')
            ->limit($limit)
            ->pluck('sale_scope.sale_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Fast detail membership check: PK lookup first, then exact business-date
     * calculation in PHP. This avoids scanning/materializing an entire date range
     * just to validate one transaction id.
     */
    public function saleExists(
        array $outletIds,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $fallbackTimezone,
        string $saleId,
        bool $markedOnly = false,
    ): bool {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $saleId = trim($saleId);
        if ($outletIds === [] || $saleId === '') {
            return false;
        }

        $sale = DB::table('sales as s')
            ->leftJoin('outlets as o', 'o.id', '=', 's.outlet_id')
            ->where('s.id', '=', $saleId)
            ->whereIn('s.outlet_id', $outletIds)
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->when($markedOnly, fn ($query) => $query->whereRaw('COALESCE(CAST(s.marking AS SIGNED), 0) = 1'))
            ->first([
                's.id',
                's.outlet_id',
                's.sale_number',
                's.created_at',
                'o.timezone as outlet_timezone',
            ]);

        if (! $sale) {
            return false;
        }

        $timezone = TransactionDate::normalizeTimezone(
            (string) ($sale->outlet_timezone ?? ''),
            TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone()),
        );
        $window = TransactionDate::businessDateWindow($dateFrom, $dateTo, $timezone);
        $businessDate = TransactionDate::businessDateForSale(
            $sale->created_at ?? null,
            $timezone,
            isset($sale->sale_number) ? (string) $sale->sale_number : null,
        );

        if (! $businessDate) {
            return false;
        }

        return $businessDate >= $window['requested_from']->toDateString()
            && $businessDate <= $window['requested_to']->toDateString();
    }

    private function timezoneMap(array $outletIds, ?string $fallbackTimezone): array
    {
        $fallback = TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
        $rows = DB::table('outlets')
            ->whereIn('id', $outletIds)
            ->get(['id', 'timezone']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->id] = TransactionDate::normalizeTimezone((string) ($row->timezone ?? ''), $fallback);
        }

        foreach ($outletIds as $outletId) {
            $map[$outletId] ??= $fallback;
        }

        return $map;
    }

    private function normalizeOutletIds(array $outletIds): array
    {
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($outletIds);

        return $outletIds;
    }

    private function emptyScopeQuery(): Builder
    {
        return DB::table('sales as exact_s')
            ->selectRaw('exact_s.id as sale_id')
            ->selectRaw('exact_s.outlet_id')
            ->selectRaw('COALESCE(CAST(exact_s.marking AS SIGNED), 0) as marking')
            ->whereRaw('1 = 0');
    }
}
