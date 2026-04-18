<?php

namespace App\Services;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportSaleBusinessDateIndexService
{
    public function ensureCoverage(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $fallbackTimezone = null): void
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return;
        }

        $timezoneMap = $this->resolveTimezoneMap($normalizedOutletIds, $fallbackTimezone);
        $groupedOutletIds = [];
        foreach ($normalizedOutletIds as $outletId) {
            $timezone = $timezoneMap[$outletId] ?? TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
            $groupedOutletIds[$timezone] ??= [];
            $groupedOutletIds[$timezone][] = $outletId;
        }

        foreach ($groupedOutletIds as $timezone => $tzOutletIds) {
            $window = TransactionDate::businessDateWindow($dateFrom, $dateTo, $timezone);
            $fromDate = $window['requested_from']->toDateString();
            $toDate = $window['requested_to']->toDateString();
            $source = $this->reportableSalesSourceQuery($tzOutletIds, $fromDate, $toDate, $timezone);

            DB::transaction(function () use ($tzOutletIds, $fromDate, $toDate, $source) {
                DB::table('report_sale_business_dates')
                    ->whereIn('outlet_id', $tzOutletIds)
                    ->whereBetween('business_date', [$fromDate, $toDate])
                    ->delete();

                DB::table('report_sale_business_dates')->insertUsing(
                    ['sale_id', 'outlet_id', 'business_timezone', 'business_date', 'marking', 'created_at', 'updated_at'],
                    $source
                );
            }, 1);
        }
    }

    public function saleIdsSubquery(array $outletIds, ?string $dateFrom, ?string $dateTo, bool $markedOnly = false, ?string $fallbackTimezone = null): Builder
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return DB::table('report_sale_business_dates as rsbd')->selectRaw('rsbd.sale_id as id')->whereRaw('1 = 0');
        }

        [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $fallbackTimezone);

        $query = DB::table('report_sale_business_dates as rsbd')
            ->selectRaw('rsbd.sale_id as id')
            ->whereIn('rsbd.outlet_id', $normalizedOutletIds)
            ->whereBetween('rsbd.business_date', [$fromDate, $toDate]);

        if ($markedOnly) {
            $query->whereRaw('COALESCE(CAST(rsbd.marking AS SIGNED), 0) = 1');
        }

        return $query->distinct();
    }

    private function reportableSalesSourceQuery(array $outletIds, string $dateFrom, string $dateTo, string $timezone): Builder
    {
        $resolvedLocalExpr = TransactionDate::resolvedSaleLocalSqlExpression('s.created_at', 's.sale_number', $timezone);
        $startHour = TransactionDate::businessDayStartHour($timezone);
        $businessDateExpr = $startHour > 0
            ? "DATE(DATE_SUB({$resolvedLocalExpr}, INTERVAL {$startHour} HOUR))"
            : "DATE({$resolvedLocalExpr})";
        $timestamp = now()->format('Y-m-d H:i:s');

        $query = DB::table('sales as s')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->whereIn('s.outlet_id', array_values(array_unique(array_filter(array_map('strval', $outletIds)))));

        TransactionDate::applyExactBusinessDateScope(
            $query,
            's.created_at',
            $dateFrom,
            $dateTo,
            $timezone,
            's.sale_number'
        );

        return $query
            ->selectRaw('CAST(s.id AS CHAR(64)) as sale_id')
            ->selectRaw('CAST(s.outlet_id AS CHAR(64)) as outlet_id')
            ->selectRaw('? as business_timezone', [$timezone])
            ->selectRaw("{$businessDateExpr} as business_date")
            ->selectRaw('COALESCE(CAST(s.marking AS SIGNED), 0) as marking')
            ->selectRaw('? as created_at', [$timestamp])
            ->selectRaw('? as updated_at', [$timestamp])
            ->distinct();
    }

    private function resolveTimezoneMap(array $outletIds, ?string $fallbackTimezone = null): array
    {
        $fallback = TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
        $rows = DB::table('outlets')
            ->whereIn('id', $outletIds)
            ->get(['id', 'timezone']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) ($row->id ?? '')] = TransactionDate::normalizeTimezone((string) ($row->timezone ?? ''), $fallback);
        }

        foreach ($outletIds as $outletId) {
            $map[(string) $outletId] = $map[(string) $outletId] ?? $fallback;
        }

        return $map;
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
}
