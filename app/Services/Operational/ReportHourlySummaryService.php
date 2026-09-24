<?php

namespace App\Services\Operational;

use App\Support\TransactionDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportHourlySummaryService
{
    public function refreshDate(array $outletIds, string $businessDate): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === [] || $businessDate === '' || ! $this->tablesReady()) {
            return ['outlets' => 0, 'date' => $businessDate, 'coverage_rows' => 0];
        }

        $timestamp = now()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($outletIds, $businessDate, $timestamp): void {
            DB::table('report_hourly_sales_summaries')
                ->whereIn('outlet_id', $outletIds)
                ->where('business_date', $businessDate)
                ->delete();

            DB::table('report_hourly_product_summaries')
                ->whereIn('outlet_id', $outletIds)
                ->where('business_date', $businessDate)
                ->delete();

            DB::table('report_hourly_summary_coverage')
                ->whereIn('outlet_id', $outletIds)
                ->where('business_date', $businessDate)
                ->delete();

            $this->insertSalesSummary($outletIds, $businessDate, $timestamp);
            $this->insertProductSummary($outletIds, $businessDate, $timestamp);
            $this->insertCoverage($outletIds, $businessDate, $timestamp);
        }, 3);

        return [
            'outlets' => count($outletIds),
            'date' => $businessDate,
            'coverage_rows' => DB::table('report_hourly_summary_coverage')
                ->whereIn('outlet_id', $outletIds)
                ->where('business_date', $businessDate)
                ->count(),
        ];
    }

    public function stalePairs(string $fromDate, string $toDate, ?int $limit = null): Collection
    {
        if (! $this->tablesReady() || ! Schema::hasTable('report_daily_summary_coverage')) {
            return collect();
        }

        $query = DB::table('report_daily_summary_coverage as d')
            ->leftJoin('report_hourly_summary_coverage as h', function ($join): void {
                $join->on('h.outlet_id', '=', 'd.outlet_id')
                    ->on('h.business_date', '=', 'd.business_date');
            })
            ->whereBetween('d.business_date', [$fromDate, $toDate])
            ->where(function ($query): void {
                $query->whereNull('h.outlet_id')
                    ->orWhereNull('h.source_daily_synced_at')
                    ->orWhereColumn('h.source_daily_synced_at', '<', 'd.synced_at');
            })
            ->orderBy('d.business_date')
            ->orderBy('d.outlet_id')
            ->select(['d.outlet_id', 'd.business_date', 'd.synced_at as daily_synced_at']);

        if ($limit !== null) {
            $query->limit(max(1, min(1000, $limit)));
        }

        return $query->get();
    }

    public function readContractStatus(array $outletIds, string $businessDate): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $expected = count($outletIds);
        if ($expected === 0 || ! $this->tablesReady() || ! Schema::hasTable('report_daily_summary_coverage')) {
            return [
                'contract' => 'erp_finance_v8_i08_hourly_materialized',
                'source' => 'report_hourly_sales_summaries + report_hourly_product_summaries',
                'business_date' => $businessDate,
                'date_from' => $businessDate,
                'date_to' => $businessDate,
                'range_days' => 1,
                'outlet_count' => $expected,
                'outlet_ids' => $outletIds,
                'timezone' => (string) config('app.timezone', 'Asia/Jakarta'),
                'recovery_pipeline' => 'hourly',
                'ready' => false,
                'state' => 'missing_schema_or_scope',
                'expected_rows' => $expected,
                'ready_rows' => 0,
                'missing_rows' => $expected,
                'coverage_percent' => 0.0,
                'http_backfill' => false,
            ];
        }

        $row = DB::table('report_daily_summary_coverage as d')
            ->leftJoin('report_hourly_summary_coverage as h', function ($join): void {
                $join->on('h.outlet_id', '=', 'd.outlet_id')
                    ->on('h.business_date', '=', 'd.business_date');
            })
            ->whereIn('d.outlet_id', $outletIds)
            ->where('d.business_date', $businessDate)
            ->selectRaw('COUNT(DISTINCT d.outlet_id) as daily_rows')
            ->selectRaw('SUM(CASE WHEN h.outlet_id IS NOT NULL AND h.source_daily_synced_at IS NOT NULL AND h.source_daily_synced_at >= d.synced_at THEN 1 ELSE 0 END) as ready_rows')
            ->selectRaw('MIN(h.synced_at) as oldest_hourly_synced_at')
            ->selectRaw('MAX(h.synced_at) as latest_hourly_synced_at')
            ->first();

        $dailyRows = (int) ($row->daily_rows ?? 0);
        $readyRows = (int) ($row->ready_rows ?? 0);
        $ready = $dailyRows === $expected && $readyRows === $expected;
        $missing = max(0, $expected - $readyRows);

        return [
            'contract' => 'erp_finance_v8_i08_hourly_materialized',
            'source' => 'report_hourly_sales_summaries + report_hourly_product_summaries',
            'business_date' => $businessDate,
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'range_days' => 1,
            'outlet_count' => $expected,
            'outlet_ids' => $outletIds,
            'timezone' => (string) config('app.timezone', 'Asia/Jakarta'),
            'recovery_pipeline' => 'hourly',
            'expected_rows' => $expected,
            'daily_ready_rows' => $dailyRows,
            'ready_rows' => $readyRows,
            'missing_rows' => $missing,
            'coverage_percent' => $expected > 0 ? round(($readyRows / $expected) * 100, 2) : 100.0,
            'oldest_synced_at' => $row->oldest_hourly_synced_at ?? null,
            'latest_synced_at' => $row->latest_hourly_synced_at ?? null,
            'ready' => $ready,
            'state' => $ready ? 'ready' : 'warming',
            'http_backfill' => false,
        ];
    }

    public function historicalSalesSeries(array $outletIds, string $businessDate): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $rows = $outletIds === [] ? collect() : DB::table('report_hourly_sales_summaries')
            ->whereIn('outlet_id', $outletIds)
            ->where('business_date', $businessDate)
            ->groupBy('business_hour')
            ->orderBy('business_hour')
            ->selectRaw('business_hour')
            ->selectRaw('SUM(trx_count) as trx_count')
            ->selectRaw('SUM(gross_amount_sales) as gross_amount_sales')
            ->get();

        return $this->fill24Hours($rows);
    }

    public function liveSalesSeries(array $outletIds, string $businessDate): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) return $this->fill24Hours(collect());

        // V8 I13 HF01: resolve the canonical local hour at sale-row level first.
        // Aggregating the complex TransactionDate SQL expression directly in GROUP BY
        // triggers ONLY_FULL_GROUP_BY/1055 on MySQL strict mode because the expression
        // references s.sale_number and s.created_at. Group the derived scalar instead.
        $hourSql = $this->hourSql('rsbd.business_timezone');
        $hourlySales = DB::table('report_sale_business_dates as rsbd')
            ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->where('rsbd.business_date', $businessDate)
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->selectRaw("{$hourSql} as business_hour")
            ->selectRaw('s.grand_total');

        $rows = DB::query()
            ->fromSub($hourlySales, 'hourly_sales')
            ->groupBy('hourly_sales.business_hour')
            ->orderBy('hourly_sales.business_hour')
            ->selectRaw('hourly_sales.business_hour as business_hour')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(hourly_sales.grand_total), 0) as gross_amount_sales')
            ->get();

        return $this->fill24Hours($rows);
    }

    public function liveOutletCurrentHour(array $outletIds, string $businessDate, int $hour): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) return [];

        $hour = max(0, min(23, $hour));
        $hourSql = $this->hourSql('rsbd.business_timezone');

        return DB::table('report_sale_business_dates as rsbd')
            ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'rsbd.outlet_id')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->where('rsbd.business_date', $businessDate)
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->whereRaw("{$hourSql} = ?", [$hour])
            ->groupBy('rsbd.outlet_id', 'o.code', 'o.name')
            ->orderByRaw('SUM(s.grand_total) DESC')
            ->selectRaw('rsbd.outlet_id')
            ->selectRaw("COALESCE(o.code, '') as outlet_code")
            ->selectRaw("COALESCE(o.name, '-') as outlet_name")
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(s.grand_total), 0) as gross_amount_sales')
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => (string) ($row->outlet_id ?? ''),
                'outlet_code' => (string) ($row->outlet_code ?? ''),
                'outlet_name' => (string) ($row->outlet_name ?? '-'),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_amount_sales' => (int) round((float) ($row->gross_amount_sales ?? 0)),
            ])->values()->all();
    }

    public function historicalTopItemsByCategory(array $outletIds, string $businessDate, int $hour, int $top = 5): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) return [];

        $rows = DB::table('report_hourly_product_summaries')
            ->whereIn('outlet_id', $outletIds)
            ->where('business_date', $businessDate)
            ->where('business_hour', max(0, min(23, $hour)))
            ->groupBy('category_id', 'product_id')
            ->selectRaw('category_id, product_id')
            ->selectRaw("MAX(category_name) as category_name")
            ->selectRaw("MAX(category_kind) as category_kind")
            ->selectRaw("MAX(product_name) as product_name")
            ->selectRaw('SUM(item_sold) as item_sold')
            ->selectRaw('SUM(gross_sales) as gross_sales')
            ->get();

        return $this->groupTopItems($rows, $top);
    }

    public function liveTopItemsByCategory(array $outletIds, string $businessDate, int $hour, int $top = 5): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) return [];

        $hour = max(0, min(23, $hour));
        $hourSql = $this->hourSql('rsbd.business_timezone');
        $rows = DB::table('report_sale_business_dates as rsbd')
            ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
            ->join('sale_items as si', 'si.sale_id', '=', 'rsbd.sale_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->where('rsbd.business_date', $businessDate)
            ->whereRaw("{$hourSql} = ?", [$hour])
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->whereNull('si.voided_at')
            ->groupBy('p.category_id', 'si.product_id')
            ->selectRaw("COALESCE(p.category_id, '') as category_id")
            ->selectRaw("MAX(COALESCE(NULLIF(c.name, ''), 'Uncategorized')) as category_name")
            ->selectRaw("MAX(COALESCE(NULLIF(si.category_kind_snapshot, ''), '')) as category_kind")
            ->selectRaw("COALESCE(si.product_id, '') as product_id")
            ->selectRaw("MAX(COALESCE(NULLIF(si.product_name, ''), '-')) as product_name")
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as gross_sales')
            ->get();

        return $this->groupTopItems($rows, $top);
    }

    private function insertSalesSummary(array $outletIds, string $businessDate, string $timestamp): void
    {
        $scope = $this->scopeSales($outletIds, $businessDate);
        $hourSql = $this->hourSql('scope_sales.business_timezone');

        // Resolve business_hour before aggregation. This preserves TransactionDate's
        // sale-number/timezone logic while keeping strict MySQL GROUP BY deterministic.
        $hourlySales = DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->selectRaw('scope_sales.outlet_id')
            ->selectRaw('scope_sales.business_date')
            ->selectRaw('scope_sales.business_timezone')
            ->selectRaw('scope_sales.marking')
            ->selectRaw("{$hourSql} as business_hour")
            ->selectRaw('s.grand_total');

        $source = DB::query()
            ->fromSub($hourlySales, 'hourly_sales')
            ->groupBy(
                'hourly_sales.outlet_id',
                'hourly_sales.business_date',
                'hourly_sales.business_timezone',
                'hourly_sales.business_hour'
            )
            ->selectRaw('hourly_sales.outlet_id')
            ->selectRaw('hourly_sales.business_date')
            ->selectRaw('hourly_sales.business_timezone')
            ->selectRaw('hourly_sales.business_hour')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(hourly_sales.marking, 0) = 1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('COALESCE(SUM(hourly_sales.grand_total), 0) as gross_amount_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(hourly_sales.marking, 0) = 1 THEN hourly_sales.grand_total ELSE 0 END), 0) as marked_gross_amount_sales')
            ->selectRaw('? as created_at', [$timestamp])
            ->selectRaw('? as updated_at', [$timestamp]);

        DB::table('report_hourly_sales_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone', 'business_hour',
            'trx_count', 'marked_trx_count', 'gross_amount_sales', 'marked_gross_amount_sales',
            'created_at', 'updated_at',
        ], $source);
    }

    private function insertProductSummary(array $outletIds, string $businessDate, string $timestamp): void
    {
        $scope = $this->scopeSales($outletIds, $businessDate);
        $hourSql = $this->hourSql('scope_sales.business_timezone');

        // Keep the hour resolver at one-row-per-sale granularity, then join items and
        // aggregate by the derived business_hour. This avoids the same 1055 failure in
        // product materialization after the sales summary has completed.
        $hourlySales = DB::query()
            ->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw('scope_sales.outlet_id')
            ->selectRaw('scope_sales.business_date')
            ->selectRaw('scope_sales.business_timezone')
            ->selectRaw('scope_sales.marking')
            ->selectRaw("{$hourSql} as business_hour");

        $source = DB::query()
            ->fromSub($hourlySales, 'hourly_sales')
            ->join('sale_items as si', 'si.sale_id', '=', 'hourly_sales.sale_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('si.voided_at')
            ->groupBy(
                'hourly_sales.outlet_id',
                'hourly_sales.business_date',
                'hourly_sales.business_timezone',
                'hourly_sales.business_hour',
                'si.product_id',
                'p.category_id'
            )
            ->selectRaw('hourly_sales.outlet_id')
            ->selectRaw('hourly_sales.business_date')
            ->selectRaw('hourly_sales.business_timezone')
            ->selectRaw('hourly_sales.business_hour')
            ->selectRaw("COALESCE(si.product_id, '') as product_id")
            ->selectRaw("MAX(COALESCE(NULLIF(si.product_name, ''), '-')) as product_name")
            ->selectRaw("COALESCE(p.category_id, '') as category_id")
            ->selectRaw("MAX(COALESCE(NULLIF(c.name, ''), 'Uncategorized')) as category_name")
            ->selectRaw("MAX(COALESCE(NULLIF(si.category_kind_snapshot, ''), '')) as category_kind")
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(hourly_sales.marking, 0) = 1 THEN si.qty ELSE 0 END), 0) as marked_item_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(hourly_sales.marking, 0) = 1 THEN si.line_total ELSE 0 END), 0) as marked_gross_sales')
            ->selectRaw('? as created_at', [$timestamp])
            ->selectRaw('? as updated_at', [$timestamp]);

        DB::table('report_hourly_product_summaries')->insertUsing([
            'outlet_id', 'business_date', 'business_timezone', 'business_hour',
            'product_id', 'product_name', 'category_id', 'category_name', 'category_kind',
            'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales',
            'created_at', 'updated_at',
        ], $source);
    }

    private function insertCoverage(array $outletIds, string $businessDate, string $timestamp): void
    {
        if (! Schema::hasTable('report_daily_summary_coverage')) return;

        $rows = DB::table('report_daily_summary_coverage')
            ->whereIn('outlet_id', $outletIds)
            ->where('business_date', $businessDate)
            ->get(['outlet_id', 'business_date', 'synced_at']);

        $payload = $rows->map(fn ($row) => [
            'outlet_id' => (string) ($row->outlet_id ?? ''),
            'business_date' => (string) ($row->business_date ?? $businessDate),
            'source_daily_synced_at' => $row->synced_at ?? null,
            'synced_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->filter(fn ($row) => $row['outlet_id'] !== '')->values()->all();

        if ($payload !== []) {
            DB::table('report_hourly_summary_coverage')->insert($payload);
        }
    }

    private function scopeSales(array $outletIds, string $businessDate): Builder
    {
        return DB::table('report_sale_business_dates as rsbd')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->where('rsbd.business_date', $businessDate)
            ->selectRaw('rsbd.sale_id')
            ->selectRaw('rsbd.outlet_id')
            ->selectRaw('rsbd.business_date')
            ->selectRaw('rsbd.business_timezone')
            ->selectRaw('COALESCE(CAST(rsbd.marking AS SIGNED), 0) as marking');
    }

    private function hourSql(string $timezoneColumn): string
    {
        $jakarta = TransactionDate::resolvedSaleLocalSqlExpression('s.created_at', 's.sale_number', 'Asia/Jakarta');
        $makassar = TransactionDate::resolvedSaleLocalSqlExpression('s.created_at', 's.sale_number', 'Asia/Makassar');
        $jayapura = TransactionDate::resolvedSaleLocalSqlExpression('s.created_at', 's.sale_number', 'Asia/Jayapura');

        return "CASE WHEN {$timezoneColumn} = 'Asia/Makassar' THEN HOUR({$makassar}) WHEN {$timezoneColumn} = 'Asia/Jayapura' THEN HOUR({$jayapura}) ELSE HOUR({$jakarta}) END";
    }

    private function fill24Hours(Collection $rows): array
    {
        $byHour = $rows->keyBy(fn ($row) => (int) ($row->business_hour ?? 0));
        $result = [];
        for ($hour = 0; $hour <= 23; $hour++) {
            $row = $byHour->get($hour);
            $result[] = [
                'hour' => $hour,
                'hour_label' => sprintf('%02d:00', $hour),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_amount_sales' => (int) round((float) ($row->gross_amount_sales ?? 0)),
            ];
        }
        return $result;
    }

    private function groupTopItems(Collection $rows, int $top): array
    {
        $top = max(1, min(10, $top));
        return $rows
            ->map(fn ($row) => [
                'category_id' => (string) ($row->category_id ?? ''),
                'category_name' => (string) ($row->category_name ?? 'Uncategorized'),
                'category_kind' => (string) ($row->category_kind ?? ''),
                'product_id' => (string) ($row->product_id ?? ''),
                'product_name' => (string) ($row->product_name ?? '-'),
                'item_sold' => (float) ($row->item_sold ?? 0),
                'gross_sales' => (int) round((float) ($row->gross_sales ?? 0)),
            ])
            ->groupBy(fn (array $row) => ($row['category_id'] !== '' ? $row['category_id'] : 'name:'.mb_strtolower($row['category_name'])))
            ->map(function (Collection $items) use ($top): array {
                $sorted = $items->sort(function (array $a, array $b): int {
                    $cmp = $b['item_sold'] <=> $a['item_sold'];
                    return $cmp !== 0 ? $cmp : strcmp(mb_strtolower($a['product_name']), mb_strtolower($b['product_name']));
                })->values();
                $first = $sorted->first() ?? [];
                return [
                    'category_id' => (string) ($first['category_id'] ?? ''),
                    'category_name' => (string) ($first['category_name'] ?? 'Uncategorized'),
                    'category_kind' => (string) ($first['category_kind'] ?? ''),
                    'total_item_sold' => (float) $sorted->sum('item_sold'),
                    'total_gross_sales' => (int) $sorted->sum('gross_sales'),
                    'items' => $sorted->take($top)->values()->all(),
                ];
            })
            ->sortByDesc('total_item_sold')
            ->values()
            ->all();
    }

    private function normalizeOutletIds(array $outletIds): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($id) => trim((string) $id), $outletIds))));
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('report_hourly_sales_summaries')
            && Schema::hasTable('report_hourly_product_summaries')
            && Schema::hasTable('report_hourly_summary_coverage')
            && Schema::hasTable('report_sale_business_dates');
    }
}
