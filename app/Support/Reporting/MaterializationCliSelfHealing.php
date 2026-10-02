<?php

namespace App\Support\Reporting;

use App\Services\ReportSaleBusinessDateIndexService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaterializationCliSelfHealing
{
    public function __construct(
        private readonly ReportSaleBusinessDateIndexService $businessDateIndex,
    ) {
    }

    /**
     * @return array<int, array{id:string,code:string,name:string,timezone:string}>
     */
    public function resolveOutlets(array $filters = []): array
    {
        $tokens = collect($filters)
            ->flatMap(fn ($value) => preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => strtolower($value))
            ->values();

        $rows = DB::table('outlets')
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'timezone']);

        if ($tokens->isEmpty()) {
            return $rows->map(fn ($row) => $this->outletRow($row))->values()->all();
        }

        $selected = $rows->filter(function ($row) use ($tokens): bool {
            $id = strtolower(trim((string) ($row->id ?? '')));
            $code = strtolower(trim((string) ($row->code ?? '')));
            $name = strtolower(trim((string) ($row->name ?? '')));

            foreach ($tokens as $token) {
                $needle = strtolower(trim((string) $token));
                if ($needle === $id || $needle === $code || $needle === $name) {
                    return true;
                }
            }

            return false;
        });

        return $selected->map(fn ($row) => $this->outletRow($row))->values()->all();
    }

    /** @return array{0:string,1:string} */
    public function expandDateRange(string $from, string $to, int $adjacentDays, string $timezone): array
    {
        $adjacentDays = max(0, min(7, $adjacentDays));
        if ($adjacentDays === 0) {
            return [$from, $to];
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $expandedFrom = CarbonImmutable::parse($from, $timezone)->subDays($adjacentDays)->startOfDay();
        $expandedTo = CarbonImmutable::parse($to, $timezone)->addDays($adjacentDays)->startOfDay();
        if ($expandedTo->greaterThan($today)) {
            $expandedTo = $today;
        }

        return [$expandedFrom->toDateString(), $expandedTo->toDateString()];
    }

    /**
     * @return array{ok:bool,rows:array<int,array<string,mixed>>,issues:int,pending_queue:int}
     */
    public function auditDaily(array $outlets, string $from, string $to, string $timezone, bool $requireQueueClean = true): array
    {
        $ids = array_values(array_filter(array_map(fn ($row) => (string) ($row['id'] ?? ''), $outlets)));
        if ($ids === []) {
            return ['ok' => true, 'rows' => [], 'issues' => 0, 'pending_queue' => 0];
        }

        $raw = collect($this->businessDateIndex->auditRawExactStats($ids, $from, $to, $timezone))
            ->keyBy(fn ($row) => $this->key((string) $row['outlet_id'], (string) $row['business_date']));

        $index = Schema::hasTable('report_sale_business_dates')
            ? DB::table('report_sale_business_dates as rsbd')
                ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
                ->whereIn('rsbd.outlet_id', $ids)
                ->whereBetween('rsbd.business_date', [$from, $to])
                ->whereNull('s.deleted_at')
                ->where('s.status', 'PAID')
                ->groupBy('rsbd.outlet_id', 'rsbd.business_date')
                ->get([
                    'rsbd.outlet_id',
                    'rsbd.business_date',
                    DB::raw('COUNT(*) as trx_count'),
                    DB::raw('COALESCE(SUM(s.grand_total),0) as grand_sales'),
                ])->keyBy(fn ($row) => $this->key((string) $row->outlet_id, (string) $row->business_date))
            : collect();

        $daily = Schema::hasTable('report_daily_sales_summaries')
            ? DB::table('report_daily_sales_summaries')
                ->whereIn('outlet_id', $ids)
                ->whereBetween('business_date', [$from, $to])
                ->get(['outlet_id', 'business_date', 'trx_count', 'grand_sales'])
                ->keyBy(fn ($row) => $this->key((string) $row->outlet_id, (string) $row->business_date))
            : collect();

        $coverage = Schema::hasTable('report_daily_summary_coverage')
            ? DB::table('report_daily_summary_coverage')
                ->whereIn('outlet_id', $ids)
                ->whereBetween('business_date', [$from, $to])
                ->get(['outlet_id', 'business_date', 'synced_at'])
                ->keyBy(fn ($row) => $this->key((string) $row->outlet_id, (string) $row->business_date))
            : collect();

        $queue = Schema::hasTable('report_daily_summary_refresh_queue')
            ? DB::table('report_daily_summary_refresh_queue')
                ->whereIn('outlet_id', $ids)
                ->whereBetween('business_date', [$from, $to])
                ->whereIn('status', ['pending', 'processing'])
                ->get(['outlet_id', 'business_date', 'status', 'touch_count', 'attempt_count'])
                ->keyBy(fn ($row) => $this->key((string) $row->outlet_id, (string) $row->business_date))
            : collect();

        $rows = [];
        $issues = 0;
        $dates = $this->dates($from, $to, $timezone);
        foreach ($outlets as $outlet) {
            $outletId = (string) $outlet['id'];
            foreach ($dates as $date) {
                $key = $this->key($outletId, $date);
                $rawRow = $raw->get($key);
                $indexRow = $index->get($key);
                $dailyRow = $daily->get($key);
                $coverageRow = $coverage->get($key);
                $queueRow = $queue->get($key);

                $rawTrx = (int) ($rawRow['trx_count'] ?? 0);
                $rawGrand = (int) round((float) ($rawRow['grand_sales'] ?? 0));
                $indexTrx = (int) ($indexRow->trx_count ?? 0);
                $indexGrand = (int) round((float) ($indexRow->grand_sales ?? 0));
                $dailyTrx = (int) ($dailyRow->trx_count ?? 0);
                $dailyGrand = (int) round((float) ($dailyRow->grand_sales ?? 0));
                $coverageReady = $coverageRow !== null;
                $queueDirty = $queueRow !== null;

                $ok = $coverageReady
                    && $rawTrx === $indexTrx
                    && $rawGrand === $indexGrand
                    && $indexTrx === $dailyTrx
                    && $indexGrand === $dailyGrand
                    && (! $requireQueueClean || ! $queueDirty);

                if (! $ok) {
                    $issues++;
                }

                $rows[] = [
                    'outlet' => $outlet['code'] !== '' ? $outlet['code'] : $outletId,
                    'date' => $date,
                    'raw_trx' => $rawTrx,
                    'index_trx' => $indexTrx,
                    'daily_trx' => $dailyTrx,
                    'raw_sales' => $rawGrand,
                    'daily_sales' => $dailyGrand,
                    'coverage' => $coverageReady ? 'READY' : 'MISSING',
                    'queue' => $queueDirty ? strtoupper((string) ($queueRow->status ?? 'pending')) : '-',
                    'touch' => (int) ($queueRow->touch_count ?? 0),
                    'attempt' => (int) ($queueRow->attempt_count ?? 0),
                    'status' => $ok ? 'PASS' : 'MISMATCH',
                ];
            }
        }

        return [
            'ok' => $issues === 0,
            'rows' => $rows,
            'issues' => $issues,
            'pending_queue' => $queue->count(),
        ];
    }

    /**
     * @return array{ok:bool,rows:array<int,array<string,mixed>>,issues:int}
     */
    public function auditHourly(array $outlets, string $from, string $to, string $timezone): array
    {
        $ids = array_values(array_filter(array_map(fn ($row) => (string) ($row['id'] ?? ''), $outlets)));
        $daily = Schema::hasTable('report_daily_sales_summaries')
            ? DB::table('report_daily_sales_summaries')
                ->whereIn('outlet_id', $ids)->whereBetween('business_date', [$from, $to])
                ->get(['outlet_id', 'business_date', 'trx_count', 'grand_sales'])
                ->keyBy(fn ($row) => $this->key((string) $row->outlet_id, (string) $row->business_date))
            : collect();

        $hourly = Schema::hasTable('report_hourly_sales_summaries')
            ? DB::table('report_hourly_sales_summaries')
                ->whereIn('outlet_id', $ids)->whereBetween('business_date', [$from, $to])
                ->groupBy('outlet_id', 'business_date')
                ->get([
                    'outlet_id', 'business_date',
                    DB::raw('COALESCE(SUM(trx_count),0) as trx_count'),
                    DB::raw('COALESCE(SUM(gross_amount_sales),0) as grand_sales'),
                ])->keyBy(fn ($row) => $this->key((string) $row->outlet_id, (string) $row->business_date))
            : collect();

        $coverage = Schema::hasTable('report_hourly_summary_coverage')
            ? DB::table('report_hourly_summary_coverage as h')
                ->leftJoin('report_daily_summary_coverage as d', function ($join): void {
                    $join->on('d.outlet_id', '=', 'h.outlet_id')->on('d.business_date', '=', 'h.business_date');
                })
                ->whereIn('h.outlet_id', $ids)->whereBetween('h.business_date', [$from, $to])
                ->get(['h.outlet_id', 'h.business_date', 'h.source_daily_synced_at', 'd.synced_at as daily_synced_at'])
                ->keyBy(fn ($row) => $this->key((string) $row->outlet_id, (string) $row->business_date))
            : collect();

        $rows = [];
        $issues = 0;
        foreach ($outlets as $outlet) {
            foreach ($this->dates($from, $to, $timezone) as $date) {
                $key = $this->key((string) $outlet['id'], $date);
                $d = $daily->get($key);
                $h = $hourly->get($key);
                $c = $coverage->get($key);
                $dailyTrx = (int) ($d->trx_count ?? 0);
                $dailySales = (int) round((float) ($d->grand_sales ?? 0));
                $hourlyTrx = (int) ($h->trx_count ?? 0);
                $hourlySales = (int) round((float) ($h->grand_sales ?? 0));
                $fresh = $c !== null
                    && ! empty($c->source_daily_synced_at)
                    && ! empty($c->daily_synced_at)
                    && (string) $c->source_daily_synced_at >= (string) $c->daily_synced_at;
                $ok = $fresh && $dailyTrx === $hourlyTrx && $dailySales === $hourlySales;
                if (! $ok) $issues++;

                $rows[] = [
                    'outlet' => $outlet['code'] !== '' ? $outlet['code'] : $outlet['id'],
                    'date' => $date,
                    'daily_trx' => $dailyTrx,
                    'hourly_trx' => $hourlyTrx,
                    'daily_sales' => $dailySales,
                    'hourly_sales' => $hourlySales,
                    'coverage' => $fresh ? 'READY' : 'STALE/MISSING',
                    'status' => $ok ? 'PASS' : 'MISMATCH',
                ];
            }
        }

        return ['ok' => $issues === 0, 'rows' => $rows, 'issues' => $issues];
    }

    /**
     * @return array{ok:bool,rows:array<int,array<string,mixed>>,issues:int}
     */
    public function auditMonthly(array $outlets, array $months): array
    {
        $ids = array_values(array_filter(array_map(fn ($row) => (string) ($row['id'] ?? ''), $outlets)));
        $rows = [];
        $issues = 0;

        foreach ($months as $month) {
            $monthStart = ($month instanceof CarbonImmutable ? $month : CarbonImmutable::parse((string) $month))->startOfMonth();
            $from = $monthStart->toDateString();
            $to = $monthStart->endOfMonth()->toDateString();
            $expectedDailyCoverage = $monthStart->daysInMonth;

            $daily = Schema::hasTable('report_daily_sales_summaries')
                ? DB::table('report_daily_sales_summaries')
                    ->whereIn('outlet_id', $ids)->whereBetween('business_date', [$from, $to])
                    ->groupBy('outlet_id')
                    ->get(['outlet_id', DB::raw('COALESCE(SUM(trx_count),0) as trx_count'), DB::raw('COALESCE(SUM(grand_sales),0) as grand_sales')])
                    ->keyBy('outlet_id')
                : collect();

            $monthly = Schema::hasTable('report_monthly_sales_summaries')
                ? DB::table('report_monthly_sales_summaries')
                    ->whereIn('outlet_id', $ids)->where('business_month', $from)
                    ->get(['outlet_id', 'trx_count', 'grand_sales'])
                    ->keyBy('outlet_id')
                : collect();

            $dailyCoverage = Schema::hasTable('report_daily_summary_coverage')
                ? DB::table('report_daily_summary_coverage')
                    ->whereIn('outlet_id', $ids)->whereBetween('business_date', [$from, $to])
                    ->groupBy('outlet_id')->selectRaw('outlet_id, COUNT(*) as rows_count')->get()->keyBy('outlet_id')
                : collect();

            $monthlyCoverage = Schema::hasTable('report_monthly_summary_coverage')
                ? DB::table('report_monthly_summary_coverage')->whereIn('outlet_id', $ids)->where('business_month', $from)
                    ->get(['outlet_id', 'synced_at'])->keyBy('outlet_id')
                : collect();

            foreach ($outlets as $outlet) {
                $id = (string) $outlet['id'];
                $d = $daily->get($id);
                $m = $monthly->get($id);
                $dailyRows = (int) ($dailyCoverage->get($id)->rows_count ?? 0);
                $monthlyReady = $monthlyCoverage->has($id);
                $dailyTrx = (int) ($d->trx_count ?? 0);
                $dailySales = (int) round((float) ($d->grand_sales ?? 0));
                $monthlyTrx = (int) ($m->trx_count ?? 0);
                $monthlySales = (int) round((float) ($m->grand_sales ?? 0));
                $ok = $dailyRows === $expectedDailyCoverage
                    && $monthlyReady
                    && $dailyTrx === $monthlyTrx
                    && $dailySales === $monthlySales;
                if (! $ok) $issues++;

                $rows[] = [
                    'outlet' => $outlet['code'] !== '' ? $outlet['code'] : $id,
                    'month' => $monthStart->format('Y-m'),
                    'daily_cov' => sprintf('%d/%d', $dailyRows, $expectedDailyCoverage),
                    'daily_trx' => $dailyTrx,
                    'monthly_trx' => $monthlyTrx,
                    'daily_sales' => $dailySales,
                    'monthly_sales' => $monthlySales,
                    'coverage' => $monthlyReady ? 'READY' : 'MISSING',
                    'status' => $ok ? 'PASS' : 'MISMATCH',
                ];
            }
        }

        return ['ok' => $issues === 0, 'rows' => $rows, 'issues' => $issues];
    }

    public function clearDailyRefreshQueue(array $outletIds, string $from, string $to): int
    {
        if (! Schema::hasTable('report_daily_summary_refresh_queue')) {
            return 0;
        }

        return DB::table('report_daily_summary_refresh_queue')
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', [$from, $to])
            ->delete();
    }

    /** @return array<int,string> */
    private function dates(string $from, string $to, string $timezone): array
    {
        $dates = [];
        $cursor = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }
        return $dates;
    }

    private function key(string $outletId, string $date): string
    {
        return $outletId.'#'.$date;
    }

    /** @return array{id:string,code:string,name:string,timezone:string} */
    private function outletRow(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'code' => (string) ($row->code ?? ''),
            'name' => (string) ($row->name ?? ''),
            'timezone' => (string) ($row->timezone ?? ''),
        ];
    }
}
